<?php

namespace App\Domain\Loyalty;

use App\Models\LedgerEntry;

/**
 * The balance as it stood after each movement, for one page of a member ledger.
 *
 * The profile ledger shows a running Available column, and it has to be the
 * balance after that entry — not a total of the rows on screen. Two things
 * follow, and both are the reason this is not a loop in a controller:
 *
 *   1. The starting point is a sum over every earlier entry, filtered or not.
 *      Filtering the view to adjustments must not make the running balance
 *      pretend the earnings never happened.
 *   2. Order is (occurred_at, id), the same order the ledger itself uses, so two
 *      entries sharing a timestamp produce a stable sequence rather than one
 *      that depends on how the rows came back.
 *
 * One aggregate query for the page, then arithmetic.
 */
final class RunningBalance
{
    /**
     * @param  list<LedgerEntry>  $entries
     * @return array<int, array{available_after:int, pending_after:int}> Keyed by entry id.
     */
    public static function forPage(int $accountId, array $entries): array
    {
        if ($entries === []) {
            return [];
        }

        $oldest = $entries[count($entries) - 1];

        $base = LedgerEntry::query()
            ->where('loyalty_account_id', $accountId)
            ->where(function ($query) use ($oldest): void {
                $query->where('occurred_at', '<', $oldest->occurred_at)
                    ->orWhere(function ($inner) use ($oldest): void {
                        $inner->where('occurred_at', $oldest->occurred_at)
                            ->where('id', '<=', $oldest->id);
                    });
            })
            ->selectRaw(
                'coalesce(sum(available_delta), 0) as available,
                 coalesce(sum(pending_delta), 0) as pending'
            )
            ->first();

        $available = (int) ($base->available ?? 0);
        $pending = (int) ($base->pending ?? 0);

        $balances = [];

        // Walk from the oldest row on the page forwards in time. The oldest row
        // is already included in the base sum, so it is recorded before any
        // further delta is added.
        for ($index = count($entries) - 1; $index >= 0; $index--) {
            if ($index < count($entries) - 1) {
                $available += (int) $entries[$index]->available_delta;
                $pending += (int) $entries[$index]->pending_delta;
            }

            $balances[(int) $entries[$index]->id] = [
                'available_after' => $available,
                'pending_after' => $pending,
            ];
        }

        return $balances;
    }
}
