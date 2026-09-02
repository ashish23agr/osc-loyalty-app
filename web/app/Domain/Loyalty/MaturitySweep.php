<?php

namespace App\Domain\Loyalty;

use App\Domain\Events\LoyaltyEventName;
use App\Domain\Events\MemberEvents;
use App\Models\LedgerEntry;
use App\Models\LoyaltyAccount;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pending points becoming available, once their pending period is up (D2, M3).
 *
 * A transfer, never a credit: pending falls by exactly what available rises by,
 * so nothing is created and the ledger still sums to the same total points.
 *
 * D9b - the amount that matures is the earn NET OF REVERSALS, never the raw
 * earn. A refund before maturity takes points out of the pending bucket, and an
 * eighty point earn that has already had twenty reversed must mature sixty. A
 * sweep that matured the earn's own pending_delta would post -80 pending
 * against a bucket holding 60 and leave the member on -20 pending for ever,
 * with available overstated by the same twenty. That is why an earn_reversal
 * carries parent_entry_id: it is the only thing that connects the reversal back
 * to the earning it is netted against.
 *
 * Idempotent on 'mature:<earn id>'. Running the sweep twice, or running it
 * again after a crash, posts nothing the second time.
 */
final class MaturitySweep
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly BalanceCalculator $balances,
        private readonly MemberEvents $events,
    ) {}

    /**
     * @return array{matured:int, points:int, settled:int, accounts:int}
     */
    public function run(string $shopDomain, ?DateTimeInterface $asOf = null, int $chunk = 500): array
    {
        $asOf = $asOf === null ? now() : Carbon::instance(Carbon::parse($asOf));

        $matured = 0;
        $points = 0;
        $settled = 0;
        $accounts = [];

        $this->dueEarns($shopDomain, $asOf)
            ->orderBy('loyalty_ledger.id')
            ->chunkById($chunk, function ($earns) use (&$matured, &$points, &$settled, &$accounts): void {
                foreach ($earns as $earn) {
                    $net = $this->netOf($earn);

                    // Fully reversed before it ever matured. Nothing to move,
                    // and nothing owed: the earn is settled, not overdue.
                    if ($net <= 0) {
                        $settled++;

                        continue;
                    }

                    $account = LoyaltyAccount::query()->find($earn->loyalty_account_id);

                    if ($account === null || $account->isMerged()) {
                        continue;
                    }

                    // Taken before the posting, because the voucher increment
                    // event fires on a crossing and a crossing needs both sides.
                    $before = $this->balances->for($account);

                    $this->ledger->post($account, LedgerPosting::maturity(
                        points: $net,
                        parentEntryId: (int) $earn->id,
                        idempotencyKey: 'mature:'.$earn->id,
                        // The business event is the maturity date, not the
                        // moment the sweep happened to run. A sweep that was
                        // down for a day still records when the points became
                        // available.
                        occurredAt: $earn->matures_at,
                        expiresAt: $earn->expires_at,
                    ));

                    $matured++;
                    $points += $net;
                    $accounts[$earn->loyalty_account_id] = true;

                    $this->announce($account, $before, $net);
                }
            }, 'loyalty_ledger.id', 'id');

        return [
            'matured' => $matured,
            'points' => $points,
            'settled' => $settled,
            'accounts' => count($accounts),
        ];
    }

    /**
     * Tell Klaviyo what the member would want to know (M11, Sprint 4).
     *
     * Two events, and only the second is conditional: points becoming available
     * always matters, but "you have another five pounds" is only true when the
     * balance actually crossed an increment. Firing that one on every maturity
     * would promise a voucher that is not there.
     */
    private function announce(LoyaltyAccount $account, Balances $before, int $matured): void
    {
        $after = $this->balances->for($account->refresh());

        $this->events->emit($account, LoyaltyEventName::POINTS_AVAILABLE, [
            'points_matured' => $matured,
            'points_available' => $after->available,
            'voucher_balance_pence' => $after->voucher->pence(),
        ]);

        if ($after->voucher->increments > $before->voucher->increments) {
            $this->events->emit($account, LoyaltyEventName::VOUCHER_INCREMENT_REACHED, [
                'increments' => $after->voucher->increments,
                'increments_gained' => $after->voucher->increments - $before->voucher->increments,
                'voucher_balance_pence' => $after->voucher->pence(),
                'points_to_next_increment' => $after->voucher->pointsToNextIncrement,
            ]);
        }
    }

    /**
     * The earn, less every pending point already reversed against it (D9b).
     *
     * Reversal entries carry a negative pending_delta, so this is an addition
     * rather than a subtraction, and a reversal that came out of the available
     * bucket instead contributes zero here - correctly, because those points
     * had already matured and are not waiting on this sweep.
     */
    private function netOf(LedgerEntry $earn): int
    {
        $reversedPending = (int) LedgerEntry::query()
            ->where('entry_type', 'earn_reversal')
            ->where('parent_entry_id', $earn->id)
            ->sum('pending_delta');

        return (int) $earn->pending_delta + $reversedPending;
    }

    /** Earns past their maturity date that no maturity entry has answered. */
    private function dueEarns(string $shopDomain, DateTimeInterface $asOf)
    {
        return LedgerEntry::query()
            ->where('shop_domain', $shopDomain)
            ->where('entry_type', 'earn')
            ->whereNotNull('matures_at')
            ->where('matures_at', '<=', $asOf)
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('loyalty_ledger as m')
                    ->whereColumn('m.parent_entry_id', 'loyalty_ledger.id')
                    ->where('m.entry_type', 'maturity');
            });
    }
}
