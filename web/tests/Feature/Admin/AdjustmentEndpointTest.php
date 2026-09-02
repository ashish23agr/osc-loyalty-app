<?php

namespace Tests\Feature\Admin;

use App\Models\AuditEntry;
use App\Models\LedgerEntry;
use App\Support\Audit\AuditAction;

/**
 * POST /api/admin/members/{id}/adjustments.
 *
 * The only Sprint 1 endpoint that both moves points and is initiated by a
 * person, so it carries every control at once: a role floor, a per-person
 * limit, a mandatory reason, an audit entry in the same transaction, and
 * idempotency so a retry cannot adjust twice.
 */
class AdjustmentEndpointTest extends AdminApiTestCase
{
    private function memberWith340Points()
    {
        $member = $this->member([
            'email' => 'm.whitfield@example.co.uk',
            'first_name' => 'Margaret',
            'last_name' => 'Whitfield',
        ]);

        $this->withAvailablePoints($member, 340);

        return $member;
    }

    private function validBody(array $overrides = []): array
    {
        return array_merge([
            'direction' => 'add',
            'points' => 50,
            'reason_category' => 'goodwill',
            'notes' => 'Replacement for a damaged acer, agreed with the store manager (case 4471).',
        ], $overrides);
    }

    /**
     * The projection the modal shows before anything is saved: "New balance
     * will be 390 points, 15 pounds available".
     */
    public function test_a_preview_projects_the_new_balance_and_writes_nothing(): void
    {
        $member = $this->memberWith340Points();

        $this->postJson(
            '/api/admin/members/'.$member->id.'/adjustments',
            ['direction' => 'add', 'points' => 50, 'preview' => true],
            $this->headersFor('agent', 660001),
        )
            ->assertOk()
            ->assertJsonPath('preview', true)
            ->assertJsonPath('projection.points_available_before', 340)
            ->assertJsonPath('projection.points_available_after', 390)
            ->assertJsonPath('projection.voucher_balance_pence_before', 1500)
            ->assertJsonPath('projection.voucher_balance_pence_after', 1500)
            ->assertJsonPath('projection.voucher_increments_after', 3)
            ->assertJsonPath('projection.remainder_points_after', 90)
            ->assertJsonPath('projection.points_to_next_increment_after', 10)
            ->assertJsonPath('limit.points', 500)
            ->assertJsonPath('limit.exceeded', false)
            ->assertJsonPath('minimum_note_length', 10);

        $this->assertSame(340, $member->refresh()->points_available);
        $this->assertSame(0, LedgerEntry::where('entry_type', 'adjustment')->count());
        $this->assertSame(0, AuditEntry::where('action', AuditAction::POINTS_ADJUSTED)->count());
    }

    /** A preview does not demand a reason: nobody has typed one yet. */
    public function test_a_preview_needs_no_reason(): void
    {
        $member = $this->memberWith340Points();

        $this->postJson(
            '/api/admin/members/'.$member->id.'/adjustments',
            ['direction' => 'deduct', 'points' => 100, 'preview' => true],
            $this->headersFor('agent', 660002),
        )
            ->assertOk()
            ->assertJsonPath('projection.points_available_after', 240)
            ->assertJsonPath('projection.voucher_balance_pence_after', 1000);
    }

    /**
     * Over the limit is reported by a preview, not refused, so the modal can
     * disable Save and say why rather than letting someone type a reason for an
     * adjustment that was never going to be accepted.
     */
    public function test_a_preview_reports_an_over_limit_adjustment_without_refusing_it(): void
    {
        $member = $this->memberWith340Points();

        $this->postJson(
            '/api/admin/members/'.$member->id.'/adjustments',
            ['direction' => 'add', 'points' => 900, 'preview' => true],
            $this->headersFor('agent', 660003),
        )
            ->assertOk()
            ->assertJsonPath('limit.exceeded', true)
            ->assertJsonPath('limit.points', 500)
            ->assertJsonPath('limit.requested_points', 900);
    }

    public function test_an_agent_adds_points_and_the_response_carries_the_new_balance(): void
    {
        $member = $this->memberWith340Points();

        $response = $this->postJson(
            '/api/admin/members/'.$member->id.'/adjustments',
            $this->validBody(),
            $this->headersFor('agent', 660004),
        );

        $response->assertStatus(201)
            ->assertJsonPath('preview', false)
            ->assertJsonPath('entry.entry_type', 'adjustment')
            ->assertJsonPath('entry.points', 50)
            ->assertJsonPath('entry.channel', 'admin')
            ->assertJsonPath('balances.points_available', 390)
            ->assertJsonPath('balances.voucher_balance_pence', 1500)
            ->assertJsonPath('balances.points_to_next_increment', 10)
            // The same projection block the preview returned, so the
            // confirmation and the preview cannot tell different stories.
            ->assertJsonPath('projection.points_available_after', 390)
            ->assertJsonPath('idempotent_replay', false);

        $this->assertStringStartsWith('ADJ-', $response->json('reference'));
        $this->assertSame(390, $member->refresh()->points_available);
    }

