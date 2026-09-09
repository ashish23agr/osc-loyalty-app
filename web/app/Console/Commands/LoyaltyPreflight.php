<?php

namespace App\Console\Commands;

use App\Domain\Rules\RulesVersionRepository;
use App\Lib\DevTunnel;
use App\Models\LedgerEntry;
use App\Models\LoyaltyAccount;
use App\Models\Redemption;
use App\Models\Session;
use App\Models\StaffRole;
use App\Models\WebhookEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Shopify\Clients\Graphql;

/**
 * What is true right now, before a device run - and a watermark to check after.
 *
 * Written on 9 Sep 2026, after a device run was reported as having passed end to
 * end when nothing had reached the system at all: no POS redemption row, no
 * ledger movement, no webhook, and no order on Shopify. Six separate checks were
 * needed to establish that, one at a time, after the fact.
 *
 * The watermark is the part that matters and the reason this is a command rather
 * than a paste-in one-liner. Run it BEFORE a device run and again AFTER: if the
 * newest redemption, ledger entry, webhook event and Shopify order are all
 * unchanged, nothing happened, whatever the device appeared to show. A run that
 * cannot move any of those four numbers has not been verified, and no amount of
 * on-screen success changes that.
 *
 * Deliberately read-only. It writes nothing, so it can be run mid-test as often
 * as wanted.
 */
class LoyaltyPreflight extends Command
{
    protected $signature = 'loyalty:preflight
                            {--shop=loyalty-system.myshopify.com : Shop domain to check}
                            {--member=10 : Loyalty account id to report balances for}
                            {--no-api : Skip the Shopify calls and report local state only}';

    protected $description
        = 'Report store, locations, staff roles, rules and a before/after watermark for a device run';

    public function handle(): int
    {
        $shop = (string) $this->option('shop');

        $this->newLine();
        $this->components->info('Pre-flight for '.$shop);

        $ok = true;

        $ok = $this->tunnel() && $ok;
        $ok = $this->session($shop) && $ok;
        $this->staff($shop);
        $this->rules($shop);
        $this->member();
        $ok = $this->worker() && $ok;

        if (! $this->option('no-api')) {
            $ok = $this->store($shop) && $ok;
        }

        $this->watermark($shop);

        $this->newLine();

        if (! $ok) {
            $this->components->error('Something above needs attention before a device run.');

            return self::FAILURE;
        }

        $this->components->info('Local state is coherent. The watermark is what proves a run happened.');

        return self::SUCCESS;
    }

    /** Is there a live tunnel, and is it the one the tile was built against? */
    private function tunnel(): bool
    {
        $host = DevTunnel::host();

        if ($host === null || $host === '') {
            $this->line('  <fg=red>TUNNEL</>    no handshake and no manifest fallback - is `shopify app dev` running?');

            return false;
        }

        $this->line('  <fg=green>TUNNEL</>    '.$host.'  ('.DevTunnel::describe().')');

        // The tile compiles its base URL in, so a mismatch here is the appUrl
        // defect wearing a different hat: every request goes nowhere, quietly.
        $tilePath = base_path('../extensions/loyalty-tile/src/lib/appUrl.js');
        $tileUrl = null;

        if (is_readable($tilePath)) {
            preg_match("/APP_URL = '([^']*)'/", (string) file_get_contents($tilePath), $m);
            $tileUrl = $m[1] ?? null;
        }

        if ($tileUrl === null) {
            $this->line('  <fg=yellow>TILE URL</>  could not read appUrl.js - cannot confirm the tile agrees');

            return true;
        }

        if (rtrim($tileUrl, '/') !== rtrim($host, '/')) {
            $this->line('  <fg=red>TILE URL</>  '.$tileUrl);
            $this->line('             does NOT match the live tunnel. The tile will reach nothing, silently.');

            return false;
        }

        $this->line('  <fg=green>TILE URL</>  matches the live tunnel');

        return true;
    }

