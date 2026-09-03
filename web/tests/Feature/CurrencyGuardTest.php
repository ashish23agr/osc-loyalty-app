<?php

namespace Tests\Feature;

use App\Domain\Loyalty\LedgerPosting;
use App\Domain\Loyalty\LedgerService;
use App\Domain\Redemption\AdminApiDiscountCodeWriter;
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
 * The shop currency and the programme currency must agree before anything is
 * minted (V13).
 *
 * **What actually happened, on the development store, 3 Sep 2026.** The store's
 * base currency changed from GBP to EUR while markets were being configured.
 * `AdminApiDiscountCodeWriter` takes its `currencyCode` from
 * `RuleSet::currency()` and sends it to Shopify; Shopify accepts that field and
 * then denominates the amount in the **shop's** currency instead. So a
 * programme denominated in pounds minted a **€50** voucher, `ACTIVE`, bound to
 * the right customer, with the right usage limit and the right expiry — every
 * constraint correct except the money. `pointsFor()` is currency-blind, so
 * confirming it would have spent the full 1000 points for it.
 *
 * The tests below are written from that incident rather than from the abstract
 * rule, which is why they assert on the absence of a row and the absence of a
 * mint rather than only on a returned reason.
 */
class CurrencyGuardTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'loyalty-system.myshopify.com';

    private FakeDiscountCodeWriter $codes;

    private FakeShopCurrency $shopCurrency;

    protected function setUp(): void
    {
        parent::setUp();

        app(RulesVersionRepository::class)->seedDefaults(self::SHOP);

        $this->codes = new FakeDiscountCodeWriter;
        $this->app->instance(DiscountCodeWriter::class, $this->codes);

        // Replaces the GBP default the base TestCase binds, so each test states
        // the shop currency it is about.
        $this->shopCurrency = new FakeShopCurrency('GBP');
        $this->app->instance(ShopCurrency::class, $this->shopCurrency);
    }

    private function member(int $points = 1000): LoyaltyAccount
    {
        $member = LoyaltyAccount::create([
            'shop_domain' => self::SHOP,
            'shopify_customer_id' => 5001,
            'email' => 'member5001@example.com',
            'enrolment_channel' => 'online',
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
    private function hold(LoyaltyAccount $member, string $channel = 'online'): array
    {
        return app(RedemptionService::class)->hold(
            account: $member,
            gateway: $channel === 'pos'
                ? app(PosCartDiscountGateway::class)
                : app(RedemptionGateway::class),
            eligibleSubtotalPence: 6000,
            basketTotalPence: 10000,
            channel: $channel,
            // V18 is checked before this file's subject, so a POS hold with no
            // till currency would be refused for the WRONG reason and these
            // tests would stop reaching the shop-versus-rules comparison they
            // are about. Stated so they do. See TillCurrencyGuardTest.
            tillCurrency: 'GBP',
        );
    }

    // ------------------------------------------------------------------ online

    public function test_a_mismatch_refuses_the_hold_and_writes_no_row(): void
    {
        $this->shopCurrency->set('EUR');

        $result = $this->hold($this->member());

        $this->assertNull($result['redemption']);
        $this->assertSame(RedemptionService::CURRENCY_MISMATCH, $result['reason']);

        // The quote figures are still returned, so a caller can explain what
        // would have been offered. Nothing was written and nothing was minted.
        $this->assertSame(5000, $result['quote']['redeemable_pence']);
        $this->assertSame(0, Redemption::count());
        $this->assertSame([], $this->codes->created);
    }

    public function test_an_unreadable_shop_currency_also_refuses_but_says_so_differently(): void
    {
        $this->shopCurrency->set(null);

        $result = $this->hold($this->member());

        $this->assertNull($result['redemption']);
        $this->assertSame(RedemptionService::CURRENCY_UNKNOWN, $result['reason']);
        $this->assertSame(0, Redemption::count());
        $this->assertSame([], $this->codes->created);
    }

    public function test_agreement_still_mints_exactly_as_before(): void
    {
        $result = $this->hold($this->member());

        $this->assertNotNull($result['redemption']);
        $this->assertNull($result['reason']);
        $this->assertSame(1, Redemption::count());
        $this->assertCount(1, $this->codes->created);
    }

    /** Case is Shopify's to choose, not a reason to refuse a valid programme. */
    public function test_currency_comparison_ignores_case(): void
    {
        $this->shopCurrency->set('gbp');

        $this->assertNotNull($this->hold($this->member())['redemption']);
    }

    // ------------------------------------------------------------------ POS

    /**
     * The till is covered by the same guard, and this asserts it rather than
     * assuming the online case generalises.
     *
     * `PosCartDiscountGateway::publish()` is deliberately a no-op — the tile
     * applies the discount itself — so there is no mint to intercept on that
     * path. The protection has to be that `hold()` never returns a redemption
     * for the tile to apply, which is what this checks.
     */
    public function test_a_mismatch_refuses_a_pos_hold_too(): void
    {
        $this->shopCurrency->set('EUR');

        $result = $this->hold($this->member(), 'pos');

        $this->assertNull($result['redemption']);
        $this->assertSame(RedemptionService::CURRENCY_MISMATCH, $result['reason']);
        $this->assertSame(0, Redemption::count());
    }

    public function test_a_pos_hold_still_works_when_the_currencies_agree(): void
    {
        $result = $this->hold($this->member(), 'pos');

        $this->assertNotNull($result['redemption']);
        $this->assertSame('pos_cart_discount', $result['redemption']->discount_mechanism);
    }

    // ------------------------------------------------------------------ the writer

    /**
     * The writer refuses independently of `hold()`.
     *
     * Defence in depth: this is the method that puts money in a customer's
     * hands, and a future caller reaching it without going through `hold()`
     * would reintroduce V13 in full. Uses the real writer rather than the fake,
     * with the Admin API never reached because the guard returns first.
     */
    public function test_the_writer_refuses_a_mismatch_on_its_own(): void
    {
        $this->shopCurrency->set('EUR');

        $gid = app(AdminApiDiscountCodeWriter::class)->create(
            shopDomain: self::SHOP,
            code: 'PC-00000001',
            amountPence: 5000,
            currencyCode: 'GBP',
            customerGid: 'gid://shopify/Customer/5001',
            endsAt: now()->addMinutes(20),
            minimumSubtotalPence: 0,
        );

        $this->assertNull($gid);
    }

    // ------------------------------------------------------------------ not cached

    /**
     * The reader is consulted again on the second mint.
     *
     * This is the point of reading live: a cache would have held `GBP` through
     * the exact window in which the store became `EUR`, so the guard would have
     * passed while being wrong. That is worse than no guard, because it would
     * carry the authority of having checked — so it is asserted, not commented.
     */
    public function test_the_shop_currency_is_read_on_every_hold_rather_than_cached(): void
    {
        $member = $this->member(2000);

        $this->hold($member);
        $this->assertNotSame([], $this->shopCurrency->asked);

        $afterFirst = count($this->shopCurrency->asked);

        $this->shopCurrency->set('EUR');
        $second = $this->hold($member->refresh());

        $this->assertGreaterThan($afterFirst, count($this->shopCurrency->asked));
        $this->assertNull($second['redemption'], 'A cached GBP would have let this mint.');
        $this->assertSame(RedemptionService::CURRENCY_MISMATCH, $second['reason']);
    }
}
