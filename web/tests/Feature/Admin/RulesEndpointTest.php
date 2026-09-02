<?php

namespace Tests\Feature\Admin;

use App\Domain\Rules\RuleSet;
use App\Models\AuditEntry;
use App\Models\RulesVersion;
use App\Support\Audit\AuditAction;

/**
 * GET /api/admin/rules, GET /api/admin/rules/versions/{version} and
 * POST /api/admin/rules.
 *
 * A save is a new version, never an edit. That is what lets the settings screen
 * promise a change is not retrospective and mean it, and it is why the tests
 * here care more about what happens to the previous version than about what the
 * new one contains.
 */
class RulesEndpointTest extends AdminApiTestCase
{
    private function payload(array $overrides = []): array
    {
        return RuleSet::defaults($overrides);
    }

    public function test_the_current_rules_and_the_version_list_come_back_together(): void
    {
        $response = $this->getJson('/api/admin/rules', $this->headersFor('viewer', 670001))->assertOk();

        $response->assertJsonPath('current.version', 1)
            ->assertJsonPath('current.payload.earn_points_per_pound', 1)
            ->assertJsonPath('current.payload.voucher_threshold_points', 100)
            ->assertJsonPath('current.payload.voucher_value_pence', 500)
            ->assertJsonPath('current.payload.pending_period_days', 30)
            ->assertJsonPath('current.payload.points_expiry_days', 182)
            ->assertJsonPath('editable_by', 'administrator')
            ->assertJsonCount(1, 'versions');
    }

    public function test_an_administrator_saves_a_new_version(): void
    {
        $response = $this->postJson('/api/admin/rules', [
            'rules' => $this->payload(['points_expiry_days' => 365, 'earn_points_per_pound' => 2]),
            'change_summary' => 'Board decision August 2026',
        ], $this->headersFor('administrator', 670002));

        $response->assertStatus(201)
            ->assertJsonPath('version', 2)
            ->assertJsonPath('previous_version', 1)
            ->assertJsonPath('payload.points_expiry_days', 365)
            ->assertJsonPath('change_summary', 'Board decision August 2026')
            ->assertJsonPath('created_by_staff_id', 670002)
            ->assertJsonPath('diff.points_expiry_days.from', 182)
            ->assertJsonPath('diff.points_expiry_days.to', 365)
            ->assertJsonPath('diff.earn_points_per_pound.to', 2);

        // The old version is still there and untouched.
        $this->assertSame(2, RulesVersion::where('shop_domain', self::SHOP)->count());
        $this->assertSame(182, RulesVersion::where('version', 1)->sole()->payload['points_expiry_days']);
    }

    public function test_a_save_is_audited_with_only_what_moved(): void
    {
        $this->postJson('/api/admin/rules', [
            'rules' => $this->payload(['points_expiry_days' => 365]),
            'change_summary' => 'Board decision August 2026',
        ], $this->headersFor('administrator', 670003))->assertStatus(201);

        $entry = AuditEntry::where('action', AuditAction::RULES_SAVED)->sole();

        $this->assertSame(['points_expiry_days' => 182], $entry->before_state);
        $this->assertSame(365, $entry->after_state['points_expiry_days']);
        $this->assertSame(2, $entry->after_state['version']);
        $this->assertSame('Board decision August 2026', $entry->reason);
        $this->assertSame(670003, (int) $entry->actor_staff_id);
    }

    /**
     * A version is a full snapshot by definition. Merging a partial payload
     * would silently carry forward a value the person saving never saw.
     */
    public function test_a_partial_payload_is_rejected_rather_than_merged(): void
    {
        $this->postJson('/api/admin/rules', [
            'rules' => ['earn_points_per_pound' => 2],
        ], $this->headersFor('administrator', 670004))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['voucher_threshold_points']]]);

        $this->assertSame(1, RulesVersion::where('shop_domain', self::SHOP)->count());
    }

    public function test_no_payload_at_all_is_rejected(): void
    {
        $this->postJson('/api/admin/rules', [], $this->headersFor('administrator', 670005))
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['rules']]]);
    }

    /**
     * The cross-field rules from RuleSchema, reached through HTTP: points that
     * expire before they mature, and a per-order cap below one increment.
     */
    public function test_a_self_contradictory_rule_set_is_rejected(): void
    {
        $headers = $this->headersFor('administrator', 670006);

        $this->postJson('/api/admin/rules', [
            'rules' => $this->payload(['pending_period_days' => 90, 'points_expiry_days' => 60]),
        ], $headers)
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['points_expiry_days']]]);

        $this->postJson('/api/admin/rules', [
            'rules' => $this->payload(['voucher_value_pence' => 500, 'max_redemption_per_order_pence' => 100]),
        ], $headers)
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['max_redemption_per_order_pence']]]);
    }

    public function test_a_historical_version_comes_back_with_its_difference(): void
    {
        $headers = $this->headersFor('administrator', 670007);

        $this->postJson('/api/admin/rules', [
            'rules' => $this->payload(['points_expiry_days' => 365]),
        ], $headers)->assertStatus(201);

        $this->getJson('/api/admin/rules/versions/1', $headers)
            ->assertOk()
            ->assertJsonPath('version', 1)
            ->assertJsonPath('payload.points_expiry_days', 182)
            ->assertJsonPath('previous_version', null)
            ->assertJsonPath('diff', []);

        $this->getJson('/api/admin/rules/versions/2', $headers)
            ->assertOk()
            ->assertJsonPath('version', 2)
            ->assertJsonPath('diff.points_expiry_days.from', 182);
    }

    public function test_an_unknown_version_is_a_clean_404(): void
    {
        $this->getJson('/api/admin/rules/versions/99', $this->headersFor('viewer', 670008))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_a_manager_may_read_the_rules_but_not_save_them(): void
    {
        $headers = $this->headersFor('manager', 670009);

        $this->getJson('/api/admin/rules', $headers)->assertOk();

        $this->postJson('/api/admin/rules', ['rules' => $this->payload()], $headers)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'role_floor_not_met')
            ->assertJsonPath('error.details.required', 'administrator')
            ->assertJsonPath('error.details.held', 'manager');

        $this->assertSame(1, RulesVersion::where('shop_domain', self::SHOP)->count());
    }

    public function test_a_shop_never_reads_another_shops_rules(): void
    {
        RulesVersion::create([
            'shop_domain' => 'other-store.myshopify.com',
            'version' => 1,
            'payload' => $this->payload(['earn_points_per_pound' => 99]),
            'effective_from' => now()->subYear(),
        ]);

        $this->getJson('/api/admin/rules', $this->headersFor('viewer', 670010))
            ->assertOk()
            ->assertJsonPath('current.payload.earn_points_per_pound', 1)
            ->assertJsonCount(1, 'versions');
    }
}
