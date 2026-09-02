<?php

namespace App\Console\Commands;

use App\Domain\Loyalty\SegmentSweep;

/**
 * Recalculates Active / Lapsed / Unknown for every member.
 *
 * Derived, never assigned, so this is safe to run as often as anyone likes and
 * produces the same answer every time. D7: it reads our own ledger and the
 * migrated last-spend date, and does not wait on `read_all_orders`.
 */
class LoyaltyRecalculateSegments extends ShopScopedCommand
{
    protected $signature = 'loyalty:recalculate-segments
                            {--shop= : Limit to one shop domain}
                            {--as-of= : Treat this datetime as now}
                            {--chunk=500 : Members per chunk}';

    protected $description = 'Recalculate the Active and Lapsed segmentation for every member';

    public function handle(SegmentSweep $sweep): int
    {
        return $this->eachShop(fn (string $shop): array => $sweep->run(
            $shop,
            $this->asOf(),
            (int) $this->option('chunk'),
        ));
    }

    protected function report(string $shop, array $counts): void
    {
        $this->components->twoColumnDetail(
            $shop,
            $counts['examined'].' examined · '.$counts['changed'].' changed · '
            .$counts['active'].' active / '.$counts['lapsed'].' lapsed / '
            .$counts['unknown'].' unknown',
        );
    }
}
