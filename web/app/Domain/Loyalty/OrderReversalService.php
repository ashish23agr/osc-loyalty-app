<?php

namespace App\Domain\Loyalty;

use App\Domain\Orders\OrderSnapshot;
use App\Domain\Rules\RulesVersionRepository;
use App\Models\LoyaltyAccount;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Carbon;

/**
 * Turning a refund or a cancellation into the figures D9 needs (M8).
 *
 * RefundReversalService owns the arithmetic and the ledger. This owns the step
 * before it: working out, from a re-read order, what the order's eligible value
 * was and how much of THAT has been refunded.
 *
 * The distinction matters. The denominator is the qualifying value the earning
 * was computed from, and the numerator counts only refunds against lines that
 * actually qualified. Refunding a gift card on an order that also contained a
 * shirt must reverse nothing, and refunding shipping must reverse nothing,
 * because neither earned anything in the first place.
 *
 * A cancellation is a full refund of the eligible value however the refund
 * records read, which is why it takes a separate path rather than trusting the
 * refund line items to add up to the whole order.
 */
final class OrderReversalService
{
    public function __construct(
        private readonly RefundReversalService $reversals,
        private readonly EarningCalculator $earning,
        private readonly RulesVersionRepository $rules,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{
     *     outcome:string, reversed:int, restored:int,
     *     order_eligible_pence:int, refunded_eligible_pence:int, account_id:?int
     * }
     */
    public function apply(
        string $shopDomain,
        OrderSnapshot $order,
        ?int $shopifyRefundId = null,
        ?\DateTimeInterface $occurredAt = null,
    ): array {
        $occurredAt = $occurredAt === null ? now() : Carbon::parse($occurredAt);

        $account = $this->accountFor($shopDomain, $order);

        $ruleSet = $this->rules->asAt(
            $shopDomain,
            $order->processedAt === null ? $occurredAt : Carbon::parse($order->processedAt),
        );

        $calculated = $this->earning->forOrder($order->lines, $ruleSet);

        $qualifyingLineIds = array_values(array_map(
            fn (array $line): string => (string) $line['id'],
            array_filter($calculated['lines'], fn (array $line): bool => $line['qualifies']),
        ));

        $orderEligible = $calculated['qualifying_pence'];

        $refundedEligible = $order->isFullyCancelled()
            // A cancellation takes the whole eligible value, whatever the refund
            // records happen to contain. An order can be cancelled without a
            // refund line item existing at all.
            ? $orderEligible
            : $order->refundedQualifyingPence($qualifyingLineIds);

        $result = [
            'outcome' => 'no_member',
            'reversed' => 0,
            'restored' => 0,
            'order_eligible_pence' => $orderEligible,
            'refunded_eligible_pence' => $refundedEligible,
            'account_id' => $account?->id === null ? null : (int) $account->id,
        ];

        if ($account === null) {
            return $result;
        }

        // Nothing qualified, so nothing was earned and nothing can be reversed.
        // Refunding an order made entirely of gift cards lands here, correctly.
        if ($orderEligible <= 0) {
            $result['outcome'] = 'nothing_eligible';

            return $result;
        }

        if ($refundedEligible <= 0) {
            $result['outcome'] = 'nothing_refunded_that_earned';

            return $result;
        }

        $applied = $this->reversals->apply(
            account: $account,
            shopifyOrderId: $order->shopifyOrderId,
            orderEligiblePence: $orderEligible,
            // D9c wants the cumulative total, and the order source reads every
            // refund the order has ever had for exactly this reason.
            cumulativeRefundedPence: $refundedEligible,
            shopifyRefundId: $shopifyRefundId,
            occurredAt: $occurredAt,
            orderName: $order->orderName,
        );

        $result['outcome'] = ($applied['reversed'] + $applied['restored']) > 0 ? 'reversed' : 'already_applied';
        $result['reversed'] = $applied['reversed'];
        $result['restored'] = $applied['restored'];

        // C12: one audit entry per order, with the order and refund reference.
        // Not one per ledger entry - the ledger already holds those, and this
        // answers "what did the automated side do to this order".
        $this->audit->log(
            action: AuditAction::ORDER_REVERSED,
            subjectType: 'LoyaltyAccount',
            subjectId: (int) $account->id,
            after: [
                'shopify_order_id' => $order->shopifyOrderId,
                'order_name' => $order->orderName,
                'shopify_refund_id' => $shopifyRefundId,
                'cancelled' => $order->isFullyCancelled(),
                'order_eligible_pence' => $orderEligible,
                'refunded_eligible_pence' => $refundedEligible,
                'points_reversed' => $applied['reversed'],
                'points_restored' => $applied['restored'],
                'cumulative_reversed' => $applied['cumulative_reversed'],
                'cumulative_restored' => $applied['cumulative_restored'],
                'full_refund' => $applied['full_refund'],
            ],
            shopDomain: $shopDomain,
        );

        return $result;
    }

    private function accountFor(string $shopDomain, OrderSnapshot $order): ?LoyaltyAccount
    {
        if ($order->shopifyCustomerId === null) {
            return null;
        }

        $account = LoyaltyAccount::query()
            ->where('shop_domain', $shopDomain)
            ->where('shopify_customer_id', $order->shopifyCustomerId)
            ->first();

        while ($account !== null && $account->isMerged()) {
            $account = LoyaltyAccount::query()->find($account->merged_into_account_id);
        }

        return $account;
    }
}
