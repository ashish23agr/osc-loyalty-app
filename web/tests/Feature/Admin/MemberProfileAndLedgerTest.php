<?php

namespace Tests\Feature\Admin;

use App\Domain\Loyalty\LedgerPosting;
use App\Domain\Loyalty\LedgerService;
use App\Models\AuditEntry;
use App\Models\LoyaltyAccount;
use App\Models\Reward;
use App\Models\RulesVersion;
use App\Support\Audit\AuditAction;

/**
 * GET /api/admin/members/{id}, its ledger, and the cache rebuild.
 *
 * The profile screen is the one that must not need six calls to draw: the
 * header, the two balances, the voucher figures, points to the next five
 * pounds, lifetime spend and the vouchers tab all come back together, and only
 * the ledger — which is paged and filtered — is fetched separately.
 */
class MemberProfileAndLedgerTest extends AdminApiTestCase
{
    private function margaret(): LoyaltyAccount
    {
        return $this->member([
            'email' => 'm.whitfield@example.co.uk',
            'first_name' => 'Margaret',
            'last_name' => 'Whitfield',
            'postcode' => 'SP4 6AB',
            'legacy_card_number' => 'D-118422',
            'dob_month' => 9,
            'dob_day' => 12,
            'enrolment_channel' => 'migration',
        ]);
    }

    public function test_the_profile_answers_the_whole_header_in_one_call(): void
    {
        $member = $this->margaret();
        $ledger = app(LedgerService::class);

        // 340 available: matured, and worth three five-pound increments.
        $this->withAvailablePoints($member, 340);

        // 72 still pending, clearing in a month.
        $ledger->post($member, LedgerPosting::earn(
            points: 72,
            idempotencyKey: 'earn:pending',
            occurredAt: now(),
            maturesAt: now()->addDays(30),
            expiresAt: now()->addDays(212),
            channel: 'pos',
            shopifyOrderId: 8841,
            orderName: '#POS-8841',
            qualifyingValuePence: 7240,
        ));

        $response = $this->getJson('/api/admin/members/'.$member->id, $this->headersFor('viewer', 650001))
            ->assertOk();

        $response->assertJsonPath('display_name', 'Margaret Whitfield')
            ->assertJsonPath('legacy_card_number', 'D-118422')
            ->assertJsonPath('has_birthday', true)
            ->assertJsonPath('balances.points_available', 340)
            ->assertJsonPath('balances.points_pending', 72)
            ->assertJsonPath('balances.points_lifetime', 340)
            ->assertJsonPath('balances.voucher_balance_pence', 1500)
            ->assertJsonPath('balances.voucher_increments', 3)
            ->assertJsonPath('balances.points_committed_to_voucher', 300)
            ->assertJsonPath('balances.remainder_points', 40)
            // The "points to next five pounds" figure on the header.
            ->assertJsonPath('balances.points_to_next_increment', 60)
            // Lifetime spend, summed from the qualifying value of every earn.
            ->assertJsonPath('lifetime_spend_pence', 34000 + 7240)
            ->assertJsonPath('rules.voucher_threshold_points', 100)
            ->assertJsonPath('rules.voucher_value_pence', 500);

        $this->assertNotNull($response->json('pending_clears_at'));
        $this->assertNotNull($response->json('last_purchase_at'));
    }

    public function test_the_profile_carries_the_vouchers_tab(): void
    {
        $member = $this->margaret();

        Reward::create([
            'shop_domain' => self::SHOP,
            'loyalty_account_id' => $member->id,
            'reward_type' => 'birthday',
            'value_pence' => 1000,
            'birthday_year' => 2026,
            'state' => 'issued',
            'issued_at' => now()->subDays(2),
            'expires_at' => now()->addDays(88),
            'rules_version_id' => RulesVersion::where('shop_domain', self::SHOP)->value('id'),
        ]);

        $this->getJson('/api/admin/members/'.$member->id, $this->headersFor('viewer', 650002))
            ->assertOk()
            ->assertJsonCount(1, 'rewards')
            ->assertJsonPath('rewards.0.reward_type', 'birthday')
            ->assertJsonPath('rewards.0.value_pence', 1000)
            ->assertJsonPath('rewards.0.state', 'issued');
    }

    public function test_points_about_to_expire_are_reported_on_the_profile(): void
    {
        $member = $this->margaret();
        $ledger = app(LedgerService::class);

        $ledger->post($member, LedgerPosting::openingBalance(
            points: 120,
            idempotencyKey: 'open:soon',
            occurredAt: now()->subDays(160),
            // Inside the 30-day warning window from the launch rules.
            expiresAt: now()->addDays(10),
            reason: 'Migrated balance',
        ));

        $ledger->post($member, LedgerPosting::openingBalance(
            points: 80,
            idempotencyKey: 'open:later',
            occurredAt: now()->subDays(160),
            expiresAt: now()->addDays(120),
        ));

        $this->getJson('/api/admin/members/'.$member->id, $this->headersFor('viewer', 650003))
            ->assertOk()
            ->assertJsonPath('expiring_soon.points', 120)
            ->assertJsonPath('expiring_soon.window_days', 30);
    }

