<?php

namespace App\Console\Commands;

use App\Domain\Loyalty\MaturitySweep;

/**
 * Moves pending points to available once their pending period is up.
 *
 * Hourly rather than daily: a member who bought thirty days ago should not have
 * to wait until the small hours to see their points, and the sweep is cheap
 * because it only looks at earns no maturity entry has answered yet.
 */
class LoyaltyMaturePoints extends ShopScopedCommand
{
    protected $signature = 'loyalty:mature-points
                            {--shop= : Limit to one shop domain}
                            {--as-of= : Treat this datetime as now}
                            {--chunk=500 : Earn entries per chunk}';

    protected $description = 'Move pending loyalty points into the available bucket once they have matured';

    public function handle(MaturitySweep $sweep): int
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
            $counts['matured'].' matured · '.$counts['points'].' points · '
            .$counts['accounts'].' member(s)'
            // D9b: an earn fully reversed before maturity has nothing to move.
            // Reported rather than hidden, because "settled" and "skipped" are
            // not the same thing and only one is a problem.
            .($counts['settled'] > 0 ? ' · '.$counts['settled'].' settled by reversal' : ''),
        );
    }
}
