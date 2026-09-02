<?php

namespace App\Domain\Loyalty;

use App\Domain\Events\LoyaltyEventName;
use App\Domain\Events\MemberEvents;
use App\Domain\Rules\RulesVersionRepository;
use App\Models\LoyaltyAccount;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Available points reaching their expiry date (D1, D2, M3).
 *
 * One entry per lot, pointing at the lot it retired, and consuming that lot and
 * no other. Left to FIFO an expiry would retire whichever points happened to be
 * oldest, and the lot that actually expired would sit there looking unspent -
 * so the posting names its target and LedgerService honours it.
 *
 * What expires is what the lot has LEFT: its total less everything already
 * drawn from it, netted against anything released back into it by a refund
 * (D9a). Points already spent cannot expire, and points handed back after the
 * date has passed expire on the next sweep rather than living on.
 *
 * The idempotency key carries the sweep date for that reason. Keyed on the lot
 * alone, a lot that had points released back into it after expiring could never
 * expire them.
 */
final class ExpirySweep
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly ExpiryOutlook $outlook,
        private readonly RulesVersionRepository $rules,
        private readonly MemberEvents $events,
    ) {}

    /**
     * @return array{expired:int, points:int, accounts:int}
     */
    public function run(string $shopDomain, ?DateTimeInterface $asOf = null, int $batch = 500): array
    {
        $asOf = $asOf === null ? now() : Carbon::parse($asOf);
        $stamp = $asOf->format('Y-m-d');

        $expired = 0;
        $points = 0;
        $accounts = [];

        // Loop rather than one pass: expiring a lot changes what is due, and a
        // batch keeps a shop with a long backlog from being read into memory in
        // one go.
        while (true) {
            $due = $this->outlook->dueLots($shopDomain, $asOf, $batch);

            if ($due === []) {
                break;
            }

            $postedThisPass = 0;

            foreach ($due as $lot) {
                $account = LoyaltyAccount::query()->find($lot['account_id']);

                if ($account === null || $account->isMerged()) {
                    continue;
                }

                $before = $this->ledger->hasPosted(
                    $shopDomain,
                    $key = 'expire:'.$lot['id'].':'.$stamp,
                );

                if ($before) {
                    // Already answered on this sweep date. Anything still
                    // showing due is next sweep's business, not an infinite
                    // loop's.
                    continue;
                }

                $this->ledger->post($account, LedgerPosting::expiry(
                    points: $lot['points'],
                    parentEntryId: $lot['id'],
                    idempotencyKey: $key,
                    // The date the points died, not the moment the sweep ran.
                    occurredAt: Carbon::parse($lot['expires_at']),
                ));

                $expired++;
                $postedThisPass++;
                $points += $lot['points'];
                $accounts[$lot['account_id']] = true;
            }

            if ($postedThisPass === 0) {
                break;
            }
        }

        return [
            'expired' => $expired,
            'points' => $points,
            'accounts' => count($accounts),
            'warned' => $this->warnAboutUpcoming($shopDomain, $asOf),
        ];
    }

    /**
     * The expiry reminder hook (M11, Sprint 4).
     *
     * Fired on the day a lot ENTERS the warning window, not on every night it
     * spends inside it. A nightly repeat would give the flow thirty triggers per
     * lot and make suppression Klaviyo's problem; one trigger per crossing makes
     * it nobody's.
     *
     * The window is a single day wide for that reason: lots whose expiry falls
     * exactly `expiry_warning_days` from today, and no others.
     */
    private function warnAboutUpcoming(string $shopDomain, Carbon $asOf): int
    {
        $warningDays = $this->rules->current($shopDomain)->expiryWarningDays();

        if ($warningDays <= 0) {
            return 0;
        }

        $crossingToday = $asOf->copy()->startOfDay()->addDays($warningDays);

        $lots = $this->outlook->dueLots(
            $shopDomain,
            $crossingToday->copy()->addDay(),
            10000,
        );

        $byAccount = [];

        foreach ($lots as $lot) {
            // dueLots answers "expiring before X", so anything already past the
            // warning date has been warned about on an earlier night.
            if (Carbon::parse($lot['expires_at'])->lessThan($crossingToday)) {
                continue;
            }

            $byAccount[$lot['account_id']] = ($byAccount[$lot['account_id']] ?? 0) + $lot['points'];
        }

        $warned = 0;

        foreach ($byAccount as $accountId => $expiringPoints) {
            $account = LoyaltyAccount::query()->find($accountId);

            if ($account === null || $account->isMerged()) {
                continue;
            }

            $this->events->emit($account, LoyaltyEventName::POINTS_EXPIRING_SOON, [
                'points_expiring' => $expiringPoints,
                'expires_on' => $crossingToday->toDateString(),
                'warning_days' => $warningDays,
            ]);

            $warned++;
        }

        return $warned;
    }
}
