<?php

namespace App\Domain\Loyalty;

use App\Models\LedgerEntry;
use App\Models\LoyaltyAccount;
use App\Models\Redemption;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reversing earned points and restoring redeemed ones after a refund (D9, M8).
 *
 * Everything here is a NEW ledger entry. Nothing is edited, nothing is removed,
 * and a member's history reads as a sequence of things that happened rather
 * than a total someone adjusted.
 *
 * The one thing a caller must get right: $cumulativeRefundedPence is the
 * eligible value refunded against this order IN TOTAL, not the size of the
 * refund that just arrived. D9c's arithmetic is a cumulative floor, and handing
 * it a single refund's value would reintroduce exactly the per-refund rounding
 * it exists to avoid. Per M8 the webhook payload is a trigger, not the
 * arithmetic input: the Sprint 2 handler re-reads the order and sums its
 * refunds, and passes that total here.
 *
 * That also makes redelivery free. A refund delivered twice carries the same
 * cumulative total, the arithmetic finds it has already been posted, and the
 * increment is zero - before the idempotency key on the posting is even
 * consulted.
 */
final class RefundReversalService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly BalanceCalculator $balances,
    ) {}

    /**
     * @return array{
     *     reversed:int, restored:int,
     *     cumulative_reversed:int, cumulative_restored:int,
     *     entries:list<int>, full_refund:bool, balances:Balances
     * }
     */
    public function apply(
        LoyaltyAccount $account,
        int $shopifyOrderId,
        int $orderEligiblePence,
        int $cumulativeRefundedPence,
        ?int $shopifyRefundId = null,
        ?DateTimeInterface $occurredAt = null,
        ?string $orderName = null,
    ): array {
        $occurredAt = $occurredAt === null ? now() : Carbon::parse($occurredAt);

        $earns = $this->earnsFor($account, $shopifyOrderId);
        $redemption = $this->redemptionFor($account, $shopifyOrderId);

        $pointsEarned = array_sum(array_map(fn (LedgerEntry $e): int => (int) $e->pending_delta, $earns));
        $pointsRedeemed = (int) ($redemption?->points_consumed ?? 0);

        $alreadyReversed = $this->alreadyReversed($earns);
        $alreadyRestored = (int) ($redemption?->points_restored ?? 0);

        $sums = RefundArithmetic::forRefund(
            pointsEarned: $pointsEarned,
            pointsRedeemed: $pointsRedeemed,
            cumulativeRefundedPence: $cumulativeRefundedPence,
            orderEligiblePence: $orderEligiblePence,
            alreadyReversed: $alreadyReversed,
            alreadyRestored: $alreadyRestored,
        );

        $entries = [];

        DB::transaction(function () use (
            $account, $earns, $redemption, $sums, $occurredAt,
            $shopifyOrderId, $shopifyRefundId, $orderName,
            $cumulativeRefundedPence, $orderEligiblePence, &$entries
        ): void {
            foreach ($this->shareAcrossEarns($earns, $sums['reverse']) as $earnId => $share) {
                $entries[] = $this->ledger->post($account, LedgerPosting::earnReversal(
                    pendingPoints: $share['pending'],
                    availablePoints: $share['available'],
                    earnEntryId: $earnId,
                    idempotencyKey: 'reverse:'.$this->refundKey($shopifyOrderId, $shopifyRefundId).':'.$earnId,
                    occurredAt: $occurredAt,
                    shopifyRefundId: $shopifyRefundId,
                    shopifyOrderId: $shopifyOrderId,
                    orderName: $orderName,
                    reason: 'Refund '.$sums['proportion'].' of eligible value',
                ))->id;
            }

            if ($redemption !== null && $sums['restore'] > 0) {
                $redemptionEntry = $this->redemptionEntryFor($redemption);

                if ($redemptionEntry !== null) {
                    $entries[] = $this->ledger->post($account, LedgerPosting::redemptionRestore(
                        points: $sums['restore'],
                        redemptionId: (int) $redemption->id,
                        redemptionEntryId: (int) $redemptionEntry->id,
                        idempotencyKey: 'restore:'.$this->refundKey($shopifyOrderId, $shopifyRefundId),
                        occurredAt: $occurredAt,
                        shopifyRefundId: $shopifyRefundId,
                        shopifyOrderId: $shopifyOrderId,
                        orderName: $orderName,
                        reason: 'Refund '.$sums['proportion'].' of eligible value',
                    ))->id;
                }
            }

            if ($redemption !== null) {
                $this->trackOnRedemption(
                    $redemption,
                    $sums['cumulative_restored'],
                    $cumulativeRefundedPence,
                    $orderEligiblePence,
                    $occurredAt,
                );
            }
        });

        return [
            'reversed' => $sums['reverse'],
            'restored' => $sums['restore'],
            'cumulative_reversed' => $sums['cumulative_reversed'],
            'cumulative_restored' => $sums['cumulative_restored'],
            'entries' => $entries,
            'full_refund' => RefundArithmetic::isFullRefund($cumulativeRefundedPence, $orderEligiblePence),
            'balances' => $this->balances->for($account->refresh()),
        ];
    }

    /**
     * D9d. A partial refund leaves the state alone and records what has gone
     * back; only a full reversal flips it.
     *
     * Flipping to 'reversed' on the first partial would throw away the handle
     * the next refund needs - the redemption would read as finished while it
     * still had value to give back, and a second return against the same order
     * would find nothing to unwind.
     */
    private function trackOnRedemption(
        Redemption $redemption,
        int $cumulativeRestored,
        int $cumulativeRefundedPence,
        int $orderEligiblePence,
        DateTimeInterface $occurredAt,
    ): void {
        $full = RefundArithmetic::isFullRefund($cumulativeRefundedPence, $orderEligiblePence);

        $reversedValue = RefundArithmetic::cumulative(
            (int) $redemption->amount_pence,
            $cumulativeRefundedPence,
            $orderEligiblePence,
        );

        $redemption->forceFill([
            'points_restored' => $cumulativeRestored,
            'amount_reversed_pence' => $reversedValue,
            'state' => $full ? 'reversed' : $redemption->state,
            'reversed_at' => $full ? $occurredAt : $redemption->reversed_at,
        ])->save();
    }

    /**
     * Spread a reversal across the order's earns, oldest first, pending before
     * available within each.
     *
     * Pending first because points that never matured were never the member's
     * to spend, so taking those back costs them nothing. What is left comes out
     * of available and may drive the balance negative - Q3 permits it, the
     * ledger stays true, and the customer's voucher value floors at zero.
     *
     * @param  list<LedgerEntry>  $earns
     * @return array<int, array{pending:int, available:int}>
     */
    private function shareAcrossEarns(array $earns, int $reverse): array
    {
        $shares = [];
        $remaining = $reverse;

        foreach ($earns as $earn) {
            if ($remaining <= 0) {
                break;
            }

            $reversible = (int) $earn->pending_delta - $this->alreadyReversed([$earn]);
            $take = min($reversible, $remaining);

            if ($take <= 0) {
                continue;
            }

            $stillPending = $this->remainingPending($earn);
            $pending = min($take, max(0, $stillPending));

            $shares[(int) $earn->id] = [
                'pending' => $pending,
                'available' => $take - $pending,
            ];

            $remaining -= $take;
        }

        return $shares;
    }

    /** What of this earn has neither matured nor already been reversed. */
    private function remainingPending(LedgerEntry $earn): int
    {
        $matured = (int) LedgerEntry::query()
            ->where('entry_type', 'maturity')
            ->where('parent_entry_id', $earn->id)
            ->sum('available_delta');

        $reversedPending = -(int) LedgerEntry::query()
            ->where('entry_type', 'earn_reversal')
            ->where('parent_entry_id', $earn->id)
            ->sum('pending_delta');

        return (int) $earn->pending_delta - $matured - $reversedPending;
    }

    /**
     * Points already taken back from these earns, in either bucket.
     *
     * @param  list<LedgerEntry>  $earns
     */
    private function alreadyReversed(array $earns): int
    {
        if ($earns === []) {
            return 0;
        }

        return -(int) LedgerEntry::query()
            ->where('entry_type', 'earn_reversal')
            ->whereIn('parent_entry_id', array_map(fn (LedgerEntry $e): int => (int) $e->id, $earns))
            ->sum(DB::raw('pending_delta + available_delta'));
    }

    /** @return list<LedgerEntry> */
    private function earnsFor(LoyaltyAccount $account, int $shopifyOrderId): array
    {
        return LedgerEntry::query()
            ->where('loyalty_account_id', $account->id)
            ->where('entry_type', 'earn')
            ->where('shopify_order_id', $shopifyOrderId)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->all();
    }

    private function redemptionFor(LoyaltyAccount $account, int $shopifyOrderId): ?Redemption
    {
        return Redemption::query()
            ->where('loyalty_account_id', $account->id)
            ->where('shopify_order_id', $shopifyOrderId)
            ->whereIn('state', ['confirmed', 'reversed'])
            ->orderBy('id')
            ->first();
    }

    private function redemptionEntryFor(Redemption $redemption): ?LedgerEntry
    {
        return LedgerEntry::query()
            ->where('entry_type', 'redemption')
            ->where('redemption_id', $redemption->id)
            ->orderBy('id')
            ->first();
    }

    /**
     * A refund id when Shopify gave us one, the order otherwise.
     *
     * An order cancellation has no refund id but is still a reversal, and it
     * still has to be idempotent on redelivery.
     */
    private function refundKey(int $shopifyOrderId, ?int $shopifyRefundId): string
    {
        return $shopifyRefundId === null
            ? 'order:'.$shopifyOrderId
            : 'refund:'.$shopifyRefundId;
    }
}
