<?php

namespace Tests\Feature;

use App\Domain\Rules\RulesVersionRepository;
use App\Models\LoyaltyAccount;
use App\Models\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `loyalty:preflight` exists because a device run was reported as passing when
 * nothing had reached the system. Its job is to be trustworthy, so it is tested
 * for the two things that would make it worse than nothing: reporting a member
 * or a staff role that is not there, and claiming coherence when the install is
 * absent.
 *
 * Run with --no-api throughout: the Shopify calls need a live token, and a
 * diagnostic that cannot run offline is a diagnostic nobody runs.
 */
class PreflightCommandTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'loyalty-system.myshopify.com';

    public function test_it_reports_the_member_and_the_watermark(): void
    {
        app(RulesVersionRepository::class)->seedDefaults(self::SHOP);

        Session::create([
            'session_id' => 'offline_'.self::SHOP,
            'shop' => self::SHOP,
            'state' => '',
            'is_online' => false,
            'scope' => (string) config('shopify.scopes'),
            'access_token' => 'shpca_test',
        ]);

        $account = LoyaltyAccount::create([
            'shop_domain' => self::SHOP,
            'enrolment_channel' => 'pos',
            'enrolled_at' => now(),
            'legacy_card_number' => 'D-118422',
        ]);

        // Artisan::call plus the output buffer rather than expectsOutputToContain:
        // Symfony wraps console output under test, so a line-oriented matcher
        // fails on where the wrap landed rather than on anything real. The
        // buffer is the whole text and can be asserted on directly.
        $code = Artisan::call('loyalty:preflight', [
            '--shop' => self::SHOP,
            '--member' => (string) $account->id,
            '--no-api' => true,
        ]);

        $output = Artisan::output();

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('account '.$account->id, $output);
        $this->assertStringContainsString('D-118422', $output, 'The member line must name the legacy card.');
        // The watermark is the whole point: it must state that nothing has
        // happened yet, rather than omitting the line when there is no data.
        $this->assertStringContainsString('redemptions', $output);
        $this->assertStringContainsString('pos=0', $output);
        $this->assertStringContainsString('WATERMARK', $output);
    }

    /** No install, no coherence: it must fail rather than read green. */
    public function test_it_fails_when_the_app_is_not_installed_on_the_shop(): void
    {
        app(RulesVersionRepository::class)->seedDefaults(self::SHOP);

        $code = Artisan::call('loyalty:preflight', ['--shop' => self::SHOP, '--no-api' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('no stored session', $output);
    }

    /**
     * The scope check must not report a shortfall for a read scope covered by
     * its write counterpart - Shopify collapses the two, so a granted set that
     * lists write_customers without read_customers is complete. Getting this
     * wrong would make every run cry wolf.
     */
    public function test_a_write_scope_covers_its_read_counterpart(): void
    {
        app(RulesVersionRepository::class)->seedDefaults(self::SHOP);

        Session::create([
            'session_id' => 'offline_'.self::SHOP,
            'shop' => self::SHOP,
            'state' => '',
            'is_online' => false,
            // Deliberately omits every read_ scope that has a write_ twin.
            'scope' => 'read_locations,read_markets,read_orders,write_customers,write_discounts,write_products',
            'access_token' => 'shpca_test',
        ]);

        $code = Artisan::call('loyalty:preflight', ['--shop' => self::SHOP, '--no-api' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('declared scopes are covered', $output);
    }
}
