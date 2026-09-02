<?php

namespace Tests\Feature\Admin;

use App\Domain\Loyalty\LedgerPosting;
use App\Domain\Loyalty\LedgerService;
use App\Domain\Loyalty\RedemptionLadder;
use App\Domain\Redemption\MetafieldWriter;
use App\Models\AuditEntry;
use App\Models\LedgerEntry;
use App\Models\LoyaltyAccount;
use App\Models\Redemption;
use Tests\Support\FakeMetafieldWriter;

/**
 * The endpoints the POS tile actually calls (M7, D6, D8).
 *
 * The tile is a thin client over these three, so the contract it branches on —
 * status codes, the `reason` string, the shape of a refusal — is what matters
 * here. A refusal in particular is a 200 with a reason, not an error: the tile
 * has something to put on screen and nothing has gone wrong.
 */
class RedemptionEndpointTest extends AdminApiTestCase
{
    private FakeMetafieldWriter $metafields;

    protected function setUp(): void
    {
        parent::setUp();

        $this->metafields = new FakeMetafieldWriter;
        $this->app->instance(MetafieldWriter::class, $this->metafields);
    }

    /** A member holding £50 of voucher value. */
    private int $nextCustomerId = 7001;

    private function holder(int $availablePoints = 1000): LoyaltyAccount
    {
        // A distinct Shopify customer each time: uq_account_customer forbids two
        // members sharing one, which is D10 working rather than a nuisance.
        $customerId = $this->nextCustomerId++;

        $member = LoyaltyAccount::create([
            'shop_domain' => self::SHOP,
            'shopify_customer_id' => $customerId,
            'email' => 'till'.uniqid().'@example.com',
            'enrolment_channel' => 'pos',
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

    /** The tile validates before it offers, so quote writes nothing. */
    public function test_a_viewer_may_quote_and_nothing_is_written(): void
    {
        $member = $this->holder();

        $this->postJson('/api/admin/redemptions/quote', [
            'loyalty_account_id' => $member->id,
            'eligible_subtotal_pence' => 6000,
            'basket_total_pence' => 10000,
        ], $this->headersFor('viewer', 810001))
            ->assertOk()
            ->assertJsonPath('redeemable_pence', 5000)
            ->assertJsonPath('reason', null)
            ->assertJsonPath('points_for_amount', 1000);

        $this->assertSame(0, Redemption::query()->count());
    }

    /** The agreed UI's rejection example, through the endpoint the tile calls. */
    public function test_a_quote_offers_only_what_qualifies(): void
    {
        $member = $this->holder();

        $this->postJson('/api/admin/redemptions/quote', [
            'loyalty_account_id' => $member->id,
            'eligible_subtotal_pence' => 4500,
            'basket_total_pence' => 7000,
        ], $this->headersFor('viewer', 810001))
            ->assertOk()
            ->assertJsonPath('redeemable_pence', 4500)
            ->assertJsonPath('binding_rung', 'eligible_subtotal');
    }

    /** Every refusal reason the tile has to render. */
    public function test_each_refusal_carries_the_reason_the_tile_shows(): void
    {
        $headers = $this->headersFor('viewer', 810001);

        $none = $this->holder(availablePoints: 0);
        $this->postJson('/api/admin/redemptions/quote', [
            'loyalty_account_id' => $none->id,
            'eligible_subtotal_pence' => 6000,
            'basket_total_pence' => 10000,
        ], $headers)->assertOk()->assertJsonPath('reason', RedemptionLadder::NO_VOUCHER_BALANCE);

        $member = $this->holder();

        $this->postJson('/api/admin/redemptions/quote', [
            'loyalty_account_id' => $member->id,
            'eligible_subtotal_pence' => 0,
            'basket_total_pence' => 10000,
        ], $headers)->assertOk()->assertJsonPath('reason', RedemptionLadder::NO_ELIGIBLE_ITEMS);

        // The new one: qualifying items worth less than one £5 increment.
        $this->postJson('/api/admin/redemptions/quote', [
            'loyalty_account_id' => $member->id,
            'eligible_subtotal_pence' => 400,
            'basket_total_pence' => 10000,
        ], $headers)->assertOk()->assertJsonPath('reason', RedemptionLadder::BELOW_VOUCHER_INCREMENT);
    }

    // ------------------------------------------------------------------- hold

    public function test_holding_a_quote_at_the_till_records_the_staff_member(): void
    {
        $member = $this->holder();

        $response = $this->postJson('/api/admin/redemptions', [
            'loyalty_account_id' => $member->id,
            'eligible_subtotal_pence' => 6000,
            'basket_total_pence' => 10000,
            'channel' => 'pos',
            'shopify_location_id' => 771,
            'staff_member_id' => 4242,
        ], $this->headersFor('agent', 810002))
            ->assertStatus(201)
            ->assertJsonPath('redemption.state', 'quoted')
            ->assertJsonPath('redemption.amount_pence', 5000)
            ->assertJsonPath('redemption.discount_mechanism', 'pos_cart_discount')
            ->assertJsonPath('reason', null);

        $reference = $response->json('redemption.reference');
        $this->assertNotEmpty($reference);

        // The pinned staff member AND the authenticated user, because they are
        // not always the same person.
        $redemption = Redemption::query()->where('reference', $reference)->sole();
        $this->assertStringContainsString('4242', $redemption->staff_reference);
        $this->assertStringContainsString('810002', $redemption->staff_reference);

        // Nothing spent yet: that waits for orders/paid.
        $this->assertSame(0, $redemption->points_consumed);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'redemption')->count());
        $this->assertSame(1000, $member->refresh()->points_available);
    }

    /** POS holds publish nothing — the tile applies the discount itself (D6). */
    public function test_a_till_hold_publishes_no_customer_metafield(): void
    {
        $member = $this->holder();

        $this->postJson('/api/admin/redemptions', [
            'loyalty_account_id' => $member->id,
            'eligible_subtotal_pence' => 6000,
            'basket_total_pence' => 10000,
            'channel' => 'pos',
        ], $this->headersFor('agent', 810002))->assertStatus(201);

        $this->assertSame([], $this->metafields->written);
    }

    /** Online holds do publish, because the function has to read it (D5). */
    public function test_an_online_hold_publishes_the_entitlement(): void
    {
        $member = $this->holder();

        $this->postJson('/api/admin/redemptions', [
            'loyalty_account_id' => $member->id,
            'eligible_subtotal_pence' => 6000,
            'basket_total_pence' => 10000,
            'channel' => 'online',
        ], $this->headersFor('agent', 810002))->assertStatus(201);

        $published = $this->metafields->readFor(self::SHOP, 7001);

        $this->assertNotNull($published);
        $this->assertSame(5000, $published['voucher_balance_pence']);
    }

    /** A refusal is a 200 with a reason, so the tile can explain itself. */
    public function test_a_refused_hold_is_not_an_error(): void
    {
        $member = $this->holder(availablePoints: 0);

        $this->postJson('/api/admin/redemptions', [
            'loyalty_account_id' => $member->id,
            'eligible_subtotal_pence' => 6000,
            'basket_total_pence' => 10000,
            'channel' => 'pos',
        ], $this->headersFor('agent', 810002))
            ->assertOk()
            ->assertJsonPath('redemption', null)
            ->assertJsonPath('reason', RedemptionLadder::NO_VOUCHER_BALANCE);

        $this->assertSame(0, Redemption::query()->count());
    }

    /** C1: the £5-step control sends what the member chose. */
    public function test_the_step_control_may_ask_for_less(): void
    {
        $member = $this->holder();

        $this->postJson('/api/admin/redemptions', [
            'loyalty_account_id' => $member->id,
            'eligible_subtotal_pence' => 6000,
            'basket_total_pence' => 10000,
            'channel' => 'pos',
            'requested_pence' => 1500,
        ], $this->headersFor('agent', 810002))
            ->assertStatus(201)
            ->assertJsonPath('redemption.amount_pence', 1500);
    }

    /** C12/C9: a till hold is audited, attributed, and does not bypass a floor. */
    public function test_a_hold_is_audited_and_a_viewer_is_refused(): void
    {
        $member = $this->holder();

        $this->postJson('/api/admin/redemptions', [
            'loyalty_account_id' => $member->id,
            'eligible_subtotal_pence' => 6000,
            'basket_total_pence' => 10000,
            'channel' => 'pos',
        ], $this->headersFor('viewer', 810003))->assertStatus(403);

        $this->assertSame(0, Redemption::query()->count());

        $this->postJson('/api/admin/redemptions', [
            'loyalty_account_id' => $member->id,
            'eligible_subtotal_pence' => 6000,
            'basket_total_pence' => 10000,
            'channel' => 'pos',
            'staff_member_id' => 4242,
        ], $this->headersFor('agent', 810002))->assertStatus(201);

        $entry = AuditEntry::query()->where('action', 'redemption.quoted')->sole();

        $this->assertSame('staff', $entry->actor_type);
        $this->assertSame(810002, (int) $entry->actor_staff_id);
        $this->assertSame(4242, $entry->after_state['staff_member_id']);
        $this->assertSame('pos', $entry->after_state['channel']);
    }

    // ------------------------------------------------------------------- void

    public function test_voiding_gives_the_value_back(): void
    {
        $member = $this->holder();

        $reference = $this->postJson('/api/admin/redemptions', [
            'loyalty_account_id' => $member->id,
            'eligible_subtotal_pence' => 6000,
            'basket_total_pence' => 10000,
            'channel' => 'online',
        ], $this->headersFor('agent', 810002))->json('redemption.reference');

        $this->deleteJson('/api/admin/redemptions/'.$reference, [], $this->headersFor('agent', 810002))
            ->assertOk()
            ->assertJsonPath('redemption.state', 'void');

        $this->assertSame(1000, $member->refresh()->points_available);
        $this->assertNull($this->metafields->readFor(self::SHOP, 7001), 'The entitlement is withdrawn.');

        $this->assertSame(1, AuditEntry::query()->where('action', 'redemption.voided')->count());
    }

    public function test_an_unknown_reference_is_a_404(): void
    {
        $this->deleteJson('/api/admin/redemptions/PC-NOPE', [], $this->headersFor('agent', 810002))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');
    }
}
