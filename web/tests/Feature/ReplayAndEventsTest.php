<?php

namespace Tests\Feature;

use App\Domain\Events\LoyaltyEvent;
use App\Domain\Events\LoyaltyEventName;
use App\Domain\Events\MemberEvents;
use App\Domain\Events\NullEventBus;
use App\Domain\Loyalty\BirthdaySweep;
use App\Domain\Loyalty\ExpirySweep;
use App\Domain\Loyalty\LedgerPosting;
use App\Domain\Loyalty\LedgerService;
use App\Domain\Loyalty\MaturitySweep;
use App\Domain\Orders\OrderSource;
use App\Domain\Rules\RulesVersionRepository;
use App\Models\AuditEntry;
use App\Models\LedgerEntry;
use App\Models\LoyaltyAccount;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\FakeOrderSource;
use Tests\TestCase;

/**
 * The replay command, and the Klaviyo event bus behind its null driver.
 *
 * Both are about Sprint 2 being finishable without the things it depends on:
 * replay is what makes a missed webhook recoverable, and the null driver is what
 * lets every emit call site exist before Klaviyo does.
 */
class ReplayAndEventsTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'loyalty-system.myshopify.com';

    private FakeOrderSource $orders;

    private NullEventBus $events;

    protected function setUp(): void
    {
        parent::setUp();

        app(RulesVersionRepository::class)->seedDefaults(self::SHOP);

        $this->orders = new FakeOrderSource;
        $this->app->instance(OrderSource::class, $this->orders);

        $this->events = app(NullEventBus::class);
        $this->events->forget();
    }

    private function member(int $customerId = 5001, array $attributes = []): LoyaltyAccount
    {
        return LoyaltyAccount::create(array_merge([
            'shop_domain' => self::SHOP,
            'shopify_customer_id' => $customerId,
            'email' => 'member'.$customerId.'@example.com',
            'enrolment_channel' => 'online',
            'enrolled_at' => now()->subYear(),
        ], $attributes));
    }

    // ------------------------------------------------------------------ replay

    public function test_a_replay_earns_for_an_order_whose_webhook_never_arrived(): void
    {
        $member = $this->member();
        $this->orders->give(FakeOrderSource::workedExample());

        $this->artisan('loyalty:replay-orders', ['--order' => [1042]])->assertSuccessful();

        $this->assertSame(80, $member->refresh()->points_pending);
    }

    /**
     * The property the whole command rests on: replaying work that was already
     * done changes nothing.
     */
    public function test_replaying_an_already_processed_order_changes_nothing(): void
    {
        $member = $this->member();
        $this->orders->give(FakeOrderSource::workedExample());

        $this->artisan('loyalty:replay-orders', ['--order' => [1042]])->assertSuccessful();

        $entriesAfterFirst = LedgerEntry::query()->count();
        $pendingAfterFirst = $member->refresh()->points_pending;

        $this->artisan('loyalty:replay-orders', ['--order' => [1042]])->assertSuccessful();
        $this->artisan('loyalty:replay-orders', ['--order' => [1042]])->assertSuccessful();

        $this->assertSame($entriesAfterFirst, LedgerEntry::query()->count(), 'No new ledger rows.');
        $this->assertSame($pendingAfterFirst, $member->refresh()->points_pending, 'No new points.');
        $this->assertSame(80, $member->points_pending);
    }

    /** A replay over a whole date range is safe for the same reason. */
    public function test_a_range_replay_over_processed_orders_changes_nothing(): void
    {
        $member = $this->member();
        $this->orders->give(FakeOrderSource::workedExample());
        $this->artisan('loyalty:replay-orders', ['--order' => [1042]])->assertSuccessful();

        $before = LedgerEntry::query()->count();

        $this->artisan('loyalty:replay-orders', [
            '--from' => now()->subDay()->toDateString(),
            '--to' => now()->addDay()->toDateString(),
        ])->assertSuccessful();

        $this->assertSame($before, LedgerEntry::query()->count());
        $this->assertSame(80, $member->refresh()->points_pending);
    }

    public function test_a_replay_can_target_the_deliveries_that_failed(): void
    {
        $member = $this->member();
        $this->orders->give(FakeOrderSource::workedExample());

        WebhookEvent::create([
            'shop_domain' => self::SHOP,
            'topic' => 'orders/paid',
            'shopify_webhook_id' => 'wh-dead',
            'payload_hash' => str_repeat('a', 64),
            'resource_id' => 1042,
            'state' => 'failed',
            'attempts' => 3,
            'error' => 'The order could not be re-read.',
            'received_at' => now()->subHour(),
        ]);

        $this->artisan('loyalty:replay-orders', ['--failed' => true])->assertSuccessful();

        $this->assertSame(80, $member->refresh()->points_pending);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $member = $this->member();
        $this->orders->give(FakeOrderSource::workedExample());

        $this->artisan('loyalty:replay-orders', ['--order' => [1042], '--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame(0, LedgerEntry::query()->count());
        $this->assertSame(0, $member->refresh()->points_pending);
        $this->assertSame(0, $this->orders->reads, 'A dry run does not even read the order.');
    }

    /** C12: a replay is automated work, so it audits like one. */
    public function test_a_replay_writes_one_job_audit_entry_per_shop(): void
    {
        $this->member();
        $this->orders->give(FakeOrderSource::workedExample());

        $this->artisan('loyalty:replay-orders', ['--order' => [1042]])->assertSuccessful();

        $entry = AuditEntry::query()->where('action', 'job.completed')->sole();

        $this->assertSame('system', $entry->actor_type);
        $this->assertSame('loyalty:replay-orders', $entry->after_state['job']);
        $this->assertSame(1, $entry->after_state['orders']);
        $this->assertSame(80, $entry->after_state['points_posted']);
    }

    // ------------------------------------------------------------ the event bus

    /** M11: every Sprint 4 event has a call site now, behind the null driver. */
    public function test_all_five_events_are_emitted_from_real_code_paths(): void
    {
        $seen = [];

        // 1. Welcome, on enrolment.
        $member = $this->member(5001, ['dob_month' => 9, 'dob_day' => 15]);
        app(MemberEvents::class)
            ->emit($member, LoyaltyEventName::MEMBER_ENROLLED, ['enrolment_channel' => 'admin']);

        // 2 and 3. Points available, and the voucher increment it crossed.
        $ledger = app(LedgerService::class);
        $earn = $ledger->post($member, LedgerPosting::earn(
            points: 150,
            idempotencyKey: 'earn:events',
            occurredAt: now()->subDays(31),
            maturesAt: now()->subDay(),
            expiresAt: now()->addDays(30),
        ));
        app(MaturitySweep::class)->run(self::SHOP);

        // 4. The expiry reminder, on the day the lot enters the warning window.
        //    The earn above expires in 30 days, which is the warning window.
        app(ExpirySweep::class)->run(self::SHOP);

        // 5. The birthday reward.
        app(BirthdaySweep::class)->run(self::SHOP, Carbon::parse('2026-09-15'));

        foreach ($this->events->emitted() as $event) {
            $seen[$event->name] = true;
        }

        foreach (LoyaltyEventName::all() as $name) {
            $this->assertArrayHasKey(
                $name,
                $seen,
                $name.' has no call site, so Sprint 4 would have to find one.',
            );
        }

        $this->assertNotNull($earn->id);
    }

    public function test_the_null_driver_drops_everything_it_is_given(): void
    {
        $member = $this->member();

        app(MemberEvents::class)
            ->emit($member, LoyaltyEventName::MEMBER_ENROLLED, []);

        $this->assertSame(1, $this->events->count(), 'It saw the event...');
        // ...and there is nothing else to assert, which is the point: no HTTP
        // call, no queue entry, no stored state. Sprint 4 binds a driver that
        // does something and no call site changes.
    }

    /** MD1: a member with no email is still a real event, just not deliverable. */
    public function test_an_event_for_a_member_with_no_email_is_emitted_but_not_deliverable(): void
    {
        $member = LoyaltyAccount::create([
            'shop_domain' => self::SHOP,
            'enrolment_channel' => 'pos',
            'enrolled_at' => now(),
            'legacy_card_number' => 'D-118422',
        ]);

        app(MemberEvents::class)
            ->emit($member, LoyaltyEventName::MEMBER_ENROLLED, []);

        $event = $this->events->emitted()[0];

        $this->assertSame(LoyaltyEventName::MEMBER_ENROLLED, $event->name);
        $this->assertFalse($event->isDeliverable(), 'The driver decides what to do about it.');
    }

    public function test_an_unknown_event_name_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new LoyaltyEvent(
            name: 'points.doubled_on_tuesdays',
            shopDomain: self::SHOP,
            loyaltyAccountId: 1,
        );
    }

    /** The voucher increment event fires on a crossing, not on every maturity. */
    public function test_the_voucher_increment_event_fires_only_when_an_increment_is_crossed(): void
    {
        $member = $this->member();
        $ledger = app(LedgerService::class);

        // 40 points: nowhere near the hundred point increment.
        $ledger->post($member, LedgerPosting::earn(
            points: 40,
            idempotencyKey: 'earn:small',
            occurredAt: now()->subDays(31),
            maturesAt: now()->subDay(),
            expiresAt: now()->addDays(181),
        ));

        app(MaturitySweep::class)->run(self::SHOP);

        $names = array_map(fn ($e): string => $e->name, $this->events->emitted());

        $this->assertContains(LoyaltyEventName::POINTS_AVAILABLE, $names);
        $this->assertNotContains(
            LoyaltyEventName::VOUCHER_INCREMENT_REACHED,
            $names,
            'Forty points is not a five pound voucher, and saying so would be a lie.',
        );
    }
}
