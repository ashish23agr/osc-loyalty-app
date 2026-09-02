<?php

namespace App\Console\Commands;

use App\Domain\Loyalty\BalanceCalculator;
use App\Models\LoyaltyAccount;
use Illuminate\Console\Command;

/**
 * Asserts that every cached balance still matches the ledger.
 *
 * Exits non-zero on any drift so cron can alarm on it. The difference between
 * this and loyalty:rebuild-balances is deliberate: this one changes nothing, so
 * it is safe to run continuously and its failure is a signal rather than a
 * silent repair.
 */
class LoyaltyVerifyLedger extends Command
{
    protected $signature = 'loyalty:verify-ledger
                            {--shop= : Limit to one shop domain}
                            {--chunk=500 : Accounts per chunk}';

    protected $description = 'Check cached loyalty balances against the ledger without changing anything';

    public function handle(BalanceCalculator $balances): int
    {
        $query = LoyaltyAccount::query();

        if ($shop = $this->option('shop')) {
            $query->where('shop_domain', $shop);
        }

        $checked = 0;
        $drifted = [];

        $query->orderBy('id')->chunkById((int) $this->option('chunk'), function ($accounts) use ($balances, &$checked, &$drifted): void {
            foreach ($accounts as $account) {
                $result = $balances->verify($account);
                $checked++;

                if ($result['drifted']) {
                    $drifted[$account->id] = $result;
                }
            }
        });

        $this->components->info('Checked '.$checked.' account(s).');

        if ($drifted === []) {
            $this->components->info('Every cached balance matches the ledger.');

            return self::SUCCESS;
        }

        $this->components->error(count($drifted).' account(s) disagree with the ledger.');

        foreach ($drifted as $accountId => $result) {
            $this->components->twoColumnDetail(
                'account '.$accountId,
                'cached '.json_encode($result['cached']).' vs ledger '.json_encode($result['ledger']),
            );
        }

        $this->newLine();
        $this->components->warn('Run loyalty:rebuild-balances to correct these. The ledger is authoritative.');

        return self::FAILURE;
    }
}
