<?php

namespace Tests\Feature;

use App\Domain\Loyalty\LedgerPosting;
use App\Domain\Loyalty\LedgerService;
use App\Domain\Redemption\DiscountCodeWriter;
use App\Domain\Redemption\PosCartDiscountGateway;
use App\Domain\Redemption\RedemptionGateway;
use App\Domain\Redemption\RedemptionService;
use App\Domain\Rules\RulesVersionRepository;
use App\Domain\Shop\ShopCurrency;
use App\Models\LoyaltyAccount;
use App\Models\Redemption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeDiscountCodeWriter;
use Tests\Support\FakeShopCurrency;
use Tests\TestCase;

/**
 * A POS redemption must be denominated in the currency the TILL transacts in
 * (V18).
 *
 * **Why the V13 guard was not enough, which is the whole point of this file.**
 * V13 compares the SHOP currency with the rules currency. For OSC both are GBP,
 * so it passes — and a till still transacts in the currency of its *location*.
 * On the development store, "Shop location" is in the United States and its
 * session reports `USD` while the shop stays GBP. Observed on a live Android
 * device on 3 Sep 2026 as `cur=USD`.
 *
 * `applyCartDiscount` takes a bare number and lets the till denominate it,
 * exactly as `discountCodeBasicCreate` lets the shop denominate. So 1000 points
 * priced at £50 would have discounted **$50** and printed **"£50.00"** on the
 * receipt: wrong money, and a receipt contradicting the sale it is printed on.
 * Nothing in the app could have noticed, because nothing in the app read the
 * till's currency — `session.currency` was never referenced anywhere in the
 * tile.
 */
class TillCurrencyGuardTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'loyalty-system.myshopify.com';

    private FakeShopCurrency $shopCurrency;

    protected function setUp(): void
    {
        parent::setUp();

        app(RulesVersionRepository::class)->seedDefaults(self::SHOP);

        $this->app->instance(DiscountCodeWriter::class, new FakeDiscountCodeWriter);

        // The shop agrees with the rules throughout: this file is about the
        // till, and leaving V13's comparison satisfied is what isolates it.
        $this->shopCurrency = new FakeShopCurrency('GBP');
        $this->app->instance(ShopCurrency::class, $this->shopCurrency);
    }

    private function member(int $points = 2000): LoyaltyAccount
    {
        $member = LoyaltyAccount::create([
            'shop_domain' => self::SHOP,
            'shopify_customer_id' => 5001,
            'email' => 'member5001@example.com',
            'enrolment_channel' => 'pos',
            'enrolled_at' => now()->subYear(),
        ]);

        app(LedgerService::class)->post($member, LedgerPosting::openingBalance(
            points: $points,
            idempotencyKey: 'open:'.$member->id,
            occurredAt: now()->subMonths(2),
            expiresAt: now()->addMonths(4),
            reason: 'Migrated balance',
        ));

        return $member->refresh();
    }

    /** @return array<string, mixed> */
    private function holdAtTill(?string $tillCurrency, string $channel = 'pos'): array
    {
        return app(RedemptionService::class)->hold(
            account: $this->member(),
            gateway: $channel === 'pos'
                ? app(PosCartDiscountGateway::class)
                : app(RedemptionGateway::class),
            eligibleSubtotalPence: 6000,
            basketTotalPence: 10000,
            channel: $channel,
            shopifyLocationId: 95318016240,
            tillCurrency: $tillCurrency,
        );
    }

    /**
     * The exact situation on the development store: US till, GBP programme.
     *
     * `95318016240` is the real Admin API id of "Shop location", and `USD` is
     * what that till reported on the device.
     */
    public function test_a_us_till_cannot_redeem_a_sterling_voucher(): void
    {
        $result = $this->holdAtTill('USD');

        $this->assertNull($result['redemption']);
        $this->assertSame(RedemptionService::TILL_CURRENCY_MISMATCH, $result['reason']);

        // Nothing written, so there is nothing for the tile to apply and nothing
        // for the expiry sweep to reason about.
        $this->assertSame(0, Redemption::count());

        // And the quote figures survive, so the till can still say what would
        // have been offered.
        $this->assertSame(5000, $result['quote']['redeemable_pence']);
    }

    public function test_the_shop_guard_alone_would_have_let_it_through(): void
    {
        // V13's comparison is satisfied - this is the gap V18 closes, asserted
        // rather than described.
        $this->assertSame('GBP', $this->shopCurrency->for(self::SHOP));

        $this->assertSame(
            RedemptionService::TILL_CURRENCY_MISMATCH,
            $this->holdAtTill('USD')['reason'],
        );
    }

    public function test_a_till_that_does_not_say_is_refused_rather_than_trusted(): void
    {
        $result = $this->holdAtTill(null);

        $this->assertNull($result['redemption']);
        $this->assertSame(RedemptionService::TILL_CURRENCY_UNKNOWN, $result['reason']);
        $this->assertSame(0, Redemption::count());
    }

    public function test_an_empty_string_is_not_a_currency(): void
    {
        $this->assertSame(
            RedemptionService::TILL_CURRENCY_UNKNOWN,
            $this->holdAtTill('')['reason'],
        );
    }

    public function test_a_sterling_till_redeems_normally(): void
    {
        $result = $this->holdAtTill('GBP');

        $this->assertNotNull($result['redemption']);
        $this->assertNull($result['reason']);
        $this->assertSame('pos_cart_discount', $result['redemption']->discount_mechanism);
        $this->assertSame(95318016240, (int) $result['redemption']->shopify_location_id);
    }

    /** Case is the platform's to choose, not a reason to refuse a valid till. */
    public function test_currency_comparison_ignores_case(): void
    {
        $this->assertNotNull($this->holdAtTill('gbp')['redemption']);
    }

    /**
     * Online is unaffected, and must be.
     *
     * There is no till in an online checkout, so requiring a till currency there
     * would refuse every web redemption. The guard is scoped to the POS channel
     * for that reason, and this is the assertion that keeps it scoped.
     */
    public function test_an_online_hold_needs_no_till_currency(): void
    {
        $result = $this->holdAtTill(null, 'online');

        $this->assertNotNull($result['redemption']);
        $this->assertNull($result['reason']);
        $this->assertSame('discount_code', $result['redemption']->discount_mechanism);
    }

    public function test_the_endpoint_passes_the_till_currency_through(): void
    {
        // Guards against the field being accepted by validation and then
        // dropped before it reaches hold() - which would restore the defect
        // while every test above still passed.
        $reflection = new \ReflectionMethod(RedemptionService::class, 'hold');
        $names = array_map(fn ($p) => $p->getName(), $reflection->getParameters());

        $this->assertContains('tillCurrency', $names);

        $controller = file_get_contents(
            base_path('app/Http/Controllers/Api/Admin/RedemptionController.php'),
        );

        $this->assertStringContainsString("'till_currency'", $controller);
        $this->assertStringContainsString('tillCurrency:', $controller);
    }
}
