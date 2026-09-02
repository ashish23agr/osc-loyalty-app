<?php

namespace App\Console\Commands;

use App\Domain\Redemption\QuoteExpirySweep;

/**
 * Ends redemption quotes nobody paid for.
 *
 * Runs often, because a quote is short-lived by design: a twenty minute quote
 * swept every five minutes lives at most twenty-five, which is close enough to
 * what the member was told. The query is indexed on
 * (shop_domain, state, quote_expires_at), so a frequent run is cheap.
 */
class LoyaltyExpireQuotes extends ShopScopedCommand
{
    protected $signature = 'loyalty:expire-quotes
                            {--shop= : Limit to one shop domain}
                            {--as-of= : Treat this datetime as now}
                            {--chunk=200 : Quotes per chunk}';

    protected $description = 'Void redemption quotes that were never paid for, and withdraw what they published';

    public function handle(QuoteExpirySweep $sweep): int
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
            $counts['expired'].' quote(s) voided · '.$counts['accounts'].' member(s)'
            .($counts['withdrawn'] > 0 ? ' · '.$counts['withdrawn'].' entitlement(s) withdrawn' : ''),
        );
    }
}
