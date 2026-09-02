<?php

namespace App\Domain\Loyalty;

use App\Models\LoyaltyAccount;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Recalculating every member as Active, Lapsed or Unknown (M9).
 *
 * Derived, never assigned: there is no endpoint that sets a segment, and this
 * job is free to run as often as anyone likes because it is a pure function of
 * the ledger and the migrated dates. Running it twice produces the same answer,
 * which is the property that makes a nightly schedule safe.
 *
 * Merged accounts are skipped. Their ledger has been replayed into the
 * surviving account, so segmenting them would put a stale pill on a record
 * nobody should be reading.
 */
final class SegmentSweep
{
    public function __construct(private readonly LastSpendResolver $lastSpend) {}

    /**
     * @return array{examined:int, changed:int, active:int, lapsed:int, unknown:int}
     */
    public function run(string $shopDomain, ?DateTimeInterface $asOf = null, int $chunk = 500): array
    {
        $asOf = $asOf === null ? now() : Carbon::parse($asOf);

        $examined = 0;
        $changed = 0;
        $counts = ['active' => 0, 'lapsed' => 0, 'unknown' => 0];

        LoyaltyAccount::query()
            ->where('shop_domain', $shopDomain)
            ->where('status', '!=', 'merged')
            ->orderBy('id')
            ->chunkById($chunk, function ($members) use ($asOf, &$examined, &$changed, &$counts): void {
                $ids = $members->pluck('id')->all();
                $lastSpends = $this->lastSpend->forAccounts($ids);

                foreach ($members as $member) {
                    $lastSpend = $lastSpends[$member->id] ?? null;
                    $segment = SegmentCalculator::for($lastSpend, $asOf);

                    $examined++;
                    $counts[$segment]++;

                    $moved = $member->segment !== $segment
                        || $this->differs($member->last_qualifying_spend_at, $lastSpend);

                    if ($moved) {
                        $changed++;
                    }

                    // Written every time, moved or not: segment_calculated_at is
                    // how the console shows that segmentation is still running,
                    // and a night where nothing changed is exactly when that
                    // matters.
                    $member->forceFill([
                        'segment' => $segment,
                        'last_qualifying_spend_at' => $lastSpend,
                        'segment_calculated_at' => now(),
                    ])->save();
                }
            });

        return [
            'examined' => $examined,
            'changed' => $changed,
            'active' => $counts['active'],
            'lapsed' => $counts['lapsed'],
            'unknown' => $counts['unknown'],
        ];
    }

    private function differs(?DateTimeInterface $a, ?DateTimeInterface $b): bool
    {
        if ($a === null || $b === null) {
            return $a !== $b;
        }

        return ! Carbon::parse($a)->equalTo(Carbon::parse($b));
    }
}
