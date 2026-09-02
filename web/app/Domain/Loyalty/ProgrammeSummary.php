<?php

namespace App\Domain\Loyalty;

use App\Domain\Rules\RulesVersionRepository;
use App\Models\AuditEntry;
use App\Models\LedgerEntry;
use App\Models\LoyaltyAccount;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The programme-wide figures behind the dashboard tiles and the Loyalty screen.
 *
 * One class rather than aggregates written into two controllers, because the
 * dashboard and the Loyalty screen show overlapping tiles and two
 * implementations of "points in circulation" would eventually disagree in front
 * of the client.
 *
 * "Today" is the reporting day, taken from the rule version rather than the
 * server clock, so a figure quoted at half past midnight in British Summer Time
 * covers the day OSC is actually trading.
 */
final class ProgrammeSummary
{
    public function __construct(
        private readonly RulesVersionRepository $rules,
        private readonly ExpiryOutlook $expiry,
    ) {}

    /** The five tiles on the Loyalty screen, and nothing more. */
    public function loyaltyTiles(string $shopDomain): array
    {
        $rules = $this->rules->current($shopDomain);
        $today = $this->startOfReportingDay($rules->reportingTimezone());

        $window = $this->expiry->forShop(
            $shopDomain,
            now(),
            now()->addDays($rules->expiryWarningDays()),
        );

        return [
            'points_in_circulation' => $this->sum($shopDomain, 'available_delta'),
            'points_pending' => $this->sum($shopDomain, 'pending_delta'),
            'points_earned_today' => (int) $this->entries($shopDomain)
                ->where('entry_type', 'earn')
                ->where('occurred_at', '>=', $today)
                ->sum('pending_delta'),
            // The spend behind today's earning. Held on every earn as
            // qualifying_value_pence since Sprint 1; only aggregated here now
            // that something actually posts it.
            'qualifying_spend_today_pence' => (int) $this->entries($shopDomain)
                ->where('entry_type', 'earn')
                ->where('occurred_at', '>=', $today)
                ->sum('qualifying_value_pence'),
            'points_reversed_today' => (int) $this->entries($shopDomain)
                ->where('entry_type', 'earn_reversal')
                ->where('occurred_at', '>=', $today)
                ->sum(DB::raw('pending_delta + available_delta')),
            // The refund and cancellation split. A reversal driven by
            // refunds/create carries a refund id; one driven by
            // orders/cancelled does not, which is the whole distinction.
            'reversals_today' => $this->reversalSplit($shopDomain, $today),
            'expiring_soon' => [
                'points' => $window['points'],
                'lots' => $window['lots'],
                'next_expires_at' => $window['next_expires_at'] === null
                    ? null
                    : Carbon::parse($window['next_expires_at'])->toIso8601String(),
                'window_days' => $rules->expiryWarningDays(),
            ],
            'voucher_value_outstanding_pence' => (int) LoyaltyAccount::query()
                ->where('shop_domain', $shopDomain)
                ->where('status', '!=', 'merged')
                ->sum('voucher_balance_pence'),
        ];
    }

    /** Everything the dashboard shows, tiles included. */
    public function overview(string $shopDomain): array
    {
        $rules = $this->rules->current($shopDomain);

        return [
            'generated_at' => now()->toIso8601String(),
            'reporting_timezone' => $rules->reportingTimezone(),
            'currency' => $rules->currency(),
            'members' => $this->members($shopDomain),
            'enrolments' => $this->enrolments($shopDomain),
            // The Loyalty tiles plus the one figure only the dashboard asks
            // for. Added here rather than to loyaltyTiles() because the Loyalty
            // screen is a view of now — what is in circulation, what cleared
            // today — and a thirty-day total would sit oddly beside it.
            'points' => $this->loyaltyTiles($shopDomain) + [
                'points_issued_last_30_days' => (int) $this->entries($shopDomain)
                    ->where('entry_type', 'earn')
                    ->where('occurred_at', '>=', now()->subDays(30))
                    ->sum('pending_delta'),
            ],
            'redemptions' => $this->redemptions($shopDomain),
            'jobs' => $this->jobHealth($shopDomain),
        ];
    }

