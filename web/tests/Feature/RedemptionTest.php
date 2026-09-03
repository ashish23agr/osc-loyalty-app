<?php

namespace Tests\Feature;

use App\Domain\Loyalty\LedgerPosting;
use App\Domain\Loyalty\LedgerService;
use App\Domain\Loyalty\RedemptionLadder;
use App\Domain\Redemption\DiscountFunctionGateway;
use App\Domain\Redemption\MetafieldWriter;
use App\Domain\Redemption\RedemptionService;
use App\Domain\Rules\RulesVersionRepository;
use App\Models\LedgerEntry;
use App\Models\LotAllocation;
use App\Models\LoyaltyAccount;
use App\Models\Redemption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeMetafieldWriter;
use Tests\TestCase;

/**
 * Online redemption end to end: quote, publish, confirm (D5, D8, M6).
 *
 * The ladder arithmetic itself is held by the shared fixtures, on both sides.
 * What this covers is everything around it — when points actually move, what
 * the discount function is given to read, and that a quote nobody pays for
 * costs the member nothing.
 */
class RedemptionTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'loyalty-system.myshopify.com';

    private RedemptionService $redemptions;

    private DiscountFunctionGateway $gateway;

    private FakeMetafieldWriter $metafields;

    protected function setUp(): void
    {
        parent::setUp();

        app(RulesVersionRepository::class)->seedDefaults(self::SHOP);

        $this->metafields = new FakeMetafieldWriter;
        $this->app->instance(MetafieldWriter::class, $this->metafields);

        $this->redemptions = app(RedemptionService::class);
        $this->gateway = app(DiscountFunctionGateway::class);
    }

    /** A member holding £50 of voucher value: 1,000 available points. */
    private function member(int $availablePoints = 1000, int $customerId = 5001): LoyaltyAccount
    {
        $member = LoyaltyAccount::create([
            'shop_domain' => self::SHOP,
            'shopify_customer_id' => $customerId,
            'email' => 'member'.$customerId.'@example.com',
            'enrolment_channel' => 'online',
            'enrolled_at' => now()->subYear(),
        ]);

        if ($availablePoints > 0) {
            app(LedgerService::class)->post($member, LedgerPosting::openingBalance(
                points: $availablePoints,
                idempotencyKey: 'open:'.$member->id,
                occurredAt: now()->subMonths(2),
                expiresAt: now()->addMonths(4),
                reason: 'Migrated balance',
            ));
        }

        return $member->refresh();
    }

    // ------------------------------------------------------------------ quote

    /** The Blueprint worked example, through the service. */
    public function test_a_quote_runs_the_ladder_and_writes_nothing(): void
    {
        $member = $this->member();

        $quote = $this->redemptions->quote($member, eligibleSubtotalPence: 6000, basketTotalPence: 10000);

        $this->assertSame(5000, $quote['redeemable_pence']);
        $this->assertNull($quote['reason']);
        $this->assertSame(5000, $quote['voucher_balance_pence']);
        $this->assertSame(1000, $quote['points_for_amount'], '£50 is 1,000 points at the launch rate.');

        $this->assertSame(0, Redemption::query()->count(), 'A quote creates nothing.');
        $this->assertSame(1, LedgerEntry::query()->count(), 'And moves nothing.');
    }

    /** The agreed UI's rejection example, through the service. */
    public function test_a_quote_offers_only_what_qualifies(): void
    {
        $member = $this->member();

        $quote = $this->redemptions->quote($member, eligibleSubtotalPence: 4500, basketTotalPence: 7000);

        $this->assertSame(4500, $quote['redeemable_pence'], 'Offers 45, not the 50 held.');
        $this->assertSame('eligible_subtotal', $quote['binding_rung'], 'And can say which rung bound it.');
    }

    public function test_a_quote_explains_a_refusal(): void
    {
        $member = $this->member(availablePoints: 0);

        $quote = $this->redemptions->quote($member, eligibleSubtotalPence: 6000, basketTotalPence: 10000);

        $this->assertSame(0, $quote['redeemable_pence']);
        $this->assertSame(RedemptionLadder::NO_VOUCHER_BALANCE, $quote['reason']);
    }

    // ------------------------------------------------------------------- hold

    public function test_holding_a_quote_publishes_what_the_function_will_read(): void
    {
        $member = $this->member();

        $held = $this->redemptions->hold(
            account: $member,
            gateway: $this->gateway,
            eligibleSubtotalPence: 6000,
            basketTotalPence: 10000,
            channel: 'online',
        );

        $redemption = $held['redemption'];

        $this->assertNotNull($redemption);
        $this->assertSame('quoted', $redemption->state);
        $this->assertSame(5000, $redemption->amount_pence);
        $this->assertSame(0, $redemption->points_consumed, 'Nothing is spent until an order is paid.');
        $this->assertSame('function', $redemption->discount_mechanism);
        $this->assertNotNull($redemption->quote_expires_at);

        // What the discount function reads at cart time (V4).
        $published = $this->metafields->readFor(self::SHOP, 5001);

        $this->assertNotNull($published, 'Nothing published means no discount offered.');
        $this->assertSame(5000, $published['voucher_balance_pence']);
        $this->assertSame(500, $published['voucher_value_pence'], 'The rules travel with it.');
        $this->assertSame(5000, $published['max_redemption_per_order_pence']);
        $this->assertSame(0, $published['min_basket_pence']);
        $this->assertSame($redemption->reference, $published['reference']);
    }

    /**
     * The quote is published, not the whole balance.
     *
     * A member holding £50 who is offered £20 against this basket must not have
     * £50 sitting in a metafield the function would happily give away.
     */
    public function test_only_the_quoted_amount_is_published_not_the_balance(): void
    {
        $member = $this->member();

        $this->redemptions->hold(
            account: $member,
            gateway: $this->gateway,
            eligibleSubtotalPence: 2000,
            basketTotalPence: 10000,
            channel: 'online',
        );

        $published = $this->metafields->readFor(self::SHOP, 5001);

        $this->assertSame(2000, $published['voucher_balance_pence'], 'The quote, not the £50 held.');
    }

    /** C1: a member may ask for less, and only in whole increments. */
    public function test_a_member_may_ask_for_less_than_the_ladder_allows(): void
    {
        $member = $this->member();

        $held = $this->redemptions->hold(
            account: $member,
            gateway: $this->gateway,
            eligibleSubtotalPence: 6000,
            basketTotalPence: 10000,
            channel: 'online',
            requestedPence: 1700,
        );

        $this->assertSame(1500, $held['redemption']->amount_pence, '£17 rounds down to £15.');
    }

    /** And asking for more than the ladder allows gets the ladder's answer. */
    public function test_asking_for_more_than_the_ladder_allows_changes_nothing(): void
    {
        $member = $this->member();

        $held = $this->redemptions->hold(
            account: $member,
            gateway: $this->gateway,
            eligibleSubtotalPence: 2000,
            basketTotalPence: 10000,
            channel: 'online',
            requestedPence: 999999,
        );

        $this->assertSame(2000, $held['redemption']->amount_pence);
    }

    public function test_a_refused_quote_holds_nothing_and_publishes_nothing(): void
    {
        $member = $this->member(availablePoints: 0);

        $held = $this->redemptions->hold(
            account: $member,
            gateway: $this->gateway,
            eligibleSubtotalPence: 6000,
            basketTotalPence: 10000,
            channel: 'online',
        );

        $this->assertNull($held['redemption']);
        $this->assertSame(RedemptionLadder::NO_VOUCHER_BALANCE, $held['reason']);
        $this->assertSame(0, Redemption::query()->count());
        $this->assertNull($this->metafields->readFor(self::SHOP, 5001));
    }

    // ---------------------------------------------------------------- confirm

    public function test_confirming_spends_the_points_through_the_ledger(): void
    {
        $member = $this->member();

        $redemption = $this->redemptions->hold(
            $member, $this->gateway, 6000, 10000, 'online',
        )['redemption'];

        $result = $this->redemptions->confirm($member, $redemption, shopifyOrderId: 1042, orderName: '#1042');

        $this->assertSame(1000, $result['points'], '£50 is 1,000 points.');
        $this->assertFalse($result['replayed']);

        $entry = LedgerEntry::query()->where('entry_type', 'redemption')->sole();
        $this->assertSame(-1000, $entry->available_delta);
        $this->assertSame(0, $entry->pending_delta, 'Pending points are not redeemable.');
        $this->assertSame(1042, (int) $entry->shopify_order_id);
        $this->assertSame((int) $redemption->id, (int) $entry->redemption_id);

        $member->refresh();
        $this->assertSame(0, $member->points_available);
        $this->assertSame(0, $member->voucher_balance_pence);

        $redemption->refresh();
        $this->assertSame('confirmed', $redemption->state);
        $this->assertSame(1000, $redemption->points_consumed);
        $this->assertNotNull($redemption->confirmed_at);
    }

    /** FIFO across lots, through the Sprint 1 allocator, so expiry still works. */
    public function test_a_redemption_draws_from_the_oldest_lot_first(): void
    {
        $member = $this->member(availablePoints: 0);
        $ledger = app(LedgerService::class);

        $older = $ledger->post($member, LedgerPosting::openingBalance(
            points: 600, idempotencyKey: 'open:old', occurredAt: now()->subMonths(5),
            expiresAt: now()->addMonth(), reason: 'Older balance',
        ));
        $newer = $ledger->post($member, LedgerPosting::openingBalance(
            points: 600, idempotencyKey: 'open:new', occurredAt: now()->subMonth(),
            expiresAt: now()->addMonths(5), reason: 'Newer balance',
        ));

        $redemption = $this->redemptions->hold(
            $member->refresh(), $this->gateway, 6000, 10000, 'online',
        )['redemption'];

        $this->redemptions->confirm($member, $redemption, 1042);

        $entry = LedgerEntry::query()->where('entry_type', 'redemption')->sole();

        $this->assertSame(
            600,
            (int) LotAllocation::query()->where('consuming_entry_id', $entry->id)
                ->where('lot_entry_id', $older->id)->sum('points'),
            'The older lot is drained first.',
        );
        $this->assertSame(
            400,
            (int) LotAllocation::query()->where('consuming_entry_id', $entry->id)
                ->where('lot_entry_id', $newer->id)->sum('points'),
        );
    }

    /** A redelivered orders/paid confirms once. */
    public function test_confirming_twice_spends_once(): void
    {
        $member = $this->member();

        $redemption = $this->redemptions->hold(
            $member, $this->gateway, 6000, 10000, 'online',
        )['redemption'];

        $this->redemptions->confirm($member, $redemption, 1042);
        $second = $this->redemptions->confirm($member, $redemption->refresh(), 1042);

        $this->assertTrue($second['replayed']);
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'redemption')->count());

        $member->refresh();
        $this->assertSame(0, $member->points_available, 'Not minus one thousand.');
    }

    // ------------------------------------------------------------------- void

    public function test_voiding_a_quote_costs_the_member_nothing(): void
    {
        $member = $this->member();

        $redemption = $this->redemptions->hold(
            $member, $this->gateway, 6000, 10000, 'online',
        )['redemption'];

        $this->redemptions->void($member, $redemption, $this->gateway);

        $redemption->refresh();
        $this->assertSame('void', $redemption->state);
        $this->assertSame(0, $redemption->points_consumed);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'redemption')->count());

        $member->refresh();
        $this->assertSame(1000, $member->points_available, 'The balance is untouched.');

        // And the function can no longer read an entitlement.
        $this->assertNull($this->metafields->readFor(self::SHOP, 5001));
    }

    public function test_a_confirmed_redemption_cannot_be_voided(): void
    {
        $member = $this->member();

        $redemption = $this->redemptions->hold(
            $member, $this->gateway, 6000, 10000, 'online',
        )['redemption'];

        $this->redemptions->confirm($member, $redemption, 1042);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/refund/');

        $this->redemptions->void($member, $redemption->refresh(), $this->gateway);
    }

    // ---------------------------------------------------------------- gateway

    /** D5: the mechanism stays behind the interface. */
    public function test_the_gateway_names_the_mechanism_the_schema_records(): void
    {
        $this->assertSame('function', $this->gateway->mechanism());
    }

    /** A member with no Shopify customer cannot check out online. */
    public function test_publishing_is_skipped_for_a_member_with_no_shopify_customer(): void
    {
        $member = LoyaltyAccount::create([
            'shop_domain' => self::SHOP,
            'enrolment_channel' => 'pos',
            'enrolled_at' => now(),
            'legacy_card_number' => 'D-118422',
        ]);

        app(LedgerService::class)->post($member, LedgerPosting::openingBalance(
            points: 1000, idempotencyKey: 'open:pos', occurredAt: now()->subMonth(),
            expiresAt: now()->addMonths(5), reason: 'Migrated balance',
        ));

        $held = $this->redemptions->hold(
            $member->refresh(), $this->gateway, 6000, 10000, 'pos',
            tillCurrency: 'GBP',
        );

        $this->assertNotNull($held['redemption'], 'The quote still stands at the till.');
        $this->assertSame([], $this->metafields->written, 'There is nowhere to publish to.');
    }
}
