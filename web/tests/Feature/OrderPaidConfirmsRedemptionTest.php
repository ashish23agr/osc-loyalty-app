<?php

namespace Tests\Feature;

use App\Domain\Loyalty\LedgerPosting;
use App\Domain\Loyalty\LedgerService;
use App\Domain\Orders\OrderSource;
use App\Domain\Redemption\DiscountFunctionGateway;
use App\Domain\Redemption\MetafieldWriter;
use App\Domain\Redemption\RedemptionReference;
use App\Domain\Redemption\RedemptionService;
use App\Domain\Rules\RulesVersionRepository;
use App\Models\AuditEntry;
use App\Models\LedgerEntry;
use App\Models\LoyaltyAccount;
use App\Models\Redemption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeMetafieldWriter;
use Tests\Support\FakeOrderSource;
use Tests\TestCase;

/**
 * The last link in the chain: a paid order spending the points it was quoted
 * (M6, M7, D5, D6).
 *
 * Everything before this was built and tested in isolation — the ladder, the
 * quote, the gateway, the tile. What was missing was the moment the money
 * actually leaves the ledger, and the thing that makes it findable: **the
 * reference on the discount**. There is no other link between an order and the
 * quote that produced it, because at quote time the order did not exist.
 *
 * That is D6's shared identifier being load-bearing rather than decorative.
 */
class OrderPaidConfirmsRedemptionTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'loyalty-system.myshopify.com';

    private FakeOrderSource $orders;

    protected function setUp(): void
    {
        parent::setUp();

        app(RulesVersionRepository::class)->seedDefaults(self::SHOP);

        $this->orders = new FakeOrderSource;
        $this->app->instance(OrderSource::class, $this->orders);
        $this->app->instance(MetafieldWriter::class, new FakeMetafieldWriter);
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

    /** Hold a £50 quote and return it. */
    private function hold(LoyaltyAccount $member): Redemption
    {
        return app(RedemptionService::class)->hold(
            account: $member,
            gateway: app(DiscountFunctionGateway::class),
            eligibleSubtotalPence: 6000,
            basketTotalPence: 10000,
            channel: 'online',
        )['redemption'];
    }

    private function hmac(string $body): string
    {
        return base64_encode(hash_hmac('sha256', $body, config('shopify.api_secret'), true));
    }

    private function deliverPaid(int $orderId, string $webhookId = 'wh-paid-1')
    {
        $body = json_encode(['id' => $orderId]);

        return $this->call('POST', config('shopify.webhooks.path'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SHOPIFY_TOPIC' => 'orders/paid',
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => self::SHOP,
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $this->hmac($body),
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => $webhookId,
        ], $body);
    }

    // ------------------------------------------------------------------ the wiring

    public function test_a_paid_order_carrying_the_reference_spends_the_points(): void
    {
        $member = $this->member();
        $redemption = $this->hold($member);

        $this->orders->give(FakeOrderSource::workedExample(
            redemptionReferences: [$redemption->reference],
        ));

        $this->deliverPaid(1042)->assertOk();

        $entry = LedgerEntry::query()->where('entry_type', 'redemption')->sole();
        $this->assertSame(-1000, $entry->available_delta, '£50 is 1,000 points.');
        $this->assertSame(1042, (int) $entry->shopify_order_id);
        $this->assertSame((int) $redemption->id, (int) $entry->redemption_id);

        $redemption->refresh();
        $this->assertSame('confirmed', $redemption->state);
        $this->assertSame(1000, $redemption->points_consumed);
        $this->assertSame(1042, (int) $redemption->shopify_order_id);
        $this->assertNotNull($redemption->confirmed_at);

        // Earned and spent on the same order, and both are on the member.
        $member->refresh();
        $this->assertSame(80, $member->points_pending, 'The order still earned.');
        $this->assertSame(0, $member->points_available, '1,000 held, 1,000 spent.');
    }

    /** An ordinary order with no voucher confirms nothing and is not an error. */
    public function test_an_order_with_no_reference_confirms_nothing(): void
    {
        $member = $this->member();
        $this->hold($member);

        $this->orders->give(FakeOrderSource::workedExample());

        $this->deliverPaid(1042)->assertOk();

        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'redemption')->count());
        $this->assertSame('quoted', Redemption::query()->sole()->state);
        $this->assertSame(80, $member->refresh()->points_pending);
    }

    /** A redelivered orders/paid spends once. */
    public function test_a_redelivered_order_spends_once(): void
    {
        $member = $this->member();
        $redemption = $this->hold($member);

        $this->orders->give(FakeOrderSource::workedExample(
            redemptionReferences: [$redemption->reference],
        ));

        $this->deliverPaid(1042, 'wh-1')->assertOk();
        // A different delivery for the same order — the outer guard lets this
        // through, so the ledger's own key is what has to catch it.
        $this->deliverPaid(1042, 'wh-2')->assertOk();

        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'redemption')->count());
        $this->assertSame(0, $member->refresh()->points_available, 'Not minus one thousand.');
    }

    /** C12: the redemption side of the order is audited once, by reference. */
    public function test_confirmation_writes_one_audit_entry_naming_the_reference(): void
    {
        $member = $this->member();
        $redemption = $this->hold($member);

        $this->orders->give(FakeOrderSource::workedExample(
            redemptionReferences: [$redemption->reference],
        ));

        $this->deliverPaid(1042)->assertOk();

        $entry = AuditEntry::query()->where('action', 'redemption.confirmed')->sole();

        $this->assertSame('system', $entry->actor_type);
        $this->assertSame([$redemption->reference], $entry->after_state['references']);
        $this->assertSame(1000, $entry->after_state['points_consumed']);
        $this->assertSame(1042, $entry->after_state['shopify_order_id']);
    }

    // -------------------------------------------------------------- the guards

    /**
     * A reference copied onto someone else's order must not spend their points.
     *
     * The only place this could happen, and the only thing standing in the way
     * is this check — the reference is printed on a receipt, so it is not a
     * secret.
     */
    public function test_a_reference_on_another_members_order_is_refused(): void
    {
        $holder = $this->member(customerId: 5001);
        $redemption = $this->hold($holder);

        $other = $this->member(customerId: 6002);

        // The other member's order, carrying the first member's reference.
        $this->orders->give(FakeOrderSource::workedExample(
            orderId: 2042,
            customerId: 6002,
            redemptionReferences: [$redemption->reference],
        ));

        $this->deliverPaid(2042)->assertOk();

        $this->assertSame(
            0,
            LedgerEntry::query()->where('entry_type', 'redemption')->count(),
            'Nobody\'s points were spent.',
        );
        $this->assertSame('quoted', $redemption->refresh()->state);
        $this->assertSame(1000, $holder->refresh()->points_available, 'The holder is untouched.');
        $this->assertSame(1000, $other->refresh()->points_available);
    }

    /** A voided quote that still reached the order does not spend. */
    public function test_a_voided_redemption_on_a_paid_order_does_not_spend(): void
    {
        $member = $this->member();
        $redemption = $this->hold($member);

        app(RedemptionService::class)->void($member, $redemption, app(DiscountFunctionGateway::class));

        $this->orders->give(FakeOrderSource::workedExample(
            redemptionReferences: [$redemption->reference],
        ));

        $this->deliverPaid(1042)->assertOk();

        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'redemption')->count());
        $this->assertSame('void', $redemption->refresh()->state);
        $this->assertSame(1000, $member->refresh()->points_available);
    }

    /** A reference-shaped string this app never issued is ignored safely. */
    public function test_an_unknown_reference_is_ignored(): void
    {
        $member = $this->member();

        $this->orders->give(FakeOrderSource::workedExample(
            redemptionReferences: ['PC-99999999'],
        ));

        $this->deliverPaid(1042)->assertOk();

        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'redemption')->count());
        $this->assertSame(80, $member->refresh()->points_pending, 'Earning still happened.');
    }

    /** Two vouchers on one order both confirm. */
    public function test_two_references_on_one_order_both_confirm(): void
    {
        $member = $this->member(points: 2000);

        $first = $this->hold($member);
        // The second quote is against what is left; hold() re-reads the balance.
        $second = app(RedemptionService::class)->hold(
            account: $member->refresh(),
            gateway: app(DiscountFunctionGateway::class),
            eligibleSubtotalPence: 6000,
            basketTotalPence: 10000,
            channel: 'online',
        )['redemption'];

        $this->orders->give(FakeOrderSource::workedExample(
            redemptionReferences: [$first->reference, $second->reference],
        ));

        $this->deliverPaid(1042)->assertOk();

        $this->assertSame(2, LedgerEntry::query()->where('entry_type', 'redemption')->count());
        $this->assertSame('confirmed', $first->refresh()->state);
        $this->assertSame('confirmed', $second->refresh()->state);
    }

    // -------------------------------------------------------- the reference format

    public function test_a_reference_is_read_back_out_of_a_discount_title(): void
    {
        $reference = RedemptionReference::generate();

        $this->assertSame(
            [$reference],
            RedemptionReference::extractAll('Privilege Club £50 ('.$reference.')'),
            'The title the function and the till both write.',
        );
    }

    public function test_extraction_is_strict_about_the_shape(): void
    {
        $this->assertSame([], RedemptionReference::extractAll('Privilege Club £50'));
        $this->assertSame([], RedemptionReference::extractAll('PC-123'), 'Too short.');
        $this->assertSame([], RedemptionReference::extractAll('PC-ABCDEFGH'), 'Digits only.');
        $this->assertSame([], RedemptionReference::extractAll(null));
    }

    public function test_a_reference_appearing_twice_is_read_once(): void
    {
        $reference = RedemptionReference::generate();

        $this->assertSame(
            [$reference],
            RedemptionReference::extractFromAll([$reference, 'Voucher '.$reference, null]),
        );
    }
}