    /**
     * How today's reversals divide between refunds and cancellations.
     *
     * @return array{refunds:array{entries:int, points:int}, cancellations:array{entries:int, points:int}}
     */
    private function reversalSplit(string $shopDomain, Carbon $today): array
    {
        $rows = $this->entries($shopDomain)
            ->where('entry_type', 'earn_reversal')
            ->where('occurred_at', '>=', $today)
            ->selectRaw(
                'case when shopify_refund_id is null then 0 else 1 end as is_refund,
                 count(*) as entries,
                 coalesce(sum(pending_delta + available_delta), 0) as points'
            )
            ->groupBy('is_refund')
            ->get()
            ->keyBy('is_refund');

        return [
            'refunds' => [
                'entries' => (int) ($rows[1]->entries ?? 0),
                'points' => (int) ($rows[1]->points ?? 0),
            ],
            'cancellations' => [
                'entries' => (int) ($rows[0]->entries ?? 0),
                'points' => (int) ($rows[0]->points ?? 0),
            ],
        ];
    }

    private function members(string $shopDomain): array
    {
        $byStatus = LoyaltyAccount::query()
            ->where('shop_domain', $shopDomain)
            ->groupBy('status')
            ->selectRaw('status, count(*) as total')
            ->pluck('total', 'status');

        $bySegment = LoyaltyAccount::query()
            ->where('shop_domain', $shopDomain)
            ->where('status', 'active')
            ->groupBy('segment')
            ->selectRaw('segment, count(*) as total')
            ->pluck('total', 'segment');

        return [
            'total' => (int) $byStatus->sum(),
            'active' => (int) ($byStatus['active'] ?? 0),
            'suspended' => (int) ($byStatus['suspended'] ?? 0),
            'merged' => (int) ($byStatus['merged'] ?? 0),
            'closed' => (int) ($byStatus['closed'] ?? 0),
            'segment_active' => (int) ($bySegment['active'] ?? 0),
            'segment_lapsed' => (int) ($bySegment['lapsed'] ?? 0),
            'segment_unknown' => (int) ($bySegment['unknown'] ?? 0),
            // MD1: counted, because a member with no email is a supported state
            // and OSC needs to see how many of them the programme carries.
            'without_email' => (int) LoyaltyAccount::query()
                ->where('shop_domain', $shopDomain)
                ->whereNull('email_normalised')
                ->count(),
        ];
    }

    private function enrolments(string $shopDomain): array
    {
        $recent = LoyaltyAccount::query()
            ->where('shop_domain', $shopDomain)
            ->where('enrolled_at', '>=', now()->subDays(30))
            ->groupBy('enrolment_channel')
            ->selectRaw('enrolment_channel, count(*) as total')
            ->pluck('total', 'enrolment_channel');

        $last30 = (int) $recent->sum();

        $previous30 = (int) LoyaltyAccount::query()
            ->where('shop_domain', $shopDomain)
            ->where('enrolled_at', '>=', now()->subDays(60))
            ->where('enrolled_at', '<', now()->subDays(30))
            ->count();

        return [
            'last_30_days' => $last30,
            'previous_30_days' => $previous30,
            // Null rather than a fabricated percentage when there is nothing to
            // compare against, so the tile can say so instead of showing +100%.
            'change_percent' => $previous30 === 0
                ? null
                : (int) round((($last30 - $previous30) / $previous30) * 100),
            'by_channel' => [
                'online' => (int) ($recent['online'] ?? 0),
                'pos' => (int) ($recent['pos'] ?? 0),
                'admin' => (int) ($recent['admin'] ?? 0),
                'migration' => (int) ($recent['migration'] ?? 0),
            ],
        ];
    }

    private function redemptions(string $shopDomain): array
    {
        $row = DB::table('loyalty_redemptions')
            ->where('shop_domain', $shopDomain)
            ->where('state', 'confirmed')
            ->where('confirmed_at', '>=', now()->subDays(30))
            ->selectRaw('count(*) as total, coalesce(sum(amount_pence), 0) as amount')
            ->first();

        return [
            'last_30_days' => (int) ($row->total ?? 0),
            'last_30_days_pence' => (int) ($row->amount ?? 0),
        ];
    }

