<?php

namespace App\Domain\Loyalty;

/**
 * A snapshot of one member point position, read from the ledger.
 *
 * The cached columns on loyalty_accounts hold the same numbers for fast reads,
 * but they are disposable: loyalty:rebuild-balances reconstructs them from the
 * ledger, and if a cache and the ledger ever disagree the ledger wins.
 */
final readonly class Balances
{
    public function __construct(
        public int $pending,
        public int $available,
        public int $lifetime,
        public VoucherBalance $voucher,
    ) {}

    public function toArray(): array
    {
        return [
            'points_pending' => $this->pending,
            'points_available' => $this->available,
            'points_lifetime' => $this->lifetime,
            'voucher_balance_pence' => $this->voucher->pence(),
        ];
    }

    /** The shape the cached columns take. */
    public function toCacheAttributes(): array
    {
        return [
            'points_pending' => $this->pending,
            'points_available' => $this->available,
            'points_lifetime' => $this->lifetime,
            'voucher_balance_pence' => $this->voucher->pence(),
        ];
    }
}
