<?php

namespace App\Domain\Loyalty;

use App\Support\Money\Pence;

/**
 * The customer-facing reward value, derived from points on read.
 *
 * Per D1 this is never stored as an issued object: a member reward value rises
 * and falls automatically with their eligible points, so nothing is issued and
 * nothing has to be reconciled. The only genuinely issued objects are the
 * birthday reward and any goodwill voucher, which live in loyalty_rewards.
 */
final readonly class VoucherBalance
{
    public function __construct(
        public int $increments,
        public Pence $balance,
        public int $remainderPoints,
        public int $pointsToNextIncrement,
    ) {}

    public function pence(): int
    {
        return $this->balance->toInt();
    }

    public function toArray(): array
    {
        return [
            'increments' => $this->increments,
            'voucher_balance_pence' => $this->balance->toInt(),
            'remainder_points' => $this->remainderPoints,
            'points_to_next_increment' => $this->pointsToNextIncrement,
        ];
    }
}