    public function test_a_deduction_is_the_same_endpoint_with_the_other_direction(): void
    {
        $member = $this->memberWith340Points();

        $this->postJson(
            '/api/admin/members/'.$member->id.'/adjustments',
            $this->validBody(['direction' => 'deduct', 'points' => 40]),
            $this->headersFor('agent', 660005),
        )
            ->assertStatus(201)
            ->assertJsonPath('entry.points', -40)
            ->assertJsonPath('balances.points_available', 300)
            ->assertJsonPath('balances.voucher_balance_pence', 1500);
    }

    public function test_the_reason_reaches_both_the_ledger_and_the_audit_log(): void
    {
        $member = $this->memberWith340Points();

        $this->postJson(
            '/api/admin/members/'.$member->id.'/adjustments',
            $this->validBody(),
            $this->headersFor('agent', 660006),
        )->assertStatus(201);

        $entry = LedgerEntry::where('entry_type', 'adjustment')->sole();
        $audit = AuditEntry::where('action', AuditAction::POINTS_ADJUSTED)->sole();

        $this->assertStringStartsWith('Goodwill gesture', $entry->reason);
        $this->assertStringContainsString('damaged acer', $entry->reason);

        // The member record and the audit log tell the same story.
        $this->assertSame($entry->reason, $audit->reason);
        $this->assertSame(340, $audit->before_state['points_available']);
        $this->assertSame(390, $audit->after_state['points_available']);
        $this->assertSame('goodwill', $audit->after_state['reason_category']);
        $this->assertSame((int) $entry->id, $audit->after_state['ledger_entry_id']);
        $this->assertSame(660006, (int) $audit->actor_staff_id);
    }

