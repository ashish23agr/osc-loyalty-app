<?php

namespace App\Domain\Loyalty;

use App\Domain\Orders\OrderSnapshot;
use App\Domain\Rules\RulesVersionRepository;
use App\Models\LedgerEntry;
use App\Models\LoyaltyAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Posting the points an order earned (M3, D2, D3).
 *
 * Cumulative, like the refund arithmetic and for the same reason. The service
 * works out what the order SHOULD have earned in total and posts the difference
 * against what it already has, so `orders/paid` and `orders/updated` are the
 * same code path: the first delivery finds nothing posted and posts everything,
 * an edit that adds a line posts the increase, and a redelivery finds the target
 * already met and posts nothing at all.
 *
 * That also makes it safe to run from the replay command over orders that were
 * processed correctly the first time.
 *
 * D2: the points are pending, `matures_at` is the order date plus the pending
 * period, and `expires_at` runs from maturity so a member gets the full expiry
 * period of usable life rather than losing the pending month.
 */
final class OrderEarningService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly EarningCalculator $earning,
        private readonly RulesVersionRepository $rules,
        private readonly BalanceCalculator $balances,
    ) {}

    /**
     * @return array{
     *     outcome:string, points_posted:int, target_points:int, already_posted:int,
     *     qualifying_pence:int, excluded_pence:int, account_id:?int, entry_id:?int
     * }
     */
    public function apply(string $shopDomain, OrderSnapshot $order): array
    {
        $account = $this->accountFor($shopDomain, $order);

        $occurredAt = $order->processedAt === null ? now() : Carbon::parse($order->processedAt);
        // The version in force when the order happened, not when the webhook
        // was processed, so a late delivery for an old order is evaluated
        // against the rules that were actually in effect at the time.
        $ruleSet = $this->rules->asAt($shopDomain, $occurredAt);

        $calculated = $this->earning->forOrder($order->lines, $ruleSet);

        $result = [
            'outcome' => 'no_member',
            'points_posted' => 0,
            'target_points' => $calculated['points'],
            'already_posted' => 0,
            'qualifying_pence' => $calculated['qualifying_pence'],
            'excluded_pence' => $calculated['excluded_pence'],
            'account_id' => null,
            'entry_id' => null,
        ];

        // An order from someone who is not a member is not a failure. Enrolment
        // is M1's business, and this simply has nobody to credit.
        if ($account === null) {
            return $result;
        }

        $result['account_id'] = (int) $account->id;

        $alreadyPosted = $this->alreadyPosted($account, $order->shopifyOrderId);
        $result['already_posted'] = $alreadyPosted;

        $difference = $calculated['points'] - $alreadyPosted;

        if ($difference === 0) {
            $result['outcome'] = $alreadyPosted === 0 ? 'nothing_to_earn' : 'already_posted';

            return $result;
        }

        // Keyed on the cumulative target rather than on a counter, so the same
        // target is always the same key: a redelivery collides and returns the
        // original entry, while a genuine edit produces a new target and a new
        // key without anyone tracking revisions.
        $key = 'earn:order:'.$order->shopifyOrderId.':t'.$calculated['points'];

        $entry = DB::transaction(function () use (
            $account, $order, $occurredAt, $ruleSet, $calculated, $difference, $key
        ): LedgerEntry {
            if ($difference > 0) {
                $maturesAt = $occurredAt->copy()->addDays($ruleSet->pendingPeriodDays());

                return $this->ledger->post($account, LedgerPosting::earn(
                    points: $difference,
                    idempotencyKey: $key,
                    occurredAt: $occurredAt,
                    maturesAt: $maturesAt,
                    // D2: the expiry clock starts at availability.
                    expiresAt: $maturesAt->copy()->addDays($ruleSet->pointsExpiryDays()),
                    channel: $order->shopifyLocationId === null ? 'online' : 'pos',
                    shopifyOrderId: $order->shopifyOrderId,
                    orderName: $order->orderName,
                    qualifyingValuePence: $calculated['qualifying_pence'],
                    shopifyLocationId: $order->shopifyLocationId,
                ));
            }

            // An edit that removed value. The earn cannot be made smaller, so
            // the reduction is a reversal against it - a new entry, like
            // everything else, and the history shows both.
            $earn = $this->earns($account, $order->shopifyOrderId)->first();

            return $this->ledger->post($account, LedgerPosting::earnReversal(
                pendingPoints: $this->pendingPortion($earn, abs($difference)),
                availablePoints: abs($difference) - $this->pendingPortion($earn, abs($difference)),
                earnEntryId: (int) $earn->id,
                idempotencyKey: $key,
                occurredAt: $occurredAt,
                shopifyOrderId: $order->shopifyOrderId,
                orderName: $order->orderName,
                reason: 'Order edited: qualifying value fell to '.$calculated['qualifying_pence'].'p',
            ));
        });

        $result['outcome'] = $difference > 0 ? 'earned' : 'reduced';
        $result['points_posted'] = $difference;
        $result['entry_id'] = (int) $entry->id;

        return $result;
    }

    /** Total points this order has been credited with so far, net of reversals. */
    private function alreadyPosted(LoyaltyAccount $account, int $shopifyOrderId): int
    {
        return (int) LedgerEntry::query()
            ->where('loyalty_account_id', $account->id)
            ->where('shopify_order_id', $shopifyOrderId)
            ->whereIn('entry_type', ['earn', 'earn_reversal'])
            ->sum('pending_delta')
            + (int) LedgerEntry::query()
                ->where('loyalty_account_id', $account->id)
                ->where('shopify_order_id', $shopifyOrderId)
                ->where('entry_type', 'earn_reversal')
                ->sum('available_delta');
    }

    private function earns(LoyaltyAccount $account, int $shopifyOrderId)
    {
        return LedgerEntry::query()
            ->where('loyalty_account_id', $account->id)
            ->where('entry_type', 'earn')
            ->where('shopify_order_id', $shopifyOrderId)
            ->orderBy('id')
            ->get();
    }

    /** How much of a reduction can come out of pending before touching available. */
    private function pendingPortion(LedgerEntry $earn, int $points): int
    {
        $matured = (int) LedgerEntry::query()
            ->where('entry_type', 'maturity')
            ->where('parent_entry_id', $earn->id)
            ->sum('available_delta');

        $reversedPending = -(int) LedgerEntry::query()
            ->where('entry_type', 'earn_reversal')
            ->where('parent_entry_id', $earn->id)
            ->sum('pending_delta');

        return min($points, max(0, (int) $earn->pending_delta - $matured - $reversedPending));
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

        // D10: a merged account's ledger lives on the survivor, so credit the
        // survivor rather than reviving a record nobody should be reading.
        while ($account !== null && $account->isMerged()) {
            $account = LoyaltyAccount::query()->find($account->merged_into_account_id);
        }

        return $account;
    }
}
