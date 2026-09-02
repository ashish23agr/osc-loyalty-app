<?php

namespace App\Domain\Loyalty;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * When each member last spent qualifying money (M9, D7).
 *
 * Two sources, in order of authority:
 *
 *   1. Our own ledger. An earn entry exists only for qualifying spend, and its
 *      occurred_at is the order date, so the newest earn is the newest spend.
 *   2. The migrated last-spend date from the legacy export, for members whose
 *      spending happened before we existed.
 *
 * D7 is why the list stops there. `read_all_orders` has been requested and may
 * or may not be granted, and segmentation is built not to need it: every member
 * is segmented from what we already hold. The consequence is stated rather than
 * hidden - Shopify orders placed between the legacy export and go-live are
 * invisible, so a member who bought in that window reads as more lapsed than
 * they are until their next purchase corrects it. If the scope lands, a
 * backfill refines this date and posts nothing to the ledger; no balance moves,
 * which is what lets the grant arrive at any time, go-live included.
 *
 * Resolved in bulk. One query per chunk of accounts rather than two per member,
 * because this runs nightly over every account on the shop.
 */
final class LastSpendResolver
{
    /**
     * @param  list<int>  $accountIds
     * @return array<int, ?Carbon> Keyed by account id; null where nothing is known.
     */
    public function forAccounts(array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }

        $fromLedger = DB::table('loyalty_ledger')
            ->whereIn('loyalty_account_id', $accountIds)
            ->where('entry_type', 'earn')
            ->groupBy('loyalty_account_id')
            ->selectRaw('loyalty_account_id, max(occurred_at) as last_spend')
            ->pluck('last_spend', 'loyalty_account_id');

        $fromMigration = DB::table('migration_records')
            ->whereIn('loyalty_account_id', $accountIds)
            ->whereNotNull('last_spend_at')
            ->groupBy('loyalty_account_id')
            ->selectRaw('loyalty_account_id, max(last_spend_at) as last_spend')
            ->pluck('last_spend', 'loyalty_account_id');

        $resolved = [];

        foreach ($accountIds as $id) {
            // The later of the two rather than strictly "ledger first": a
            // migrated member who has since bought something has both, and the
            // newer date is the true one either way round.
            $dates = array_filter([
                $fromLedger[$id] ?? null,
                $fromMigration[$id] ?? null,
            ]);

            $resolved[$id] = $dates === []
                ? null
                : Carbon::parse(max(array_map(
                    fn ($value): string => (string) Carbon::parse($value)->toDateTimeString(),
                    $dates,
                )));
        }

        return $resolved;
    }

    public function forAccount(int $accountId): ?Carbon
    {
        return $this->forAccounts([$accountId])[$accountId] ?? null;
    }
}
