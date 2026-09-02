<?php

namespace Tests\Feature;

use App\Domain\Loyalty\LedgerPosting;
use App\Domain\Loyalty\LedgerService;
use App\Domain\Orders\OrderSource;
use App\Domain\Redemption\DiscountCodeGateway;
use App\Domain\Redemption\DiscountCodeWriter;
use App\Domain\Redemption\MetafieldWriter;
use App\Domain\Redemption\QuoteExpirySweep;
use App\Domain\Redemption\RedemptionGateway;
use App\Domain\Redemption\RedemptionService;
use App\Domain\Rules\RulesVersionRepository;
use App\Models\LedgerEntry;
use App\Models\LoyaltyAccount;
use App\Models\Redemption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeDiscountCodeWriter;
use Tests\Support\FakeMetafieldWriter;
use Tests\Support\FakeOrderSource;
use Tests\TestCase;

/**
 * Online redemption through a single-use discount code (D5, revised
 * 2 Sep 2026).
 *
 * The function path is not gone — it is the Plus-and-above option and keeps its
 * own tests. This covers the mechanism that actually ships, and the emphasis is
 * deliberate: **the constraints Shopify enforces are the ones the app does not
 * re-check**, so if a code is minted with the wrong customer, amount, expiry or
 * usage limit, nothing downstream will notice. Those are asserted from the
 * payload rather than inferred from a call count.
 *
 * There is no gift-card test here on purpose. Shopify excludes gift-card lines
 * from a fixed-amount code itself — proved on paid order `#1001` on the
 * development store, 2 Sep 2026 — so there is no app-level behaviour to assert.
 */
class DiscountCodeRedemptionTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'loyalty-system.myshopify.com';

    private FakeDiscountCodeWriter $codes;

    private FakeMetafieldWriter $metafields;

    private FakeOrderSource $orders;

    protected function setUp(): void
    {
        parent::setUp();

        app(RulesVersionRepository::class)->seedDefaults(self::SHOP);

        $this->codes = new FakeDiscountCodeWriter;
        $this->app->instance(DiscountCodeWriter::class, $this->codes);

        // Faked as well, so a test can prove the function's metafield is NOT
        // written any more rather than merely not looking.
        $this->metafields = new FakeMetafieldWriter;
        $this->app->instance(MetafieldWriter::class, $this->metafields);

        $this->orders = new FakeOrderSource;
        $this->app->instance(OrderSource::class, $this->orders);
    }

    private function member(int $customerId = 5001, int $points = 1000): LoyaltyAccount
    {
        $member = LoyaltyAccount::create([
            'shop_domain' => self::SHOP,
            'shopify_customer_id' => $customerId,
            'email' => 'member'.$customerId.'@example.com',
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

    /** Hold a £50 online quote through whatever the container binds. */
    private function hold(LoyaltyAccount $member): ?Redemption
    {
        return app(RedemptionService::class)->hold(
            account: $member,
            gateway: app(RedemptionGateway::class),
            eligibleSubtotalPence: 6000,
            basketTotalPence: 10000,
            channel: 'online',
        )['redemption'];
    }

    // ------------------------------------------------------------------ the binding

    /** The container decides the online mechanism, and it decides once. */
    public function test_the_bound_online_gateway_is_the_code_gateway(): void
    {
        $this->assertInstanceOf(DiscountCodeGateway::class, app(RedemptionGateway::class));
        $this->assertSame('discount_code', app(RedemptionGateway::class)->mechanism());
    }

    // ------------------------------------------------------------------ minting

    public function test_a_hold_mints_one_code_carrying_every_native_constraint(): void
    {
        $member = $this->member();
        $redemption = $this->hold($member);

        $minted = $this->codes->readCode((string) $redemption->reference);

        $this->assertNotNull($minted);

        // The code IS the reference: the only link between a paid order and the
        // quote behind it.
        $this->assertSame((string) $redemption->reference, $minted['code']);
        $this->assertSame(5000, $minted['amount_pence']);
        $this->assertSame('gid://shopify/Customer/5001', $minted['customer_gid']);
        $this->assertSame('GBP', $minted['currency_code']);

        // Agreed 2 Sep 2026: the code's life is the quote's life, so Shopify and
        // the expiry sweep cannot disagree about whether an offer still stands.
        $this->assertSame($redemption->quote_expires_at->format(DATE_ATOM), $minted['ends_at']);

        $this->assertSame('discount_code', $redemption->discount_mechanism);
        $this->assertSame($minted['node_gid'], $redemption->shopify_discount_gid);

        // One mechanism, not two.
        $this->assertSame([], $this->metafields->written);
    }

    public function test_the_minimum_basket_rule_reaches_the_code(): void
    {
        $rules = app(RulesVersionRepository::class);
        $payload = $rules->current(self::SHOP)->toArray();
        $payload['min_basket_pence'] = 2500;
        $rules->save(self::SHOP, $payload, changeSummary: 'Minimum basket for the test');

        $redemption = $this->hold($this->member());

        $this->assertSame(
            2500,
            $this->codes->readCode((string) $redemption->reference)['minimum_subtotal_pence'],
        );
    }

    /**
     * Publishing twice mints once.
     *
     * A retried request is normal; two live codes for one quote would let a
     * member spend the same points twice, which Shopify's `usageLimit: 1` would
     * not catch because they would be two different codes.
     */
    public function test_publishing_the_same_quote_twice_mints_one_code(): void
    {
        $member = $this->member();
        $redemption = $this->hold($member);

        app(RedemptionGateway::class)->publish($member, $redemption->refresh());

        $this->assertCount(1, $this->codes->created);
    }

    /** No Shopify customer means nobody to bind a code to, and that is quiet. */
    public function test_a_member_with_no_shopify_customer_gets_no_code(): void
    {
        $member = LoyaltyAccount::create([
            'shop_domain' => self::SHOP,
            'shopify_customer_id' => null,
            'email' => null,
            'enrolment_channel' => 'migration',
            'enrolled_at' => now()->subYear(),
        ]);

        app(LedgerService::class)->post($member, LedgerPosting::openingBalance(
            points: 1000,
            idempotencyKey: 'open:'.$member->id,
            occurredAt: now()->subMonths(2),
            expiresAt: now()->addMonths(4),
            reason: 'Migrated balance',
        ));

        $redemption = $this->hold($member->refresh());

        $this->assertSame([], $this->codes->created);
        $this->assertNull($redemption->shopify_discount_gid);
    }

    /**
     * A mint that fails leaves a quote with nothing to apply, and does not throw.
     *
     * The alternative is worse: an exception mid-hold would leave a redemption
     * row that nothing will ever apply or clean up.
     */
    public function test_a_failed_mint_leaves_the_quote_without_a_code(): void
    {
        $this->codes->shouldFail = true;

        $redemption = $this->hold($this->member());

        $this->assertNotNull($redemption, 'The quote is still held.');
        $this->assertNull($redemption->shopify_discount_gid);
        $this->assertSame('quoted', $redemption->state);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'redemption')->count());
    }

    // ------------------------------------------------------------------ withdrawal

    public function test_voiding_a_quote_deactivates_its_code(): void
    {
        $member = $this->member();
        $redemption = $this->hold($member);
        $gid = (string) $redemption->shopify_discount_gid;

        app(RedemptionService::class)->void($member, $redemption, app(RedemptionGateway::class));

        $this->assertTrue($this->codes->wasDeactivated($gid));
        $this->assertSame('void', $redemption->refresh()->state);
    }

    /** The expiry sweep is the only thing that ends an offer early. */
    public function test_the_sweep_deactivates_the_code_of_an_expired_quote(): void
    {
        $member = $this->member();
        $redemption = $this->hold($member);
        $gid = (string) $redemption->shopify_discount_gid;

        $counts = app(QuoteExpirySweep::class)->run(self::SHOP, now()->addHour());

        $this->assertSame(1, $counts['expired']);
        $this->assertTrue($this->codes->wasDeactivated($gid));
        $this->assertSame('void', $redemption->refresh()->state);
    }

    public function test_withdrawing_a_quote_that_never_published_is_safe(): void
    {
        $this->codes->shouldFail = true;

        $member = $this->member();
        $redemption = $this->hold($member);

        app(RedemptionService::class)->void($member, $redemption, app(RedemptionGateway::class));

        $this->assertSame([], $this->codes->deactivated);
        $this->assertSame('void', $redemption->refresh()->state);
    }

    /**
     * A confirmed redemption keeps its code.
     *
     * `usageLimit: 1` has already spent it, and deactivating it afterwards would
     * only strip the discount's record off a paid order.
     */
    public function test_a_confirmed_redemption_keeps_its_code(): void
    {
        $member = $this->member();
        $redemption = $this->hold($member);

        app(RedemptionService::class)->confirm(
            account: $member,
            redemption: $redemption,
            shopifyOrderId: 2001,
        );

        app(RedemptionGateway::class)->withdraw($member, $redemption->refresh());

        $this->assertSame([], $this->codes->deactivated);
    }

    // ------------------------------------------------------------------ end to end

    /**
     * The whole point: a paid order carrying the code spends the points.
     *
     * This is the assertion that made the migration off the function cheap.
     * `AdminApiOrderSource` already selected `DiscountCodeApplication.code` and
     * already fed it to `RedemptionReference`, so making the code equal the
     * reference means `orders/paid` confirms a code redemption with no new
     * plumbing at all.
     */
    public function test_a_paid_order_carrying_the_code_spends_the_points(): void
    {
        $member = $this->member();
        $redemption = $this->hold($member);

        $order = FakeOrderSource::workedExample(
            redemptionReferences: [$redemption->reference],
        );
        $this->orders->give($order);

        $result = app(RedemptionService::class)->confirmFromOrder($member, $order);

        $this->assertSame(1, $result['confirmed']);
        $this->assertSame(1000, $result['points'], '£50 is 1,000 points.');

        $entry = LedgerEntry::query()->where('entry_type', 'redemption')->sole();

        // The ledger entry shape for a completed redemption.
        $this->assertSame(0, (int) $entry->pending_delta);
        $this->assertSame(-1000, (int) $entry->available_delta);
        $this->assertSame('redeem:'.$redemption->reference, $entry->idempotency_key);
        $this->assertSame('online', $entry->channel);
        $this->assertSame((int) $redemption->id, (int) $entry->redemption_id);

        $redemption->refresh();
        $this->assertSame('confirmed', $redemption->state);
        $this->assertSame(1000, (int) $redemption->points_consumed);
        $this->assertNotNull($redemption->confirmed_at);
    }

    /** A redelivered order confirms once, keyed on the reference. */
    public function test_a_redelivered_order_spends_once(): void
    {
        $member = $this->member();
        $redemption = $this->hold($member);

        $order = FakeOrderSource::workedExample(redemptionReferences: [$redemption->reference]);
        $this->orders->give($order);

        app(RedemptionService::class)->confirmFromOrder($member, $order);
        $second = app(RedemptionService::class)->confirmFromOrder($member, $order);

        $this->assertSame(1, $second['replayed']);
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'redemption')->count());
        $this->assertSame(1000, (int) $redemption->refresh()->points_consumed);
    }
}
