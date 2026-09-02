<?php

namespace Tests\Feature\Admin;

use App\Models\AuditEntry;
use App\Models\StaffRole;
use App\Support\Audit\AuditAction;

/**
 * GET /api/admin/staff and PUT /api/admin/staff/{staffId}.
 *
 * Administrator at both ends. The rule that matters most is the one that
 * refuses to remove the last Administrator: a shop with none can no longer
 * change its own rules or its own roles, and recovering from that needs a
 * developer rather than a merchant.
 */
class StaffEndpointTest extends AdminApiTestCase
{
    private function owner(): array
    {
        return $this->asStaff(self::OWNER);
    }

    public function test_the_staff_list_shows_roles_limits_and_permissions(): void
    {
        $this->staffWith('agent', 690001, 2000);
        $this->staffWith('viewer', 690002);

        $response = $this->getJson('/api/admin/staff', $this->owner())->assertOk();

        $response->assertJsonCount(3, 'data')
            // Sorted by rank, not by the enum column, so the Administrator is
            // not filed under V for Viewer.
            ->assertJsonPath('data.0.role', 'administrator')
            ->assertJsonPath('data.0.is_you', true)
            ->assertJsonPath('meta.roles', ['viewer', 'agent', 'manager', 'administrator'])
            ->assertJsonPath('meta.agent_adjustment_limit_points', 500)
            ->assertJsonPath('meta.administrator_count', 1);

        $agent = collect($response->json('data'))->firstWhere('shopify_staff_id', 690001);

        $this->assertSame('agent', $agent['role']);
        $this->assertSame(2000, $agent['adjustment_limit_points']);
        $this->assertSame(2000, $agent['effective_adjustment_limit_points']);
        $this->assertTrue($agent['permissions']['adjust_points']);
        $this->assertFalse($agent['permissions']['edit_rules']);
        $this->assertFalse($agent['is_you']);

        $viewer = collect($response->json('data'))->firstWhere('shopify_staff_id', 690002);

        // No override, and a Viewer holds no adjustment permission anyway.
        $this->assertNull($viewer['adjustment_limit_points']);
        $this->assertSame(500, $viewer['effective_adjustment_limit_points']);
    }

    public function test_an_administrator_assigns_a_role_and_labels_the_person(): void
    {
        $this->putJson('/api/admin/staff/690010', [
            'role' => 'manager',
            'staff_name' => 'N. Mehra',
            'staff_email' => 'n.mehra@example.com',
        ], $this->owner())
            ->assertOk()
            ->assertJsonPath('shopify_staff_id', 690010)
            ->assertJsonPath('role', 'manager')
            ->assertJsonPath('staff_name', 'N. Mehra')
            ->assertJsonPath('assigned_by_staff_id', self::OWNER)
            // A Manager is unrestricted on adjustments, per Blueprint 12.
            ->assertJsonPath('effective_adjustment_limit_points', null)
            ->assertJsonPath('permissions.view_audit', true)
            ->assertJsonPath('permissions.edit_rules', false);

        $this->assertSame('manager', StaffRole::where('shopify_staff_id', 690010)->sole()->role);
    }

    public function test_an_assignment_is_audited_with_the_role_that_was_held_before(): void
    {
        $this->putJson('/api/admin/staff/690011', ['role' => 'viewer'], $this->owner())->assertOk();
        $this->putJson('/api/admin/staff/690011', ['role' => 'manager'], $this->owner())->assertOk();

        $entries = AuditEntry::where('action', AuditAction::STAFF_ROLE_ASSIGNED)
            ->orderBy('id')
            ->get()
            ->filter(fn (AuditEntry $entry): bool => ($entry->after_state['role'] ?? null) !== null)
            ->values();

        $promotion = $entries->last();

        $this->assertSame('viewer', $promotion->before_state['role']);
        $this->assertSame('manager', $promotion->after_state['role']);
        $this->assertSame(self::OWNER, (int) $promotion->actor_staff_id);
    }

    public function test_a_personal_adjustment_limit_can_be_set_and_cleared(): void
    {
        $this->putJson('/api/admin/staff/690012', [
            'role' => 'agent',
            'adjustment_limit_points' => 2000,
        ], $this->owner())
            ->assertOk()
            ->assertJsonPath('adjustment_limit_points', 2000)
            ->assertJsonPath('effective_adjustment_limit_points', 2000);

        // Sent as null, which means "clear the override", not "leave it alone".
        $this->putJson('/api/admin/staff/690012', [
            'role' => 'agent',
            'adjustment_limit_points' => null,
        ], $this->owner())
            ->assertOk()
            ->assertJsonPath('adjustment_limit_points', null)
            ->assertJsonPath('effective_adjustment_limit_points', 500);
    }

    public function test_the_last_administrator_cannot_be_demoted(): void
    {
        $this->putJson('/api/admin/staff/'.self::OWNER, ['role' => 'viewer'], $this->owner())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'last_administrator');

        $this->assertSame('administrator', StaffRole::where('shopify_staff_id', self::OWNER)->sole()->role);
    }

    public function test_once_a_second_administrator_exists_the_first_may_step_down(): void
    {
        $this->putJson('/api/admin/staff/690013', ['role' => 'administrator'], $this->owner())->assertOk();

        $this->putJson('/api/admin/staff/'.self::OWNER, ['role' => 'manager'], $this->owner())
            ->assertOk()
            ->assertJsonPath('role', 'manager');
    }

    public function test_an_unknown_role_is_a_validation_failure(): void
    {
        $this->putJson('/api/admin/staff/690014', ['role' => 'superuser'], $this->owner())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['role']]]);

        $this->putJson('/api/admin/staff/690014', [], $this->owner())
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['role']]]);

        $this->putJson('/api/admin/staff/690014', [
            'role' => 'agent',
            'staff_email' => 'not-an-email',
        ], $this->owner())
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['staff_email']]]);

        $this->assertSame(0, StaffRole::where('shopify_staff_id', 690014)->count());
    }

    public function test_a_manager_can_neither_list_nor_assign_roles(): void
    {
        $headers = $this->headersFor('manager', 690015);

        $this->getJson('/api/admin/staff', $headers)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'role_floor_not_met')
            ->assertJsonPath('error.details.required', 'administrator')
            ->assertJsonPath('error.details.held', 'manager');

        $this->putJson('/api/admin/staff/690016', ['role' => 'administrator'], $headers)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'role_floor_not_met');

        $this->assertSame(0, StaffRole::where('shopify_staff_id', 690016)->count());
    }

    public function test_the_list_never_shows_another_shops_staff(): void
    {
        StaffRole::create([
            'shop_domain' => 'other-store.myshopify.com',
            'shopify_staff_id' => 690017,
            'role' => 'administrator',
        ]);

        $response = $this->getJson('/api/admin/staff', $this->owner())->assertOk();

        $this->assertNotContains(690017, array_column($response->json('data'), 'shopify_staff_id'));
    }
}
