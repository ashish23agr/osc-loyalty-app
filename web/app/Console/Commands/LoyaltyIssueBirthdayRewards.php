<?php

namespace App\Console\Commands;

use App\Domain\Loyalty\BirthdaySweep;

/**
 * Issues the birthday reward to every member whose birthday is today.
 *
 * C4, confirmed 31 Aug 2026: lapsed members receive it. Eligibility is date of
 * birth on record and nothing else — there is no segment filter in this command
 * or in the sweep behind it.
 */
class LoyaltyIssueBirthdayRewards extends ShopScopedCommand
{
    protected $signature = 'loyalty:issue-birthday-rewards
                            {--shop= : Limit to one shop domain}
                            {--as-of= : Treat this date as today}
                            {--chunk=500 : Members per chunk}';

    protected $description = 'Issue the birthday reward to members whose birthday falls today';

    public function handle(BirthdaySweep $sweep): int
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
            $shop.' ('.$counts['day'].')',
            $counts['issued'].' issued'
            // MD8 in practice: the unique index refused a second voucher for
            // this year, which is the job re-running safely rather than
            // anything going wrong.
            .($counts['already_held'] > 0 ? ' · '.$counts['already_held'].' already held' : '')
            .' · '.$counts['no_birthday'].' member(s) hold no birthday',
        );
    }
}