    public function test_the_ledger_returns_the_balance_after_each_movement(): void
    {
        $member = $this->margaret();
        $this->withAvailablePoints($member, 340);

        app(LedgerService::class)->post($member, LedgerPosting::adjustment(
            points: 50,
            bucket: 'available',
            reason: 'Goodwill gesture — damaged plant replacement',
            idempotencyKey: 'adj:manual',
            occurredAt: now(),
            staffId: 650004,
        ));

        $response = $this->getJson(
            '/api/admin/members/'.$member->id.'/ledger',
            $this->headersFor('viewer', 650004),
        )->assertOk();

        // Newest first, as the screen shows it.
        $response->assertJsonPath('data.0.entry_type', 'adjustment')
            ->assertJsonPath('data.0.points', 50)
            ->assertJsonPath('data.0.available_after', 390)
            ->assertJsonPath('data.0.status', 'available')
            ->assertJsonPath('data.1.entry_type', 'maturity')
            // A maturity creates no points; it moves them.
            ->assertJsonPath('data.1.points', 0)
            ->assertJsonPath('data.1.available_after', 340)
            ->assertJsonPath('data.2.entry_type', 'earn')
            ->assertJsonPath('data.2.available_after', 0)
            ->assertJsonPath('data.2.pending_after', 340)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.member.points_available', 390);

        // An adjustment has no order, so its reference is derived from the
        // entry; an earn carries the order name it came from.
        $this->assertStringStartsWith('ADJ-', $response->json('data.0.reference'));
        $this->assertStringStartsWith('#', (string) $response->json('data.2.reference'));
    }

    /**
     * Narrowing the view must not rewrite history: the running balance is the
     * account balance at that moment, not a total of the rows on screen.
     */
    public function test_filtering_the_ledger_does_not_change_the_running_balance(): void
    {
        $member = $this->margaret();
        $this->withAvailablePoints($member, 340);

        app(LedgerService::class)->post($member, LedgerPosting::adjustment(
            points: 50,
            bucket: 'available',
            reason: 'Goodwill gesture — agreed with the store manager',
            idempotencyKey: 'adj:filtered',
            occurredAt: now(),
            staffId: 650005,
        ));

        $this->getJson(
            '/api/admin/members/'.$member->id.'/ledger?type=adjustment',
            $this->headersFor('viewer', 650005),
        )
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.entry_type', 'adjustment')
            ->assertJsonPath('data.0.available_after', 390);
    }

    public function test_the_ledger_filters_by_type_channel_and_date(): void
    {
        $member = $this->margaret();
        $this->withAvailablePoints($member, 200);

        app(LedgerService::class)->post($member, LedgerPosting::adjustment(
            points: 25,
            bucket: 'available',
            reason: 'Correction — missed earning on an old order',
            idempotencyKey: 'adj:dated',
            occurredAt: now()->subDays(10),
            staffId: 650006,
        ));

        $headers = $this->headersFor('viewer', 650006);
        $base = '/api/admin/members/'.$member->id.'/ledger';

        $this->getJson($base.'?type=earn,maturity', $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->getJson($base.'?channel=admin', $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        // The earn is 31 days old, the adjustment 10, the maturity 1.
        $this->getJson($base.'?from='.now()->subDays(15)->toDateString(), $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->getJson($base, $headers)
            ->assertOk()
            ->assertJsonPath('meta.filters.channels', ['online', 'pos', 'admin', 'system', 'migration']);
    }

    public function test_an_unknown_ledger_filter_is_a_validation_failure(): void
    {
        $member = $this->margaret();

        $this->getJson(
            '/api/admin/members/'.$member->id.'/ledger?type=teleportation',
            $this->headersFor('viewer', 650007),
        )
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['type.0']]]);
    }

    public function test_a_manager_rebuilds_a_cache_and_the_change_is_audited(): void
    {
        $member = $this->margaret();
        $this->withAvailablePoints($member, 340);

        // Drift the cache the only way that can happen in practice: something
        // outside the ledger wrote to it.
        $member->forceFill(['points_available' => 999, 'voucher_balance_pence' => 4500])->save();

        $this->postJson(
            '/api/admin/members/'.$member->id.'/rebuild-cache',
            [],
            $this->headersFor('manager', 650008),
        )
            ->assertOk()
            ->assertJsonPath('before.points_available', 999)
            ->assertJsonPath('after.points_available', 340)
            ->assertJsonPath('after.voucher_balance_pence', 1500)
            ->assertJsonPath('changed', true);

        $entry = AuditEntry::where('action', AuditAction::BALANCE_REBUILT)->sole();

        $this->assertSame(999, $entry->before_state['points_available']);
        $this->assertSame(340, $entry->after_state['points_available']);
    }

    public function test_a_rebuild_that_changes_nothing_says_so(): void
    {
        $member = $this->margaret();
        $this->withAvailablePoints($member, 340);

        $this->postJson(
            '/api/admin/members/'.$member->id.'/rebuild-cache',
            [],
            $this->headersFor('manager', 650009),
        )
            ->assertOk()
            ->assertJsonPath('changed', false);
    }

    public function test_an_agent_cannot_rebuild_a_cache(): void
    {
        $member = $this->margaret();

        $this->postJson(
            '/api/admin/members/'.$member->id.'/rebuild-cache',
            [],
            $this->headersFor('agent', 650010),
        )
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'role_floor_not_met')
            ->assertJsonPath('error.details.required', 'manager')
            ->assertJsonPath('error.details.held', 'agent');
    }

    public function test_a_missing_member_is_a_clean_404(): void
    {
        $headers = $this->headersFor('manager', 650011);

        $this->getJson('/api/admin/members/999999', $headers)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');

        $this->getJson('/api/admin/members/999999/ledger', $headers)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');
    }
}
