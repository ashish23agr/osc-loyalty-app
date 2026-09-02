<?php

namespace Tests\Feature\Admin;

use App\Domain\Rules\RuleSet;
use App\Models\AuditEntry;
use App\Support\Audit\AuditAction;

/**
 * GET /api/admin/audit.
 *
 * The screen the client asked for by name: who did what, when, what the value
 * was before and after, and why. Filterable by user, by action and by date
 * range, because the question is almost never "show me everything".
 *
 * The entries here are produced by actually using the other endpoints rather
 * than inserted directly, so what the screen shows is what the application
 * really writes.
 */
class AuditEndpointTest extends AdminApiTestCase
{
    private function makeSomeHistory(): array
    {
        $member = $this->member([
            'email' => 'm.whitfield@example.co.uk',
            'first_name' => 'Margaret',
            'last_name' => 'Whitfield',
        ]);

        $this->withAvailablePoints($member, 340);

        $agent = $this->headersFor('agent', 680101);
        $administrator = $this->headersFor('administrator', 680102);

        $this->postJson('/api/admin/members/'.$member->id.'/adjustments', [
            'direction' => 'add',
            'points' => 50,
            'reason_category' => 'goodwill',
            'notes' => 'Replacement for a damaged acer, agreed with the store manager.',
        ], $agent)->assertStatus(201);

        $this->postJson('/api/admin/rules', [
            'rules' => RuleSet::defaults(['points_expiry_days' => 365]),
            'change_summary' => 'Board decision August 2026',
        ], $administrator)->assertStatus(201);

        return [$member, $agent, $administrator];
    }

    public function test_the_log_returns_the_actor_the_change_and_the_reason(): void
    {
        [$member] = $this->makeSomeHistory();

        $response = $this->getJson(
            '/api/admin/audit?action=points.adjusted',
            $this->headersFor('manager', 680001),
        )->assertOk();

        $response->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.action', 'points.adjusted')
            ->assertJsonPath('data.0.subject_type', 'LoyaltyAccount')
            ->assertJsonPath('data.0.subject_id', $member->id)
            ->assertJsonPath('data.0.actor.staff_id', 680101)
            ->assertJsonPath('data.0.actor.type', 'staff')
            // Looked up now rather than frozen at write time, so the screen
            // shows the role the person actually holds.
            ->assertJsonPath('data.0.actor.role', 'agent')
            ->assertJsonPath('data.0.channel', 'admin')
            ->assertJsonPath('data.0.before_state.points_available', 340)
            ->assertJsonPath('data.0.after_state.points_available', 390);

        $this->assertStringContainsString('damaged acer', $response->json('data.0.reason'));

        // The flattened before-to-after column the screen renders.
        $changes = collect($response->json('data.0.changes'))->keyBy('field');
        $this->assertSame(340, $changes['points_available']['from']);
        $this->assertSame(390, $changes['points_available']['to']);
    }

    public function test_the_log_filters_by_action_actor_and_date(): void
    {
        $this->makeSomeHistory();
        $headers = $this->headersFor('manager', 680002);

        $this->getJson('/api/admin/audit?action=rules.saved', $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.actor.staff_id', 680102);

        $this->getJson('/api/admin/audit?action=points.adjusted,rules.saved', $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/admin/audit?actor_staff_id=680101', $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.action', 'points.adjusted');

        // Everything happened today, so a window that ends yesterday is empty.
        $this->getJson('/api/admin/audit?to='.now()->subDay()->toDateString(), $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        // And one that includes today is not. Narrowed to the two actions
        // under test, because assigning the roles this test needs is itself
        // audited and would otherwise be counted.
        $this->getJson(
            '/api/admin/audit?action=points.adjusted,rules.saved'
                .'&from='.now()->toDateString().'&to='.now()->toDateString(),
            $headers,
        )
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_the_log_searches_the_actor_name_and_the_reason(): void
    {
        $this->makeSomeHistory();
        $headers = $this->headersFor('manager', 680003);

        $this->getJson('/api/admin/audit?q=damaged+acer', $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.action', 'points.adjusted');

        $this->getJson('/api/admin/audit?q=Board+decision', $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.action', 'rules.saved');
    }

    public function test_the_filter_vocabularies_come_back_with_the_page(): void
    {
        $this->makeSomeHistory();

        $response = $this->getJson('/api/admin/audit', $this->headersFor('manager', 680004))->assertOk();

        $this->assertContains('points.adjusted', $response->json('meta.filters.actions'));
        $this->assertContains('rules.saved', $response->json('meta.filters.actions'));
        $this->assertContains('staff.bootstrapped', $response->json('meta.filters.actions'));

        $actorIds = array_column($response->json('meta.filters.actors'), 'staff_id');
        $this->assertContains(680101, $actorIds);
        $this->assertContains(680102, $actorIds);
    }

    public function test_an_unknown_action_filter_is_a_validation_failure(): void
    {
        $this->getJson('/api/admin/audit?action=points.vanished', $this->headersFor('manager', 680005))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['action.0']]]);
    }

    public function test_an_agent_cannot_read_the_audit_log(): void
    {
        $this->getJson('/api/admin/audit', $this->headersFor('agent', 680006))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'role_floor_not_met')
            ->assertJsonPath('error.details.required', 'manager')
            ->assertJsonPath('error.details.held', 'agent');
    }

    public function test_the_log_never_shows_another_shops_entries(): void
    {
        $this->makeSomeHistory();

        AuditEntry::create([
            'shop_domain' => 'other-store.myshopify.com',
            'actor_type' => 'staff',
            'actor_staff_id' => 999,
            'actor_name' => 'Someone Else',
            'action' => AuditAction::POINTS_ADJUSTED,
            'subject_type' => 'LoyaltyAccount',
            'subject_id' => 1,
            'reason' => 'Not this shop',
            'channel' => 'admin',
        ]);

        $response = $this->getJson(
            '/api/admin/audit?action=points.adjusted',
            $this->headersFor('manager', 680007),
        )->assertOk();

        $response->assertJsonPath('meta.total', 1);
        $this->assertSame(680101, $response->json('data.0.actor.staff_id'));
    }
}
