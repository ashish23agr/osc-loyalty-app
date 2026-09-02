<?php

namespace App\Domain\Loyalty;

use App\Models\LedgerEntry;
use App\Models\LotAllocation;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Decides which earnings a consumption draws from, oldest first.
 *
 * Expiry has to know which points are oldest and a redemption has to consume
 * those first, or expiry becomes guesswork. Because the ledger is append-only
 * the remaining life of a lot cannot be a mutable column on the earn row, so it
 * is the lot total minus its allocations.
 *
 * The algorithm is a pure function (plan) so the shared fixtures can exercise it
 * without a database, and allocate is the thin part that writes rows.
 */
final class LotAllocator
{
    /**
     * Plan a FIFO draw across lots.
     *
     * Reports a shortfall rather than inventing points: if a clawback exceeds
     * what the lots hold, the caller still posts the full reversal (the ledger
     * stays truthful and the balance may go negative, per Q3) but only the
     * points that existed are attributed to a lot.
     *
     * @param  list<array{points:int, already_allocated:int}>  $lots  Oldest first.
     * @return array{allocations:list<int>, unallocated:int}
     */
    public static function plan(array $lots, int $consume): array
    {
        $remaining = max(0, $consume);
        $allocations = [];

        foreach ($lots as $lot) {
            $availableInLot = max(0, $lot['points'] - $lot['already_allocated']);
            $take = min($availableInLot, $remaining);

            $allocations[] = $take;
            $remaining -= $take;
        }

        return ['allocations' => $allocations, 'unallocated' => $remaining];
    }

    /**
     * Plan a release: points going BACK to the lots a consumption drew from.
     *
     * D9a. Restored points return to their original lots, keeping the expiry
     * they always had, so a redeem-and-refund cycle cannot extend the life of a
     * point. A release is therefore bounded twice over: it can only touch lots
     * this consumption actually drew from, and only up to what it drew.
     *
     * Oldest lot first, the same order the consumption used. One ordering rule
     * for both directions is easier to reason about than two, and it is the
     * conservative choice: points go back to the lot that expires soonest,
     * which is where they would have been had the redemption never happened.
     *
     * @param  list<array{allocated:int, already_released:int}>  $allocations  Oldest lot first.
     * @return array{releases:list<int>, unreleased:int}
     */
    public static function planRelease(array $allocations, int $release): array
    {
        $remaining = max(0, $release);
        $releases = [];

        foreach ($allocations as $allocation) {
            $releasable = max(0, $allocation['allocated'] - $allocation['already_released']);
            $give = min($releasable, $remaining);

            $releases[] = $give;
            $remaining -= $give;
        }

        return ['releases' => $releases, 'unreleased' => $remaining];
    }

    /**
     * Allocate a consuming entry against the account lots and write the rows.
     *
     * $preferredLotId draws from one named lot before falling back to FIFO. An
     * expiry must consume the lot that is expiring and no other, and an earn
     * reversal should take back the points that earn created rather than an
     * older member's-choice lot. A redemption passes null and is pure FIFO.
     *
     * @return array{allocated:int, unallocated:int}
     */
    public function allocate(LedgerEntry $consumer, int $points, ?int $preferredLotId = null): array
    {
        $lots = $this->openLotsFor($consumer->loyalty_account_id);

        if ($preferredLotId !== null) {
            usort($lots, fn (array $a, array $b): int => ($b['id'] === $preferredLotId ? 1 : 0)
                <=> ($a['id'] === $preferredLotId ? 1 : 0));
        }

        $plan = self::plan(
            array_map(
                fn (array $lot): array => [
                    'points' => $lot['points'],
                    'already_allocated' => $lot['already_allocated'],
                ],
                $lots,
            ),
            $points,
        );

        $rows = [];
        $now = now();

        foreach ($plan['allocations'] as $index => $take) {
            if ($take <= 0) {
                continue;
            }

            $rows[] = [
                'shop_domain' => $consumer->shop_domain,
                'lot_entry_id' => $lots[$index]['id'],
                'consuming_entry_id' => $consumer->id,
                'points' => $take,
                'created_at' => $now,
            ];
        }

        if ($rows !== []) {
            LotAllocation::insert($rows);
        }

        return [
            'allocated' => array_sum($plan['allocations']),
            'unallocated' => $plan['unallocated'],
        ];
    }

    /**
     * Give points back to the lots a consumption drew from, as negative rows.
     *
     * D9a. The ledger is append-only and so is this table, so an allocation is
     * never edited or removed; a give-back is a new row with negative points
     * against the same lot. Every reader is already SUM(alloc.points), so the
     * arithmetic nets with no change to a single query.
     *
     * @param  LedgerEntry  $releasing  The redemption_restore entry doing the giving back.
     * @param  int  $consumingEntryId  The original redemption entry being unwound.
     * @return array{released:int, unreleased:int}
     */
    public function release(LedgerEntry $releasing, int $consumingEntryId, int $points): array
    {
        $allocations = $this->allocationsFor($consumingEntryId);

        $plan = self::planRelease(
            array_map(
                fn (array $row): array => [
                    'allocated' => $row['allocated'],
                    'already_released' => $row['already_released'],
                ],
                $allocations,
            ),
            $points,
        );

        $rows = [];
        $now = now();

        foreach ($plan['releases'] as $index => $give) {
            if ($give <= 0) {
                continue;
            }

            $lot = $allocations[$index];

            // The invariant, asserted rather than assumed: a lot can never be
            // handed back more than it gave. If this ever trips, the ledger is
            // about to become untrue and stopping is the only safe answer.
            if ($give > $lot['allocated'] - $lot['already_released']) {
                throw new RuntimeException(
                    'Release of '.$give.' exceeds what lot '.$lot['lot_entry_id']
                    .' gave to entry '.$consumingEntryId.'.'
                );
            }

            $rows[] = [
                'shop_domain' => $releasing->shop_domain,
                'lot_entry_id' => $lot['lot_entry_id'],
                'consuming_entry_id' => $releasing->id,
                'points' => -$give,
                'created_at' => $now,
            ];
        }

        if ($rows !== []) {
            LotAllocation::insert($rows);
        }

        return [
            'released' => array_sum($plan['releases']),
            'unreleased' => $plan['unreleased'],
        ];
    }

