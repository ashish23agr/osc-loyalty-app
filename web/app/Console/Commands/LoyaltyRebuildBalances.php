<?php

namespace App\Console\Commands;

use App\Domain\Loyalty\BalanceCalculator;
use App\Models\LoyaltyAccount;
use Illuminate\Console\Command;

/**
 * Rebuilds cached balances from the ledger.
 *
 * The Blueprint is explicit that cached balances are disposable and that the
 * ledger wins any disagreement. This is the command that makes that true in
 * practice rather than in principle.
 */
class LoyaltyRebuildBalances extends Command
{
    protected $signature = 'loyalty:rebuild-balances
                            {--member= : Rebuild a single loyalty account by id}
                            {--shop= : Limit to one shop domain}
                            {--chunk=500 : Accounts per chunk}';

    protected $description = 'Recompute every cached loyalty balance from the ledger';

    public function handle(BalanceCalculator $balances): int
    {
        $query = LoyaltyAccount::query();

        if ($member = $this->option('member')) {
            $query->whereKey($member);
        }

        if ($shop = $this->option('shop')) {
            $query->where('shop_domain', $shop);
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->components->warn('No loyalty accounts matched.');

            return self::SUCCESS;
        }

        $this->components->info('Rebuilding '.$total.' account(s) from the ledger.');

        $changed = 0;
        $bar = $this->output->createProgressBar($total);

        $query->orderBy('id')->chunkById((int) $this->option('chunk'), function ($accounts) use ($balances, &$changed, $bar): void {
            foreach ($accounts as $account) {
                $before = $balances->verify($account);

                $balances->refreshCache($account);

                if ($before['drifted']) {
                    $changed++;

                    $this->newLine();
                    $this->components->twoColumnDetail(
                        'account '.$account->id.' corrected',
                        json_encode($before['cached']).' -> '.json_encode($before['ledger']),
                    );
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $changed === 0
            ? $this->components->info('Every cached balance already matched the ledger.')
            : $this->components->warn($changed.' account(s) had drifted and were corrected.');

        return self::SUCCESS;
    }
}