    /** Which shop we actually hold a token for, and what it covers. */
    private function session(string $shop): bool
    {
        $session = Session::query()->where('shop', $shop)->first();

        if ($session === null) {
            $this->line('  <fg=red>SESSION</>   no stored session for '.$shop.' - the app is not installed here');

            return false;
        }

        $this->line('  <fg=green>SESSION</>   '.$session->session_id.'  online='.($session->is_online ? 'yes' : 'no'));

        // Shopify collapses read into write, so a stored scope string that omits
        // read_customers while holding write_customers is complete, not short.
        $declared = collect(explode(',', (string) config('shopify.scopes')))
            ->map(fn ($s) => trim($s))->filter()->values();
        $granted = collect(explode(',', (string) $session->scope))
            ->map(fn ($s) => trim($s))->filter();

        $missing = $declared->reject(function (string $scope) use ($granted): bool {
            if ($granted->contains($scope)) {
                return true;
            }

            return str_starts_with($scope, 'read_')
                && $granted->contains('write_'.substr($scope, 5));
        });

        if ($missing->isNotEmpty()) {
            $this->line('  <fg=red>SCOPES</>    granted set is missing: '.$missing->implode(', '));

            return false;
        }

        $this->line('  <fg=green>SCOPES</>    all '.$declared->count().' declared scopes are covered');

        return true;
    }

    /**
     * Who can use the till.
     *
     * V16: only the first staff member on a shop is bootstrapped, and the 403
     * that every other till user gets names no id, so an Administrator cannot
     * learn which id to assign. That is why the ids are printed here.
     */
    private function staff(string $shop): void
    {
        $roles = StaffRole::query()->where('shop_domain', $shop)->get();

        if ($roles->isEmpty()) {
            $this->line('  <fg=yellow>STAFF</>     no roles yet - the first staff member to open the app is bootstrapped as administrator');

            return;
        }

        $this->line('  <fg=green>STAFF</>     '.$roles->count().' role(s):');

        foreach ($roles as $role) {
            $this->line('             id='.$role->shopify_staff_id.'  role='.$role->role);
        }

        $this->line('             <fg=yellow>The POS user must be one of these ids or every till request 403s (V16).</>');
        $this->line('             There is no read_users scope, so an id cannot be resolved to a name from here.');
    }

    private function rules(string $shop): void
    {
        $rules = app(RulesVersionRepository::class)->current($shop);

        $this->line('  <fg=green>RULES</>     v'.$rules->versionId
            .'  currency='.$rules->currency()
            .'  increment='.$this->money($rules->voucherValuePence())
            .'  cap='.$this->money($rules->maxRedemptionPerOrderPence())
            .'  min_basket='.$this->money($rules->minBasketPence())
            .'  threshold='.$rules->voucherThresholdPoints().'pts');
    }

    private function member(): void
    {
        $account = LoyaltyAccount::query()->find((int) $this->option('member'));

        if ($account === null) {
            $this->line('  <fg=yellow>MEMBER</>    account '.$this->option('member').' does not exist');

            return;
        }

        $this->line('  <fg=green>MEMBER</>    account '.$account->id
            .'  available='.$account->points_available.'pts'
            .'  pending='.$account->points_pending.'pts'
            .'  voucher='.$this->money((int) $account->voucher_balance_pence)
            .'  legacy_card='.($account->legacy_card_number ?? 'none'));
    }

    /**
     * A dead worker is one of two silent causes of "the points never arrived".
     * It cannot be proven alive from here, so report the queue depth instead:
     * a backlog that does not drain is the symptom.
     */
    private function worker(): bool
    {
        if (config('queue.default') !== 'database') {
            $this->line('  <fg=yellow>QUEUE</>     connection is '.config('queue.default').' - depth not checked');

            return true;
        }

        $pending = DB::table('jobs')->count();
        $failed = DB::table('failed_jobs')->count();

        $colour = $pending > 0 ? 'yellow' : 'green';
        $this->line('  <fg='.$colour.'>QUEUE</>     pending='.$pending.'  failed='.$failed);

        if ($pending > 0) {
            $this->line('             A depth that does not fall means no worker: `php artisan queue:work --tries=5`.');
        }

        if ($failed > 0) {
            $this->line('             <fg=red>Inspect failures with `php artisan queue:failed`.</>');
        }

        return $failed === 0;
    }

