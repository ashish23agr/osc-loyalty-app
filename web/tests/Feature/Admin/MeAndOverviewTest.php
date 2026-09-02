<?php

namespace Tests\Feature\Admin;

use App\Domain\Loyalty\LedgerPosting;
use App\Domain\Loyalty\LedgerService;
use App\Models\LoyaltyAccount;

/**
 * GET /api/admin/me and GET /api/admin/overview.
 *
 * The two calls the console makes before anything is on screen: who am I, and
 * what does the programme look like today.
 */
class MeAndOverviewTest extends AdminApiTestCase
{
    public function test_me_returns_the_role_and_the_permission_set(): void
    {
        $this->getJson('/api/admin/me', $this->headersFor('manager', 610001))
            ->assertOk()
            ->assertJsonPath('shop_domain', self::SHOP)
            ->assertJsonPath('staff.shopify_staff_id', 610001)
            ->assertJsonPath('staff.role', 'manager')
            ->assertJsonPath('permissions.view_members', true)
            ->assertJsonPath('permissions.adjust_points', true)
            ->assertJsonPath('permissions.view_audit', true)
            // A Manager acts on rewards but not on rules, per Blueprint 12.
            ->assertJsonPath('permissions.edit_rules', false)
            ->assertJsonPath('permissions.manage_staff', false)
            ->assertJsonPath('roles', ['viewer', 'agent', 'manager', 'administrator']);
    }

    public function test_a_viewer_is_told_they_may_change_nothing(): void
    {
        $this->getJson('/api/admin/me', $this->headersFor('viewer', 610002))
            ->assertOk()
            ->assertJsonPath('permissions.view_members', true)
            ->assertJsonPath('permissions.enrol_members', false)
            ->assertJsonPath('permissions.adjust_points', false)
            ->assertJsonPath('permissions.rebuild_caches', false)
            ->assertJsonPath('permissions.view_audit', false);
    }

    /**
     * The limit that actually applies, not just the override column, because
     * the modal has to show a number and an Agent with no override still has
     * one.
     */
    public function test_me_reports_the_adjustment_limit_that_applies(): void
    {
        $this->getJson('/api/admin/me', $this->headersFor('agent', 610003))
            ->assertOk()
            ->assertJsonPath('staff.adjustment_limit_points', null)
            ->assertJsonPath('staff.effective_adjustment_limit_points', 500);

        $this->getJson('/api/admin/me', $this->headersFor('agent', 610004, 2000))
            ->assertOk()
            ->assertJsonPath('staff.effective_adjustment_limit_points', 2000);

        // Manager and above are unrestricted.
        $this->getJson('/api/admin/me', $this->headersFor('manager', 610005))
            ->assertOk()
            ->assertJsonPath('staff.effective_adjustment_limit_points', null);
    }

    public function test_me_is_refused_without_a_session_token(): void
    {
        $this->getJson('/api/admin/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_session_token');
    }

    public function test_the_overview_counts_members_points_and_enrolments(): void
    {
        $withPoints = $this->member(['segment' => 'active']);
        $this->withAvailablePoints($withPoints, 340);

        $this->member(['email' => null, 'postcode' => 'SP4 6AB', 'segment' => 'lapsed']);
        $this->member(['enrolment_channel' => 'pos']);

        $response = $this->getJson('/api/admin/overview', $this->headersFor('viewer', 620001))->assertOk();

        $response->assertJsonPath('members.total', 3)
            ->assertJsonPath('members.without_email', 1)
            ->assertJsonPath('enrolments.last_30_days', 3)
            ->assertJsonPath('enrolments.by_channel.pos', 1)
            ->assertJsonPath('points.points_in_circulation', 340)
            ->assertJsonPath('points.points_pending', 0)
            // Zero, and correctly: the seeded earn is dated 31 days ago, one
            // day outside the window. The test below earns inside it.
            ->assertJsonPath('points.points_issued_last_30_days', 0)
            ->assertJsonPath('points.voucher_value_outstanding_pence', 1500)
            ->assertJsonPath('currency', 'GBP');

        $this->assertIsArray($response->json('recent_activity'));
        $this->assertNotEmpty($response->json('recent_activity'));
        $this->assertSame('maturity', $response->json('recent_activity.0.entry_type'));
    }

    /**
     * The dashboard figure, which is a window rather than a running total: an
     * earn inside thirty days counts and one outside it does not.
     */
    public function test_the_overview_counts_points_issued_over_thirty_days(): void
    {
        $member = $this->member();
        $ledger = app(LedgerService::class);

        $ledger->post($member, LedgerPosting::earn(
            points: 120,
            idempotencyKey: 'earn:inside',
            occurredAt: now()->subDays(5),
            maturesAt: now()->addDays(25),
            expiresAt: now()->addDays(207),
        ));

        $ledger->post($member, LedgerPosting::earn(
            points: 500,
            idempotencyKey: 'earn:outside',
            occurredAt: now()->subDays(45),
            maturesAt: now()->subDays(15),
            expiresAt: now()->addDays(167),
        ));

        $this->getJson('/api/admin/overview', $this->headersFor('viewer', 620004))
            ->assertOk()
            ->assertJsonPath('points.points_issued_last_30_days', 120)
            // Both earnings are still pending, so the running figure counts them
            // both. The two are different questions.
            ->assertJsonPath('points.points_pending', 620);
    }

    /**
     * Job health is counted from the ledger, so a queue that stopped shows as
     * work piling up rather than as a job that simply never ran.
     */
    public function test_the_overview_reports_maturities_the_job_has_not_processed(): void
    {
        $member = $this->member();

        app(LedgerService::class)->post($member, LedgerPosting::earn(
            points: 120,
            idempotencyKey: 'earn:overdue',
            occurredAt: now()->subDays(40),
            maturesAt: now()->subDays(10),
            expiresAt: now()->addDays(170),
        ));

        $this->getJson('/api/admin/overview', $this->headersFor('viewer', 620002))
            ->assertOk()
            ->assertJsonPath('jobs.overdue_maturities.entries', 1)
            ->assertJsonPath('jobs.overdue_maturities.points', 120);
    }

    public function test_the_overview_never_sees_another_shop(): void
    {
        LoyaltyAccount::create([
            'shop_domain' => 'other-store.myshopify.com',
            'email' => 'elsewhere@example.com',
            'enrolment_channel' => 'online',
            'enrolled_at' => now(),
        ]);

        $this->member();

        $this->getJson('/api/admin/overview', $this->headersFor('viewer', 620003))
            ->assertOk()
            ->assertJsonPath('members.total', 1);
    }
}
