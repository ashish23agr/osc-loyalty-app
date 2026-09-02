<?php

namespace App\Domain\Loyalty;

use App\Domain\Rules\RuleSet;
use App\Domain\Rules\RulesVersionRepository;
use App\Models\LedgerEntry;
use App\Models\LoyaltyAccount;
use App\Support\Money\Pence;

/**
 * Reads balances from the ledger, and derives the voucher value from them.
 *
 * The ledger is the only source of truth. Balances are sums over immutable rows
 * rather than a running total that could fall out of step, which is what makes a
 * rebuild reproduce a balance exactly rather than approximately.
 */
final class BalanceCalculator
{
    public function __construct(private readonly RulesVersionRepository $rules) {}

    /**
     * The voucher balance, derived on read and never stored (D1).
     *
     * A pure function of points and the rule version, so the shared fixtures can
     * exercise it directly. Negative available points, which a clawback can
     * produce (Q3), floor at zero: the ledger stays truthful while the customer
     * never sees a negative reward.
     */
    public static function derive(int $availablePoints, RuleSet $rules): VoucherBalance
    {
        $threshold = $rules->voucherThresholdPoints();
        $value = $rules->voucherValuePence();

        if ($availablePoints < $threshold) {
            return new VoucherBalance(
                increments: 0,
                balance: Pence::zero(),
                remainderPoints: $availablePoints,
                // What the member must add to reach the first increment. For a
                // negative balance that includes clearing the debt.
                pointsToNextIncrement: $threshold - $availablePoints,
            );
        }

        $increments = intdiv($availablePoints, $threshold);
        $remainder = $availablePoints % $threshold;

        return new VoucherBalance(
            increments: $increments,
            balance: Pence::of($increments * $value),
            remainderPoints: $remainder,
            pointsToNextIncrement: $threshold - $remainder,
        );
    }

    public function pending(int $accountId): int
    {
        return (int) LedgerEntry::query()
            ->where('loyalty_account_id', $accountId)
            ->sum('pending_delta');
    }

    public function available(int $accountId): int
    {
        return (int) LedgerEntry::query()
            ->where('loyalty_account_id', $accountId)
            ->sum('available_delta');
    }

    /**
     * Total points ever credited to the available bucket.
     *
     * Counted from positive available movements only, so a maturity is counted
     * once and the earn that produced it is not counted again.
     */
    public function lifetime(int $accountId): int
    {
        return (int) LedgerEntry::query()
            ->where('loyalty_account_id', $accountId)
            ->where('available_delta', '>', 0)
            ->sum('available_delta');
    }

    public function for(LoyaltyAccount $account): Balances
    {
        $available = $this->available($account->id);

        return new Balances(
            pending: $this->pending($account->id),
            available: $available,
            lifetime: $this->lifetime($account->id),
            voucher: self::derive($available, $this->rules->current($account->shop_domain)),
        );
    }

    /**
     * Recompute the cached columns from the ledger and save them.
     *
     * Called after every posting. A full re-sum rather than an increment,
     * because an increment can drift and this cannot: after this returns, the
     * cache equals the ledger by construction.
     */
    public function refreshCache(LoyaltyAccount $account): Balances
    {
        $balances = $this->for($account);

        $account->forceFill($balances->toCacheAttributes() + [
            'caches_rebuilt_at' => now(),
        ])->save();

        return $balances;
    }

    /**
     * Does the cached position still match the ledger?
     *
     * Used by loyalty:verify-ledger, which exits non-zero on any drift so cron
     * can alarm on it rather than waiting for a member to notice.
     *
     * @return array{drifted:bool, cached:array, ledger:array}
     */
    public function verify(LoyaltyAccount $account): array
    {
        $ledger = $this->for($account)->toCacheAttributes();

        $cached = [
            'points_pending' => (int) $account->points_pending,
            'points_available' => (int) $account->points_available,
            'points_lifetime' => (int) $account->points_lifetime,
            'voucher_balance_pence' => (int) $account->voucher_balance_pence,
        ];

        return [
            'drifted' => $cached !== $ledger,
            'cached' => $cached,
            'ledger' => $ledger,
        ];
    }
}
