<?php

namespace App\Console\Commands;

use App\Domain\Redemption\ExclusionSync;

/**
 * Pushes the saved qualification rules out to the metafields the discount
 * function reads (V6a).
 *
 * Run automatically whenever a rule set that touches qualification is saved, and
 * available by hand for a shop whose flags are suspected stale. Idempotent: it
 * writes the same answer for the same rules, so re-running costs API calls and
 * nothing else.
 */
class LoyaltySyncExclusions extends ShopScopedCommand
{
    protected $signature = 'loyalty:sync-exclusions
                            {--shop= : Limit to one shop domain}
                            {--batch=250 : Products per metafield write batch}';

    protected $description = 'Sync the saved item qualification rules to the metafields the discount function reads';

    public function handle(ExclusionSync $sync): int
    {
        return $this->eachShop(fn (string $shop): array => $sync->run(
            $shop,
            (int) $this->option('batch'),
        ));
    }

    protected function report(string $shop, array $counts): void
    {
        $this->components->twoColumnDetail(
            $shop.' (rules v'.($counts['config_version'] ?? '?').')',
            $counts['products'].' product(s) · '.$counts['excluded'].' excluded / '
            .$counts['included'].' included · '.$counts['written'].' flag(s) written'
            .($counts['shop_config_written'] ? ' · shop config written' : ' · SHOP CONFIG NOT WRITTEN'),
        );
    }
}
