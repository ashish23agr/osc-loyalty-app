<?php

namespace App\Console\Commands;

use App\Domain\Loyalty\ExpirySweep;

/**
 * Retires available points that have reached their expiry date.
 *
 * Daily, and after maturity in the schedule: points that mature today and
 * expire today should do both in the right order rather than depending on which
 * cron entry fired first.
 */
class LoyaltyExpirePoints extends ShopScopedCommand
{
    protected $signature = 'loyalty:expire-points
                            {--shop= : Limit to one shop domain}
                            {--as-of= : Treat this datetime as now}
                            {--batch=500 : Lots per pass}';

    protected $description = 'Expire loyalty points whose expiry date has passed';

    public function handle(ExpirySweep $sweep): int
    {
        return $this->eachShop(fn (string $shop): array => $sweep->run(
            $shop,
            $this->asOf(),
            (int) $this->option('batch'),
        ));
    }

    protected function report(string $shop, array $counts): void
    {
        $this->components->twoColumnDetail(
            $shop,
            $counts['expired'].' lot(s) · '.$counts['points'].' points · '
            .$counts['accounts'].' member(s)',
        );
    }
}