    public function test_an_adjustment_without_a_reason_is_refused(): void
    {
        $member = $this->memberWith340Points();
        $headers = $this->headersFor('agent', 660007);
        $url = '/api/admin/members/'.$member->id.'/adjustments';

        $this->postJson($url, ['direction' => 'add', 'points' => 50], $headers)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['reason_category', 'notes']]]);

        // A category with no notes is not an explanation either.
        $this->postJson($url, ['direction' => 'add', 'points' => 50, 'reason_category' => 'goodwill'], $headers)
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['notes']]]);

        // Nor is "fixed it".
        $this->postJson($url, $this->validBody(['notes' => 'fixed it']), $headers)
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['notes']]]);

        // Nor is whitespace dressed up as ten characters.
        $this->postJson($url, $this->validBody(['notes' => '          ']), $headers)
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['notes']]]);

        $this->assertSame(0, LedgerEntry::where('entry_type', 'adjustment')->count());
    }

    public function test_an_unknown_reason_category_is_refused(): void
    {
        $member = $this->memberWith340Points();

        $this->postJson(
            '/api/admin/members/'.$member->id.'/adjustments',
            $this->validBody(['reason_category' => 'because']),
            $this->headersFor('agent', 660008),
        )
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['reason_category']]]);
    }

    public function test_a_zero_or_negative_points_value_is_refused(): void
    {
        $member = $this->memberWith340Points();
        $headers = $this->headersFor('agent', 660009);
        $url = '/api/admin/members/'.$member->id.'/adjustments';

        $this->postJson($url, $this->validBody(['points' => 0]), $headers)
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['points']]]);

        // The direction carries the sign; a negative number here is ambiguous.
        $this->postJson($url, $this->validBody(['points' => -50]), $headers)
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['points']]]);

        $this->postJson($url, $this->validBody(['direction' => 'sideways']), $headers)
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['direction']]]);
    }

    public function test_an_agent_over_their_limit_is_refused_with_the_limit_in_the_details(): void
    {
        $member = $this->memberWith340Points();

        $this->postJson(
            '/api/admin/members/'.$member->id.'/adjustments',
            $this->validBody(['points' => 900]),
            $this->headersFor('agent', 660010),
        )
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'adjustment_limit_exceeded')
            ->assertJsonPath('error.details.requested_points', 900)
            ->assertJsonPath('error.details.limit_points', 500);

        $this->assertSame(340, $member->refresh()->points_available);
    }

    /** The limit is on the size of the movement, not its direction. */
    public function test_the_limit_applies_to_deductions_too(): void
    {
        $member = $this->memberWith340Points();

        $this->postJson(
            '/api/admin/members/'.$member->id.'/adjustments',
            $this->validBody(['direction' => 'deduct', 'points' => 900]),
            $this->headersFor('agent', 660011),
        )
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'adjustment_limit_exceeded');
    }

    public function test_a_personal_override_beats_the_rule_default(): void
    {
        $member = $this->memberWith340Points();

        $this->postJson(
            '/api/admin/members/'.$member->id.'/adjustments',
            $this->validBody(['points' => 900]),
            $this->headersFor('agent', 660012, 2000),
        )
            ->assertStatus(201)
            ->assertJsonPath('limit.points', 2000)
            ->assertJsonPath('balances.points_available', 1240);
    }

    public function test_a_manager_is_unrestricted(): void
    {
        $member = $this->memberWith340Points();

        $this->postJson(
            '/api/admin/members/'.$member->id.'/adjustments',
            $this->validBody(['points' => 50000]),
            $this->headersFor('manager', 660013),
        )
            ->assertStatus(201)
            ->assertJsonPath('limit.points', null)
            ->assertJsonPath('limit.exceeded', false);
    }

    public function test_a_repeated_idempotency_key_returns_the_original_adjustment(): void
    {
        $member = $this->memberWith340Points();
        $headers = $this->headersFor('agent', 660014) + ['Idempotency-Key' => 'console-retry-0001'];

        $first = $this->postJson(
            '/api/admin/members/'.$member->id.'/adjustments',
            $this->validBody(),
            $headers,
        )->assertStatus(201);

        $second = $this->postJson(
            '/api/admin/members/'.$member->id.'/adjustments',
            $this->validBody(),
            $headers,
        )->assertOk();

        $this->assertSame($first->json('entry.id'), $second->json('entry.id'));
        $this->assertTrue($second->json('idempotent_replay'));

        // The points moved once, and the log records it once.
        $this->assertSame(390, $member->refresh()->points_available);
        $this->assertSame(1, LedgerEntry::where('entry_type', 'adjustment')->count());
        $this->assertSame(1, AuditEntry::where('action', AuditAction::POINTS_ADJUSTED)->count());
    }

    public function test_a_merged_account_refuses_a_posting(): void
    {
        $surviving = $this->member(['email' => 'surviving@example.com']);
        $merged = $this->memberWith340Points();

        $merged->forceFill([
            'status' => 'merged',
            'merged_into_account_id' => $surviving->id,
        ])->save();

        $this->postJson(
            '/api/admin/members/'.$merged->id.'/adjustments',
            $this->validBody(),
            $this->headersFor('agent', 660015),
        )
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'account_merged')
            ->assertJsonPath('error.details.account_id', $merged->id)
            ->assertJsonPath('error.details.merged_into_account_id', $surviving->id);

        $this->assertSame(0, LedgerEntry::where('entry_type', 'adjustment')->count());
    }

    public function test_a_viewer_cannot_adjust_points(): void
    {
        $member = $this->memberWith340Points();

        $this->postJson(
            '/api/admin/members/'.$member->id.'/adjustments',
            $this->validBody(),
            $this->headersFor('viewer', 660016),
        )
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'role_floor_not_met')
            ->assertJsonPath('error.details.required', 'agent')
            ->assertJsonPath('error.details.held', 'viewer');

        $this->assertSame(0, LedgerEntry::where('entry_type', 'adjustment')->count());
    }

    /**
     * C10, built to the recommendation: adjusted points behave like earned
     * ones rather than living on the account for ever.
     */
    public function test_adjusted_points_carry_an_expiry_from_the_rule_version(): void
    {
        $member = $this->memberWith340Points();

        $this->postJson(
            '/api/admin/members/'.$member->id.'/adjustments',
            $this->validBody(),
            $this->headersFor('agent', 660017),
        )->assertStatus(201);

        $entry = LedgerEntry::where('entry_type', 'adjustment')->sole();

        $this->assertNotNull($entry->expires_at);
        $this->assertSame(182, (int) round(now()->diffInDays($entry->expires_at)));
    }

    public function test_a_deduction_carries_no_expiry(): void
    {
        $member = $this->memberWith340Points();

        $this->postJson(
            '/api/admin/members/'.$member->id.'/adjustments',
            $this->validBody(['direction' => 'deduct']),
            $this->headersFor('agent', 660018),
        )->assertStatus(201);

        $this->assertNull(LedgerEntry::where('entry_type', 'adjustment')->sole()->expires_at);
    }
}