    /** The store itself, and whether any location can host a GBP till. */
    private function store(string $shop): bool
    {
        $session = Session::query()->where('shop', $shop)->first();

        if ($session === null) {
            return false;
        }

        try {
            $body = (new Graphql($shop, $session->access_token))->query(
                '{ shop { name currencyCode billingAddress { countryCodeV2 } } '
                .'locations(first: 20, includeInactive: true) { edges { node { id name isActive '
                .'address { countryCode city } } } } }'
            )->getDecodedBody();
        } catch (\Throwable $e) {
            $this->line('  <fg=red>STORE</>     Admin API call failed: '.$e->getMessage());

            return false;
        }

        if (isset($body['errors'])) {
            $this->line('  <fg=red>STORE</>     '.json_encode($body['errors']));

            return false;
        }

        $shopNode = $body['data']['shop'] ?? [];
        $currency = $shopNode['currencyCode'] ?? '?';
        $country = $shopNode['billingAddress']['countryCodeV2'] ?? '?';

        $this->line('  <fg=green>STORE</>     '.($shopNode['name'] ?? '?')
            .'  currency='.$currency.'  merchant_country='.$country);

        if ($country !== 'GB') {
            $this->line('             <fg=yellow>Merchant establishment is not GB, so tax says nothing about OSC (V12).</>');
        }

        $this->line('  <fg=green>LOCATIONS</>');

        $gb = 0;

        foreach (($body['data']['locations']['edges'] ?? []) as $edge) {
            $node = $edge['node'];
            $code = $node['address']['countryCode'] ?? '?';
            $gb += $code === 'GB' ? 1 : 0;

            $this->line('             '.str_pad((string) $node['name'], 22)
                .' '.$code
                .'  active='.($node['isActive'] ? 'yes' : 'no')
                .'  id='.basename((string) $node['id']));
        }

        // The till is denominated by its LOCATION's market, not by the shop, so
        // a GBP hold needs a GB-addressed location. V18 fails closed without one.
        if ($gb === 0) {
            $this->line('             <fg=red>No GB-addressed location: a POS hold will be refused as till_currency_mismatch (V18).</>');
        } else {
            $this->line('             <fg=green>'.$gb.' GB-addressed location(s) - a GBP till is possible.</>');
        }

        return true;
    }

    /**
     * The four numbers a real device run MUST move.
     *
     * Record them before, compare after. Nothing on a device screen outranks
     * these: if all four are unchanged, the run did not reach the system.
     */
    private function watermark(string $shop): void
    {
        $this->newLine();
        $this->components->info('WATERMARK - re-run after the device test and compare');

        $redemption = Redemption::query()->latest('id')->first();
        $this->line('  redemptions      total='.Redemption::query()->count()
            .'  pos='.Redemption::query()->where('channel', 'pos')->count()
            .'  newest='.($redemption === null
                ? 'none'
                : $redemption->reference.' ('.$redemption->channel.'/'.$redemption->state.', id '.$redemption->id.')'));

        $entry = LedgerEntry::query()->latest('id')->first();
        $this->line('  ledger entries   total='.LedgerEntry::query()->count()
            .'  newest='.($entry === null
                ? 'none'
                : $entry->entry_type.' id '.$entry->id.' at '.$entry->occurred_at));

        $event = WebhookEvent::query()->latest('id')->first();
        $this->line('  webhook events   total='.WebhookEvent::query()->count()
            .'  newest='.($event === null
                ? 'none'
                : $event->topic.' '.$event->state.' at '.$event->received_at.' (UTC)'));

        if (! $this->option('no-api')) {
            $this->line('  shopify orders   '.$this->newestOrder($shop));
        }

        $this->line('  now              '.now()->toIso8601String().' (UTC; the filesystem is +05:30)');
    }

    private function newestOrder(string $shop): string
    {
        $session = Session::query()->where('shop', $shop)->first();

        if ($session === null) {
            return 'no session';
        }

        try {
            $body = (new Graphql($shop, $session->access_token))->query(
                '{ orders(first: 1, sortKey: CREATED_AT, reverse: true) { edges { node { name createdAt '
                .'retailLocation { name } } } } }'
            )->getDecodedBody();
        } catch (\Throwable $e) {
            return 'lookup failed: '.$e->getMessage();
        }

        $node = $body['data']['orders']['edges'][0]['node'] ?? null;

        if ($node === null) {
            return 'none on the store';
        }

        return 'newest='.$node['name'].' at '.$node['createdAt']
            .'  retail='.($node['retailLocation']['name'] ?? 'none (so: not a POS sale)');
    }

    private function money(int $pence): string
    {
        return '£'.number_format($pence / 100, 2);
    }
}
