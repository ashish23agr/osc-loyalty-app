<?php

namespace App\Console\Commands;

use App\Models\LoyaltyAccount;
use App\Support\Audit\JobAuditor;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Shared plumbing for the scheduled loyalty sweeps.
 *
 * Every one of them is per-shop, optionally scoped to one shop, and optionally
 * run against a stated date instead of the clock. That last option is not a
 * convenience: a sweep that can only be run at "now" cannot be tested at a
 * boundary, and boundaries are where expiry, maturity and segmentation go
 * wrong.
 *
 * The app is single-tenant in practice but the schema is not, so a sweep that
 * assumed one shop would quietly do the wrong thing on the day that changes.
 */
abstract class ShopScopedCommand extends Command
{
    /**
     * Run the sweep once per shop, audit each run, and report a failure without
     * abandoning the shops that have not been swept yet.
     *
     * C12: the audit entry is written here rather than in each command, so the
     * grain is decided once. One entry per shop per run, carrying the counts the
     * sweep returned — never one per member.
     *
     * @param  callable(string):array<string, mixed>  $callback  Returns the counts to audit.
     */
    protected function eachShop(callable $callback): int
    {
        $shops = $this->shops();

        if ($shops === []) {
            $this->components->info('No shops to sweep.');

            return self::SUCCESS;
        }

        $auditor = app(JobAuditor::class);
        $asOf = $this->asOf();
        $jobName = $this->jobName();
        $failed = 0;

        foreach ($shops as $shop) {
            try {
                $counts = $auditor->record(
                    $shop,
                    $jobName,
                    $asOf?->format('Y-m-d H:i:s'),
                    fn (): array => $callback($shop),
                );

                $this->report($shop, $counts);
            } catch (\Throwable $e) {
                $failed++;
                $this->components->error($shop.': '.$e->getMessage());
                report($e);
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** The signature's command name, which is what the audit entry records. */
    protected function jobName(): string
    {
        return trim(explode(' ', trim($this->signature))[0]);
    }

    /** How this sweep summarises a run on the console. Overridden per command. */
    protected function report(string $shop, array $counts): void
    {
        $this->components->twoColumnDetail($shop, json_encode($counts));
    }

    /** @return list<string> */
    protected function shops(): array
    {
        if ($shop = $this->option('shop')) {
            return [$shop];
        }

        // From the accounts table rather than the sessions table: a shop with
        // no loyalty accounts has nothing to sweep, and a shop that uninstalled
        // still has members whose points must keep behaving correctly.
        return LoyaltyAccount::query()
            ->select('shop_domain')
            ->distinct()
            ->orderBy('shop_domain')
            ->pluck('shop_domain')
            ->all();
    }

    /**
     * The stated "now", where a command offers one.
     *
     * Not every sweep does — the replay command works from order ids and date
     * ranges instead — so this asks before reading rather than assuming every
     * subclass declares the option.
     */
    protected function asOf(): ?DateTimeInterface
    {
        if (! $this->getDefinition()->hasOption('as-of')) {
            return null;
        }

        $value = $this->option('as-of');

        return $value === null || $value === '' ? null : Carbon::parse($value);
    }
}
