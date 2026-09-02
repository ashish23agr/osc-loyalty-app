<?php

namespace App\Domain\Loyalty;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What is due to expire, and when.
 *
 * The remaining life of a lot is the lot total minus its allocations, because
 * the ledger is append-only and cannot carry a mutable "points left" column.
 * That arithmetic already exists in LotAllocator for the allocation path; this
 * is the same rule asked as a question about a date window instead of about a
 * consumption, and it answers both the member profile ("expiring soon") and the
 * Loyalty screen tile ("expiring in 30 days") from one place.
 *
 * An earn contributes only what has actually matured. Points still pending
 * cannot be redeemed, so counting them as "about to expire" would alarm a
 * member about points that are not yet theirs to lose.
 */
final class ExpiryOutlook
{
    /** @return array{points:int, lots:int, next_expires_at:?string} */
    public function forAccount(int $accountId, DateTimeInterface $from, DateTimeInterface $to): array
    {
        return $this->summarise(
            $this->lots($from, $to)->where('lot.loyalty_account_id', $accountId)
        );
    }

    /** @return array{points:int, lots:int, next_expires_at:?string} */
    public function forShop(string $shopDomain, DateTimeInterface $from, DateTimeInterface $to): array
    {
        return $this->summarise(
            $this->lots($from, $to)->where('lot.shop_domain', $shopDomain)
        );
    }

    /**
     * Lots whose expiry date has passed and that still hold points.
     *
     * The same arithmetic the outlook uses, asked as "what is overdue" instead
     * of "what is coming". The sweep needs the per-lot figure rather than a
     * total, because expiry posts one entry per lot pointing at the lot it
     * retired.
     *
     * A lot can appear here more than once over its life: points released back
     * into it by a refund (D9a) after its expiry date are available again and
     * expire on the next sweep, which is why the sweep dates its idempotency
     * key rather than keying on the lot alone.
     *
     * @return list<array{id:int, account_id:int, points:int, expires_at:string}>
     */
    public function dueLots(string $shopDomain, DateTimeInterface $asOf, int $limit = 500): array
    {
        $query = $this->lots(new DateTimeImmutable('1970-01-01'), $asOf)
            ->where('lot.shop_domain', $shopDomain)
            ->addSelect('lot.loyalty_account_id as account_id')
            ->groupBy('lot.loyalty_account_id');

        $due = [];

        foreach ($query->get() as $row) {
            $total = $row->entry_type === 'earn' ? (int) $row->matured : (int) $row->available_delta;
            $remaining = max(0, $total - (int) $row->already_allocated);

            if ($remaining === 0) {
                continue;
            }

            $due[] = [
                'id' => (int) $row->id,
                'account_id' => (int) $row->account_id,
                'points' => $remaining,
                'expires_at' => (string) $row->expires_at,
            ];

            if (count($due) >= $limit) {
                break;
            }
        }

        return $due;
    }

    /**
     * Lots carrying an expiry date inside the window, with what each has left.
     *
     * Grouped rather than aggregated in SQL because the "points" figure differs
     * by entry type, which no portable single expression covers.
     */
    private function lots(DateTimeInterface $from, DateTimeInterface $to): Builder
    {
        $matured = DB::table('loyalty_ledger as maturity')
            ->selectRaw('maturity.parent_entry_id as lot_id, sum(maturity.available_delta) as matured')
            ->where('maturity.entry_type', 'maturity')
            ->whereNotNull('maturity.parent_entry_id')
            ->groupBy('maturity.parent_entry_id');

        return DB::table('loyalty_ledger as lot')
            ->leftJoinSub($matured, 'm', 'm.lot_id', '=', 'lot.id')
            ->leftJoin('loyalty_lot_allocations as alloc', 'alloc.lot_entry_id', '=', 'lot.id')
            ->whereNotNull('lot.expires_at')
            ->where('lot.expires_at', '>=', $from)
            ->where('lot.expires_at', '<', $to)
            ->where(function ($query): void {
                $query->whereIn('lot.entry_type', ['earn', 'opening_balance'])
                    ->orWhere(function ($inner): void {
                        $inner->where('lot.entry_type', 'adjustment')
                            ->where('lot.available_delta', '>', 0);
                    });
            })
            ->groupBy('lot.id', 'lot.entry_type', 'lot.available_delta', 'lot.expires_at', 'm.matured')
            ->orderBy('lot.expires_at')
            ->selectRaw(
                'lot.id as id,
                 lot.entry_type as entry_type,
                 lot.available_delta as available_delta,
                 lot.expires_at as expires_at,
                 coalesce(m.matured, 0) as matured,
                 coalesce(sum(alloc.points), 0) as already_allocated'
            );
    }

    /** @return array{points:int, lots:int, next_expires_at:?string} */
    private function summarise(Builder $query): array
    {
        $points = 0;
        $lots = 0;
        $next = null;

        foreach ($query->get() as $row) {
            $total = $row->entry_type === 'earn' ? (int) $row->matured : (int) $row->available_delta;
            $remaining = max(0, $total - (int) $row->already_allocated);

            if ($remaining === 0) {
                continue;
            }

            $points += $remaining;
            $lots++;
            $next ??= (string) $row->expires_at;
        }

        return ['points' => $points, 'lots' => $lots, 'next_expires_at' => $next];
    }
}