    /**
     * What one consumption drew from each lot, and how much is already back.
     *
     * Oldest lot first, so a release unwinds in the order the consumption ran.
     *
     * @return list<array{lot_entry_id:int, allocated:int, already_released:int}>
     */
    public function allocationsFor(int $consumingEntryId): array
    {
        $drawn = DB::table('loyalty_lot_allocations as alloc')
            ->join('loyalty_ledger as lot', 'lot.id', '=', 'alloc.lot_entry_id')
            ->where('alloc.consuming_entry_id', $consumingEntryId)
            ->where('alloc.points', '>', 0)
            ->orderBy('lot.occurred_at')
            ->orderBy('lot.id')
            ->select('alloc.lot_entry_id', 'alloc.points')
            ->get();

        // Releases are attributed to restore entries, and a restore names the
        // consumption it unwinds through parent_entry_id. Scoping by that keeps
        // two different redemptions against one lot from reading each other's
        // give-backs as their own.
        $released = DB::table('loyalty_lot_allocations as alloc')
            ->join('loyalty_ledger as restore', 'restore.id', '=', 'alloc.consuming_entry_id')
            ->where('restore.entry_type', 'redemption_restore')
            ->where('restore.parent_entry_id', $consumingEntryId)
            ->where('alloc.points', '<', 0)
            ->groupBy('alloc.lot_entry_id')
            ->selectRaw('alloc.lot_entry_id as lot_entry_id, -sum(alloc.points) as released')
            ->pluck('released', 'lot_entry_id');

        $rows = [];

        foreach ($drawn as $row) {
            $rows[] = [
                'lot_entry_id' => (int) $row->lot_entry_id,
                'allocated' => (int) $row->points,
                'already_released' => (int) ($released[$row->lot_entry_id] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * Lots with points still to give, oldest first.
     *
     * A lot only offers points that have actually become available: an earn
     * whose maturity has not run yet is still pending and must not be
     * redeemable. Opening balances and positive adjustments are available the
     * moment they are posted, so they have no maturity entry to wait for.
     *
     * @return list<array{id:int, points:int, already_allocated:int}>
     */
    public function openLotsFor(int $accountId): array
    {
        $matured = DB::table('loyalty_ledger as maturity')
            ->selectRaw('maturity.parent_entry_id as lot_id, sum(maturity.available_delta) as matured')
            ->where('maturity.entry_type', 'maturity')
            ->whereNotNull('maturity.parent_entry_id')
            ->groupBy('maturity.parent_entry_id');

        $rows = DB::table('loyalty_ledger as lot')
            ->leftJoinSub($matured, 'm', 'm.lot_id', '=', 'lot.id')
            ->leftJoin('loyalty_lot_allocations as alloc', 'alloc.lot_entry_id', '=', 'lot.id')
            ->where('lot.loyalty_account_id', $accountId)
            ->where(function ($query): void {
                $query->whereIn('lot.entry_type', ['opening_balance'])
                    ->orWhere(fn ($q) => $q->where('lot.entry_type', 'adjustment')->where('lot.available_delta', '>', 0))
                    ->orWhere('lot.entry_type', 'earn');
            })
            ->groupBy('lot.id', 'lot.entry_type', 'lot.available_delta', 'lot.occurred_at', 'm.matured')
            ->orderBy('lot.occurred_at')
            ->orderBy('lot.id')
            ->selectRaw(
                'lot.id as id,
                 lot.entry_type as entry_type,
                 lot.available_delta as available_delta,
                 coalesce(m.matured, 0) as matured,
                 coalesce(sum(alloc.points), 0) as already_allocated'
            )
            ->get();

        $lots = [];

        foreach ($rows as $row) {
            // An earn contributes only what has matured; everything else was
            // available from the moment it was posted.
            $points = $row->entry_type === 'earn'
                ? (int) $row->matured
                : (int) $row->available_delta;

            $alreadyAllocated = (int) $row->already_allocated;

            if ($points - $alreadyAllocated <= 0) {
                continue;
            }

            $lots[] = [
                'id' => (int) $row->id,
                'points' => $points,
                'already_allocated' => $alreadyAllocated,
            ];
        }

        return $lots;
    }

    /** Points still to give from one lot. */
    public function remainingForLot(int $lotEntryId): int
    {
        $lot = LedgerEntry::query()->findOrFail($lotEntryId);

        $matured = $lot->entry_type === 'earn'
            ? (int) LedgerEntry::query()
                ->where('entry_type', 'maturity')
                ->where('parent_entry_id', $lot->id)
                ->sum('available_delta')
            : (int) $lot->available_delta;

        $allocated = (int) LotAllocation::query()->where('lot_entry_id', $lot->id)->sum('points');

        return max(0, $matured - $allocated);
    }
}
