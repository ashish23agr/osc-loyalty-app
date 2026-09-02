<?php

namespace Tests\Feature;

use App\Domain\Loyalty\LedgerPosting;
use App\Domain\Loyalty\LedgerService;
use App\Domain\Orders\OrderSnapshot;
use App\Domain\Orders\OrderSource;
use App\Domain\Rules\RuleSet;
use App\Domain\Rules\RulesVersionRepository;
use App\Jobs\ProcessOrderPaid;
use App\Jobs\ProcessOrderReversal;
use App\Models\AuditEntry;
use App\Models\LedgerEntry;
use App\Models\LoyaltyAccount;
use App\Models\Redemption;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeOrderSource;
use Tests\TestCase;

/**
 * The webhook path end to end: signature, fast acknowledgement, the queued
 * work, and idempotency on redelivery (M3, M8, D3, D9).
 *
 * The queue connection is `sync` in the suite, so a dispatched job runs inline
 * and the whole chain is exercised in one request — except where Queue::fake()
 * is used deliberately to prove the endpoint answers WITHOUT doing the work.
 */
class WebhookEarningTest extends TestCase
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
    }

    private function member(int $shopifyCustomerId = 5001): LoyaltyAccount
    {
        return LoyaltyAccount::create([
            'shop_domain' => self::SHOP,
            'shopify_customer_id' => $shopifyCustomerId,
            'email' => 'member'.$shopifyCustomerId.'@example.com',
            'enrolment_channel' => 'online',
            'enrolled_at' => now()->subYear(),
        ]);
    }

    private function hmac(string $body): string
    {
        return base64_encode(hash_hmac('sha256', $body, config('shopify.api_secret'), true));
    }

    /** @param array<string, mixed> $payload */
    private function deliver(string $topic, array $payload, ?string $webhookId = 'wh-0001', ?string $hmac = null)
    {
        $body = json_encode($payload);

        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SHOPIFY_TOPIC' => $topic,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => self::SHOP,
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac ?? $this->hmac($body),
        ];

        if ($webhookId !== null) {
            $headers['HTTP_X_SHOPIFY_WEBHOOK_ID'] = $webhookId;
        }

        return $this->call('POST', config('shopify.webhooks.path'), [], [], [], $headers, $body);
    }

    // ------------------------------------------------------------- the signature

    public function test_an_orders_paid_webhook_with_a_bad_signature_earns_nothing(): void
    {
        $this->member();
        $this->orders->give(FakeOrderSource::workedExample());

        $this->deliver('orders/paid', ['id' => 1042], hmac: base64_encode('wrong'))
            ->assertStatus(401);

        $this->assertSame(0, LedgerEntry::query()->count(), 'An unsigned delivery must move no points.');
        $this->assertSame(0, WebhookEvent::query()->count(), 'And must not even be recorded.');
        $this->assertSame(0, $this->orders->reads, 'And must never reach the Admin API.');
    }

    // ------------------------------------------------------- fast acknowledgement

    public function test_the_endpoint_answers_without_doing_the_work(): void
    {
        Queue::fake();

        $this->member();
        $this->orders->give(FakeOrderSource::workedExample());

        $this->deliver('orders/paid', ['id' => 1042])->assertOk();

        // Recorded, queued, and answered — with nothing read and nothing posted.
        Queue::assertPushed(ProcessOrderPaid::class, fn (ProcessOrderPaid $job): bool => $job->shopDomain === self::SHOP
            && $job->shopifyOrderId === 1042);

        $this->assertSame(0, $this->orders->reads, 'The re-read belongs in the job, not the request.');
        $this->assertSame(0, LedgerEntry::query()->count());
        $this->assertSame('received', WebhookEvent::query()->sole()->state);
    }

    public function test_a_refund_webhook_queues_the_reversal_rather_than_running_it(): void
    {
        Queue::fake();

        $this->member();
        $this->orders->give(FakeOrderSource::workedExample());

        $this->deliver('refunds/create', ['id' => 77001, 'order_id' => 1042])->assertOk();

        Queue::assertPushed(ProcessOrderReversal::class, fn (ProcessOrderReversal $job): bool => $job->shopifyOrderId === 1042 && $job->shopifyRefundId === 77001);
    }

    // -------------------------------------------------------------- the earning

    public function test_an_order_paid_webhook_earns_points_for_a_member(): void
    {
        $member = $this->member();
        $this->orders->give(FakeOrderSource::workedExample());

        $this->deliver('orders/paid', ['id' => 1042])->assertOk();

        $member->refresh();
        $this->assertSame(80, $member->points_pending, 'D3: £80 eligible at one point per pound.');
        $this->assertSame(0, $member->points_available, 'D2: pending until it matures.');

        $earn = LedgerEntry::query()->where('entry_type', 'earn')->sole();
        $this->assertSame(1042, (int) $earn->shopify_order_id);
        $this->assertSame('#1042', $earn->order_name);
        $this->assertSame(8000, (int) $earn->qualifying_value_pence);
        $this->assertSame('online', $earn->channel);
        $this->assertNotNull($earn->matures_at);
        $this->assertNotNull($earn->expires_at);

        $this->assertSame('processed', WebhookEvent::query()->sole()->state);
    }

    /** C12: one audit entry per order, from the System actor. */
    public function test_earning_writes_one_system_audit_entry_for_the_order(): void
    {
        $this->member();
        $this->orders->give(FakeOrderSource::workedExample());

        $this->deliver('orders/paid', ['id' => 1042])->assertOk();

        $entry = AuditEntry::query()->where('action', 'order.earned')->sole();

        $this->assertSame('system', $entry->actor_type);
        $this->assertNull($entry->actor_staff_id, 'An automated actor has no staff id.');
        $this->assertNull($entry->actor_name, 'And no invented name — the console renders "System".');
        $this->assertSame('system', $entry->channel);
        $this->assertSame(1042, $entry->after_state['shopify_order_id']);
        $this->assertSame(80, $entry->after_state['points_posted']);
    }

    public function test_an_order_from_someone_who_is_not_a_member_is_ignored_not_failed(): void
    {
        $this->orders->give(FakeOrderSource::workedExample());

        $this->deliver('orders/paid', ['id' => 1042])->assertOk();

        $this->assertSame(0, LedgerEntry::query()->count());
        $this->assertSame('ignored', WebhookEvent::query()->sole()->state);
    }

    public function test_an_order_with_no_customer_is_ignored(): void
    {
        $this->orders->give(FakeOrderSource::workedExample(customerId: null));

        $this->deliver('orders/paid', ['id' => 1042])->assertOk();

        $this->assertSame(0, LedgerEntry::query()->count());
    }

    // ------------------------------------------------------------- idempotency

    /**
     * Redelivery is a no-op end to end, and stops at the outer guard.
     *
     * The service-level assertion already exists; this one proves the handler
     * short-circuits before the Admin API is touched at all, which is the
     * difference between "harmless" and "free".
     */
    public function test_the_same_delivery_twice_earns_once_and_reads_the_order_once(): void
    {
        $member = $this->member();
        $this->orders->give(FakeOrderSource::workedExample());

        $this->deliver('orders/paid', ['id' => 1042], webhookId: 'wh-abc')->assertOk();
        $this->deliver('orders/paid', ['id' => 1042], webhookId: 'wh-abc')->assertOk();

        $this->assertSame(80, $member->refresh()->points_pending, 'Not one hundred and sixty.');
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'earn')->count());
        $this->assertSame(1, $this->orders->reads, 'The second delivery never reached the Admin API.');

        $event = WebhookEvent::query()->sole();
        $this->assertSame(2, $event->attempts, 'The redelivery is counted, so a storm is visible.');
    }

    /**
     * A DIFFERENT delivery for the same order still earns only once.
     *
     * This is the inner guard: the webhook id is new, so the outer one lets it
     * through, and the ledger's own idempotency key catches it. Shopify sends
     * orders/paid and orders/updated for the same order as a matter of course.
     */
    public function test_a_different_delivery_for_the_same_order_does_not_earn_twice(): void
    {
        $member = $this->member();
        $this->orders->give(FakeOrderSource::workedExample());

        $this->deliver('orders/paid', ['id' => 1042], webhookId: 'wh-1')->assertOk();
        $this->deliver('orders/updated', ['id' => 1042], webhookId: 'wh-2')->assertOk();

        $this->assertSame(80, $member->refresh()->points_pending);
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'earn')->count());
        $this->assertSame(2, $this->orders->reads, 'Both were read; only one posted.');
    }

    /** An edit that adds value posts the difference, not a second full earn. */
    public function test_an_order_edit_posts_only_the_difference(): void
    {
        $member = $this->member();
        $this->orders->give(FakeOrderSource::workedExample());

        $this->deliver('orders/paid', ['id' => 1042], webhookId: 'wh-1')->assertOk();
        $this->assertSame(80, $member->refresh()->points_pending);

        // The customer added a £25 scarf.
        $this->orders->give(new OrderSnapshot(
            shopifyOrderId: 1042,
            orderName: '#1042',
            shopifyCustomerId: 5001,
            shopifyLocationId: null,
            lines: [
                ['id' => 'gid://shopify/LineItem/1', 'discounted_total_ex_tax_pence' => 8000, 'current_quantity' => 1, 'tags' => [], 'collections' => []],
                ['id' => 'gid://shopify/LineItem/2', 'discounted_total_ex_tax_pence' => 2500, 'current_quantity' => 1, 'tags' => [], 'collections' => []],
            ],
            processedAt: now(),
        ));

        $this->deliver('orders/updated', ['id' => 1042], webhookId: 'wh-2')->assertOk();

        $member->refresh();
        $this->assertSame(105, $member->points_pending, '80 then 25, never 80 then 105.');
        $this->assertSame(2, LedgerEntry::query()->where('entry_type', 'earn')->count());
        $this->assertSame(25, (int) LedgerEntry::query()->where('entry_type', 'earn')->orderByDesc('id')->first()->pending_delta);
    }

    /** An edit that removes value reverses the difference rather than editing. */
    public function test_an_order_edit_that_removes_value_posts_a_reversal(): void
    {
        $member = $this->member();
        $this->orders->give(FakeOrderSource::workedExample());

        $this->deliver('orders/paid', ['id' => 1042], webhookId: 'wh-1')->assertOk();

        $this->orders->give(new OrderSnapshot(
            shopifyOrderId: 1042,
            orderName: '#1042',
            shopifyCustomerId: 5001,
            shopifyLocationId: null,
            lines: [
                ['id' => 'gid://shopify/LineItem/1', 'discounted_total_ex_tax_pence' => 5000, 'current_quantity' => 1, 'tags' => [], 'collections' => []],
            ],
            processedAt: now(),
        ));

        $this->deliver('orders/updated', ['id' => 1042], webhookId: 'wh-2')->assertOk();

        $member->refresh();
        $this->assertSame(50, $member->points_pending);

        $reversal = LedgerEntry::query()->where('entry_type', 'earn_reversal')->sole();
        $this->assertSame(-30, $reversal->pending_delta);
    }

    // -------------------------------------------------------------- reversals

    /** The worked example, arriving as a refund webhook. */
    public function test_a_refund_webhook_reverses_and_restores_proportionally(): void
    {
        $member = $this->member();
        $this->orders->give(FakeOrderSource::workedExample());
        $this->deliver('orders/paid', ['id' => 1042], webhookId: 'wh-1')->assertOk();

        $this->redemptionFor($member, 200);

        // £20 of the £80 eligible value refunded.
        $this->orders->give(FakeOrderSource::workedExample(
            refunds: [FakeOrderSource::refund(2000)],
        ));

        $this->deliver('refunds/create', ['id' => 77001, 'order_id' => 1042], webhookId: 'wh-2')->assertOk();

        $member->refresh();
        $this->assertSame(60, $member->points_pending, '80 earned less 20 reversed.');
        $this->assertSame(50, (int) LedgerEntry::query()->where('entry_type', 'redemption_restore')->sole()->available_delta);
    }

    /** A refund on a line that never qualified must reverse nothing. */
    public function test_refunding_an_excluded_line_reverses_nothing(): void
    {
        $member = $this->member();

        $order = new OrderSnapshot(
            shopifyOrderId: 2042,
            orderName: '#2042',
            shopifyCustomerId: 5001,
            shopifyLocationId: null,
            lines: [
                ['id' => 'shirt', 'discounted_total_ex_tax_pence' => 8000, 'current_quantity' => 1, 'tags' => [], 'collections' => []],
                ['id' => 'card', 'discounted_total_ex_tax_pence' => 5000, 'current_quantity' => 1, 'tags' => ['gift-card'], 'collections' => []],
            ],
            processedAt: now(),
        );

        app(RulesVersionRepository::class)->save(self::SHOP, RuleSet::defaults([
            'qualification' => [
                'mode' => 'all', 'include_collections' => [], 'include_tags' => [],
                'exclude_collections' => [], 'exclude_tags' => ['gift-card'],
            ],
        ]));

        $this->orders->give($order);
        $this->deliver('orders/paid', ['id' => 2042], webhookId: 'wh-1')->assertOk();

        $this->assertSame(80, $member->refresh()->points_pending, 'The gift card earned nothing.');

        // Now refund the gift card only.
        $this->orders->give(new OrderSnapshot(
            shopifyOrderId: 2042,
            orderName: '#2042',
            shopifyCustomerId: 5001,
            shopifyLocationId: null,
            lines: $order->lines,
            refunds: [['line_id' => 'card', 'refund_id' => 88001, 'pence' => 5000]],
            processedAt: now(),
        ));

        $this->deliver('refunds/create', ['id' => 88001, 'order_id' => 2042], webhookId: 'wh-2')->assertOk();

        $this->assertSame(80, $member->refresh()->points_pending, 'Refunding what never earned reverses nothing.');
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'earn_reversal')->count());
    }

    /** A cancellation reverses everything, with no refund line items at all. */
    public function test_a_cancellation_reverses_the_whole_order(): void
    {
        $member = $this->member();
        $this->orders->give(FakeOrderSource::workedExample());
        $this->deliver('orders/paid', ['id' => 1042], webhookId: 'wh-1')->assertOk();

        $this->orders->give(FakeOrderSource::workedExample(cancelled: true));

        $this->deliver('orders/cancelled', ['id' => 1042], webhookId: 'wh-2')->assertOk();

        $this->assertSame(0, $member->refresh()->points_pending, 'Every earned point taken back.');
    }

    /** C12: one audit entry per order for the reversal, carrying the references. */
    public function test_a_reversal_writes_one_system_audit_entry_naming_the_refund(): void
    {
        $member = $this->member();
        $this->orders->give(FakeOrderSource::workedExample());
        $this->deliver('orders/paid', ['id' => 1042], webhookId: 'wh-1')->assertOk();

        $this->orders->give(FakeOrderSource::workedExample(refunds: [FakeOrderSource::refund(2000)]));
        $this->deliver('refunds/create', ['id' => 77001, 'order_id' => 1042], webhookId: 'wh-2')->assertOk();

        $entry = AuditEntry::query()->where('action', 'order.reversed')->sole();

        $this->assertSame('system', $entry->actor_type);
        $this->assertSame((int) $member->id, (int) $entry->subject_id);
        $this->assertSame(1042, $entry->after_state['shopify_order_id']);
        $this->assertSame(77001, $entry->after_state['shopify_refund_id']);
        $this->assertSame(20, $entry->after_state['points_reversed']);
    }

    private function redemptionFor(LoyaltyAccount $member, int $points): Redemption
    {
        $redemption = Redemption::create([
            'shop_domain' => self::SHOP,
            'reference' => 'PC-'.$member->id,
            'loyalty_account_id' => $member->id,
            'channel' => 'online',
            'state' => 'confirmed',
            'amount_pence' => 1000,
            'points_consumed' => $points,
            'discount_mechanism' => 'function',
            'shopify_order_id' => 1042,
            'rules_version_id' => app(RulesVersionRepository::class)->current(self::SHOP)->versionId,
            'validation_snapshot' => [],
            'quoted_at' => now(),
            'quote_expires_at' => now()->addMinutes(20),
            'confirmed_at' => now(),
        ]);

        // The points have to have been spent for there to be anything to give
        // back, and they must come from somewhere that is not this order.
        app(LedgerService::class)->post($member, LedgerPosting::openingBalance(
            points: 260,
            idempotencyKey: 'open:'.$member->id,
            occurredAt: now()->subMonths(2),
            expiresAt: now()->addMonths(4),
            reason: 'Migrated balance',
        ));

        app(LedgerService::class)->post($member, LedgerPosting::redemption(
            points: $points,
            redemptionId: (int) $redemption->id,
            idempotencyKey: 'redeem:1042',
            occurredAt: now(),
            shopifyOrderId: 1042,
        ));

        return $redemption;
    }
}
