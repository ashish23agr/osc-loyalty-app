<?php

namespace Tests\Feature;

use App\Domain\Loyalty\BalanceCalculator;
use App\Domain\Loyalty\ExpirySweep;
use App\Domain\Loyalty\LedgerPosting;
use App\Domain\Loyalty\LedgerService;
use App\Domain\Redemption\MetafieldWriter;
use App\Domain\Rules\RulesVersionRepository;
use App\Jobs\PublishBalanceMetafieldJob;
use App\Models\LoyaltyAccount;
use App\Models\Reward;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeMetafieldWriter;
use Tests\TestCase;

/**
 * M4's customer balance metafield, and the two assertions the 9 Sep audit found
 * missing from the suite.
 *
 * The published metafield is the only way anything outside this app can see a
 * member's entitlement, so it is worth testing for the same reason the quote
 * payload is: it stands between a member's balance and a customer setting their
 * own discount.
 */
class BalanceMetafieldPublishTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'loyalty-system.myshopify.com';

    private LedgerService $ledger;

    private FakeMetafieldWriter $metafields;

    protected function setUp(): void
    {
        parent::setUp();

        app(RulesVersionRepository::class)->seedDefaults(self::SHOP);

        $this->ledger = app(LedgerService::class);

        // Replaces the base class's default binding, so this test can read back
        // what was published rather than merely not exploding.
        $this->metafields = new FakeMetafieldWriter;
        $this->app->instance(MetafieldWriter::class, $this->metafields);
    }

    private function member(?int $customerId = 6001): LoyaltyAccount
    {
        return LoyaltyAccount::create([
            'shop_domain' => self::SHOP,
            'shopify_customer_id' => $customerId,
            'email' => 'metafield@example.co.uk',
            'enrolment_channel' => 'online',
            'enrolled_at' => now()->subMonths(3),
            'legacy_card_number' => 'D-118422',
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function publishes(): array
    {
        return array_values(array_map(
            fn (array $call): array => $call['value'],
            array_filter(
                $this->metafields->calls,
                fn (array $call): bool => $call['key'] === PublishBalanceMetafieldJob::METAFIELD_KEY,
            ),
        ));
    }

    public function test_it_publishes_the_member_position_when_the_balance_moves(): void
    {
        $member = $this->member();

        $this->ledger->post($member, LedgerPosting::openingBalance(
            points: 340,
            idempotencyKey: 'open:'.$member->id,
            occurredAt: now()->subMonth(),
            expiresAt: now()->addMonths(5),
            reason: 'Migrated balance',
        ));

        $published = $this->publishes();

        $this->assertCount(1, $published);
        $this->assertSame(1500, $published[0]['voucher_balance_pence'], '340 points is three increments.');
        $this->assertSame(340, $published[0]['points_available']);
        $this->assertSame('D-118422', $published[0]['legacy_card_number']);
        $this->assertArrayHasKey('member_status', $published[0]);
        $this->assertArrayHasKey('published_at', $published[0]);

        // Written against the customer, because that is the owner the discount
        // function and the account page can both read.
        $this->assertSame(
            'gid://shopify/Customer/6001',
            $this->metafields->calls[0]['owner'],
        );
    }

    /**
     * The audit's first missing assertion: **once per balance change, not per
     * ledger entry.**
     *
     * A posting that leaves the voucher balance where it was must not republish.
     * 340 to 390 points is still three increments, so the metafield is unchanged
     * and an Admin API call to say so is waste - one per posting, forever.
     */
    public function test_a_posting_that_does_not_move_the_balance_does_not_republish(): void
    {
        $member = $this->member();

        $this->ledger->post($member, LedgerPosting::openingBalance(
            points: 340,
            idempotencyKey: 'open:'.$member->id,
            occurredAt: now()->subMonth(),
            expiresAt: now()->addMonths(5),
            reason: 'Migrated balance',
        ));

        $this->assertCount(1, $this->publishes(), 'The opening balance moved it.');

        $this->ledger->post($member->refresh(), LedgerPosting::adjustment(
            points: 50,
            bucket: 'available',
            reason: 'Goodwill gesture — fifty points, still three increments',
            idempotencyKey: 'adj:no-crossing',
            occurredAt: now(),
        ));

        $this->assertCount(
            1,
            $this->publishes(),
            '390 points is still £15, so there was nothing new to publish.',
        );

        // And the balance really did move underneath, so this is not passing by
        // nothing having happened.
        $this->assertSame(390, $member->refresh()->points_available);
    }

    /** A pending-only posting cannot move the derived balance, so it must not publish. */
    public function test_an_earn_into_pending_does_not_republish(): void
    {
        $member = $this->member();

        $this->ledger->post($member, LedgerPosting::earn(
            points: 600,
            idempotencyKey: 'earn:pending',
            occurredAt: now(),
            maturesAt: now()->addDays(30),
            expiresAt: now()->addDays(212),
            shopifyOrderId: 9001,
            qualifyingValuePence: 60000,
        ));

        $this->assertSame([], $this->publishes(), 'Pending points are not a voucher.');
        $this->assertSame(600, $member->refresh()->points_pending);
    }

    /**
     * The audit's second missing assertion: **an expiry that drops available
     * below a multiple reduces the balance without touching any reward row.**
     *
     * D1's whole design rests on this. The derived balance falls on its own
     * because it is a function of points, and `loyalty_rewards` holds only
     * genuinely issued objects - so an expiry must move the first and leave the
     * second completely alone. If expiry ever started editing reward rows, a
     * birthday voucher would silently lose its value alongside the points.
     */
    public function test_an_expiry_reduces_the_derived_balance_without_touching_a_reward_row(): void
    {
        $member = $this->member();

        // Two lots, so only one expires: 340 points is three increments (£15).
        $this->ledger->post($member, LedgerPosting::openingBalance(
            points: 240,
            idempotencyKey: 'open:old:'.$member->id,
            occurredAt: now()->subMonths(7),
            expiresAt: now()->subDay(),
            reason: 'Migrated balance, now expiring',
        ));

        $this->ledger->post($member->refresh(), LedgerPosting::openingBalance(
            points: 100,
            idempotencyKey: 'open:new:'.$member->id,
            occurredAt: now()->subMonth(),
            expiresAt: now()->addMonths(5),
            reason: 'Migrated balance, still live',
        ));

        $this->assertSame(1500, $member->refresh()->voucher_balance_pence, '340 points is £15.');

        // A genuinely issued reward, which the expiry must not touch.
        $reward = Reward::create([
            'shop_domain' => self::SHOP,
            'loyalty_account_id' => $member->id,
            'reward_type' => 'birthday',
            'value_pence' => 1000,
            'currency' => 'GBP',
            'birthday_year' => 2026,
            'state' => 'issued',
            'issued_at' => now()->subDays(5),
            'expires_at' => now()->addDays(85),
            'rules_version_id' => app(RulesVersionRepository::class)->current(self::SHOP)->versionId,
        ]);

        // Read back from the database, not from the just-created model: a fresh
        // instance carries only the attributes that were set, so comparing the
        // two would fail on key order and unset defaults rather than on any
        // change to the row.
        $before = Reward::query()->find($reward->id)->toArray();
        ksort($before);

        app(ExpirySweep::class)->run(self::SHOP, now());

        $member->refresh();

        // 100 points left: one increment, so the derived balance fell by £10.
        $this->assertSame(100, $member->points_available);
        $this->assertSame(500, $member->voucher_balance_pence, 'The derived balance fell with the points.');

        // The reward row is byte-for-byte what it was.
        $after = Reward::query()->find($reward->id)->toArray();
        ksort($after);

        $this->assertSame($before, $after, 'Expiry must not touch an issued reward.');
        $this->assertSame('issued', $reward->state);
        $this->assertSame(1000, (int) $reward->value_pence);

        // And the fall was published, because the member's own view of it changed.
        $published = $this->publishes();
        $this->assertSame(500, end($published)['voucher_balance_pence']);
    }

    /** MD1: a till-enrolled member with no Shopify customer has nowhere to publish. */
    public function test_a_member_with_no_shopify_customer_publishes_nothing(): void
    {
        $member = $this->member(customerId: null);

        $this->ledger->post($member, LedgerPosting::openingBalance(
            points: 340,
            idempotencyKey: 'open:'.$member->id,
            occurredAt: now()->subMonth(),
            expiresAt: now()->addMonths(5),
            reason: 'Migrated balance',
        ));

        $this->assertSame([], $this->publishes(), 'Nowhere to publish is not a failure.');
        $this->assertSame(1500, $member->refresh()->voucher_balance_pence, 'The balance is still derived.');
    }

    /** The payload is a contract, so it is asserted directly rather than through a queue. */
    public function test_the_payload_is_pure_and_reads_from_the_account(): void
    {
        $member = $this->member();
        app(BalanceCalculator::class)->refreshCache($member);

        $payload = PublishBalanceMetafieldJob::payloadFor($member->refresh());

        $this->assertSame(
            ['voucher_balance_pence', 'points_available', 'member_status', 'segment',
                'legacy_card_number', 'member_since', 'published_at'],
            array_keys($payload),
            'The key set is what an external reader depends on; changing it is a breaking change.',
        );
        $this->assertSame(now()->subMonths(3)->toDateString(), $payload['member_since']);
    }
}