    /**
     * Whether the scheduled work is keeping up.
     *
     * Both figures are counted from the ledger rather than from a job table,
     * so a queue that silently stopped shows here as work piling up rather than
     * as a job that simply never ran.
     */
    private function jobHealth(string $shopDomain): array
    {
        $overdueMaturities = $this->entries($shopDomain)
            ->where('entry_type', 'earn')
            ->where('matures_at', '<=', now())
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('loyalty_ledger as m')
                    ->whereColumn('m.parent_entry_id', 'loyalty_ledger.id')
                    ->where('m.entry_type', 'maturity');
            })
            // D9b: an earn reversed away before it matured has nothing left to
            // mature and never gets a maturity entry. Without this it would sit
            // in "overdue" for ever and the Loyalty screen would report a
            // backlog that no amount of running the sweep could clear.
            ->whereRaw(
                'loyalty_ledger.pending_delta + coalesce((
                    select sum(r.pending_delta) from loyalty_ledger as r
                    where r.parent_entry_id = loyalty_ledger.id and r.entry_type = ?
                ), 0) > 0',
                ['earn_reversal'],
            );

        $overdueExpiries = $this->expiry->forShop($shopDomain, now()->subYears(20), now());

        return [
            'overdue_maturities' => [
                'entries' => (int) (clone $overdueMaturities)->count(),
                'points' => (int) (clone $overdueMaturities)->sum('pending_delta'),
            ],
            'overdue_expiries' => [
                'lots' => $overdueExpiries['lots'],
                'points' => $overdueExpiries['points'],
            ],
            'oldest_pending_clears_at' => $this->iso(
                $this->entries($shopDomain)
                    ->where('entry_type', 'earn')
                    ->where('matures_at', '>', now())
                    ->min('matures_at')
            ),
            'oldest_cache_rebuilt_at' => $this->iso(
                LoyaltyAccount::query()
                    ->where('shop_domain', $shopDomain)
                    ->whereNotNull('caches_rebuilt_at')
                    ->min('caches_rebuilt_at')
            ),
            // C12 pays for itself here: because every sweep writes one audit
            // entry per run, the console can say when each last ran without a
            // job table, and a sweep that silently stopped shows as a date that
            // stopped moving rather than as nothing at all.
            'last_runs' => $this->lastRuns($shopDomain),
            'next_expiry_run_at' => $this->nextRunOf('loyalty:expire-points'),
            'next_maturity_run_at' => $this->nextRunOf('loyalty:mature-points'),
        ];
    }

    /**
     * When each scheduled sweep last completed, from the audit log.
     *
     * @return array<string, array{at:?string, counts:array<string, mixed>}>
     */
    private function lastRuns(string $shopDomain): array
    {
        $runs = [];

        $entries = AuditEntry::query()
            ->where('shop_domain', $shopDomain)
            ->where('action', 'job.completed')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        foreach ($entries as $entry) {
            $job = $entry->after_state['job'] ?? null;

            if ($job === null || isset($runs[$job])) {
                continue;
            }

            $runs[$job] = [
                'at' => $this->iso($entry->created_at),
                'counts' => $entry->after_state,
            ];
        }

        return $runs;
    }

    /**
     * The next time a scheduled command is due.
     *
     * Read from the schedule itself rather than restated here, so a change to
     * routes/console.php cannot leave the console quoting a time that is no
     * longer true.
     */
    private function nextRunOf(string $command): ?string
    {
        foreach (app(Schedule::class)->events() as $event) {
            if (! str_contains((string) $event->command, $command)) {
                continue;
            }

            return Carbon::instance(
                (new CronExpression($event->expression))->getNextRunDate(now())
            )->toIso8601String();
        }

        return null;
    }

    private function entries(string $shopDomain)
    {
        return LedgerEntry::query()->where('shop_domain', $shopDomain);
    }

    private function sum(string $shopDomain, string $column): int
    {
        return (int) $this->entries($shopDomain)->sum($column);
    }

    /** Midnight of the current reporting day, expressed for the database. */
    private function startOfReportingDay(string $timezone): Carbon
    {
        return now($timezone)->startOfDay()->setTimezone(config('app.timezone', 'UTC'));
    }

    /** Aggregates come back as a raw string on SQLite and a date on MySQL. */
    private function iso(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->toIso8601String();
    }
}
