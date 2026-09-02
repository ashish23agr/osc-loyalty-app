<?php

namespace Tests\Feature\Admin;

use App\Models\AuditEntry;
use App\Models\LoyaltyAccount;
use App\Support\Audit\AuditAction;

/**
 * POST /api/admin/members and PATCH /api/admin/members/{id}.
 *
 * Enrolment from the console and correction of a profile. The two client
 * confirmations that shape both are MD1 (an email address is optional) and MD2
 * (a birthday may be a day and a month with no year), and the rule that shapes
 * the correction is that the email address is the identifier and is therefore
 * not editable.
 */
class MemberWriteTest extends AdminApiTestCase
{
    public function test_an_agent_enrols_a_member(): void
    {
        $response = $this->postJson('/api/admin/members', [
            'email' => 'New.Member@Example.COM',
            'first_name' => 'Rachel',
            'last_name' => 'Peterson',
            'postcode' => 'sp2 9ll',
            'dob_month' => 9,
            'dob_day' => 12,
            'email_marketing_consent' => true,
        ], $this->headersFor('agent', 640001));

        $response->assertStatus(201)
            ->assertJsonPath('display_name', 'Rachel Peterson')
            ->assertJsonPath('has_no_email', false)
            ->assertJsonPath('has_birthday', true)
            ->assertJsonPath('dob_month', 9)
            ->assertJsonPath('dob_day', 12)
            ->assertJsonPath('date_of_birth', null)
            // Fixed by the endpoint, never taken from the request, so the
            // online-versus-in-store split in R2 stays meaningful.
            ->assertJsonPath('enrolment_channel', 'admin')
            ->assertJsonPath('balances.points_available', 0)
            ->assertJsonPath('balances.voucher_balance_pence', 0);

        $member = LoyaltyAccount::find($response->json('id'));

        $this->assertSame('new.member@example.com', $member->email_normalised);
        $this->assertSame('SP29LL', $member->postcode_normalised);
        $this->assertNotNull($member->consent_updated_at);
    }

    /** MD1: no email address, found by card and by postcode plus surname. */
    public function test_a_member_with_no_email_can_be_enrolled(): void
    {
        $response = $this->postJson('/api/admin/members', [
            'last_name' => 'Smith',
            'postcode' => 'SP2 9LL',
            'legacy_card_number' => 'D-077310',
        ], $this->headersFor('agent', 640002));

        $response->assertStatus(201)
            ->assertJsonPath('has_no_email', true)
            ->assertJsonPath('email', null);

        // Two of them coexist under the unique index on the normalised email.
        $this->postJson('/api/admin/members', [
            'last_name' => 'Jones',
            'postcode' => 'SP1 1AA',
        ], $this->headersFor('agent', 640003))->assertStatus(201);

        $this->assertSame(2, LoyaltyAccount::whereNull('email_normalised')->count());
    }

    public function test_an_enrolment_nobody_could_find_again_is_refused(): void
    {
        $this->postJson('/api/admin/members', [
            'first_name' => 'Anonymous',
        ], $this->headersFor('agent', 640004))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['email']]]);
    }

    public function test_a_duplicate_email_is_refused_with_the_existing_account(): void
    {
        $existing = $this->member(['email' => 'taken@example.com']);

        $this->postJson('/api/admin/members', [
            'email' => 'TAKEN@example.com',
            'last_name' => 'Twin',
        ], $this->headersFor('agent', 640005))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'duplicate_email')
            ->assertJsonPath('error.details.account_id', $existing->id);
    }

    public function test_an_impossible_birthday_is_refused_but_the_twenty_ninth_of_february_is_not(): void
    {
        $this->postJson('/api/admin/members', [
            'email' => 'impossible@example.com',
            'dob_month' => 2,
            'dob_day' => 31,
        ], $this->headersFor('agent', 640006))
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['dob_day']]]);

        // A member born on a leap day is entitled to their voucher like anyone.
        $this->postJson('/api/admin/members', [
            'email' => 'leapling@example.com',
            'dob_month' => 2,
            'dob_day' => 29,
        ], $this->headersFor('agent', 640007))
            ->assertStatus(201)
            ->assertJsonPath('has_birthday', true);
    }

    public function test_a_day_without_a_month_is_refused(): void
    {
        $this->postJson('/api/admin/members', [
            'email' => 'halfabirthday@example.com',
            'dob_day' => 12,
        ], $this->headersFor('agent', 640008))
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['dob_month']]]);
    }

    public function test_an_enrolment_is_audited(): void
    {
        $response = $this->postJson('/api/admin/members', [
            'email' => 'audited@example.com',
            'last_name' => 'Audited',
        ], $this->headersFor('agent', 640009))->assertStatus(201);

        $entry = AuditEntry::where('action', AuditAction::ACCOUNT_ENROLLED)->sole();

        $this->assertSame($response->json('id'), (int) $entry->subject_id);
        $this->assertSame(640009, (int) $entry->actor_staff_id);
        $this->assertSame('staff', $entry->actor_type);
        $this->assertSame('admin', $entry->channel);
        $this->assertSame('audited@example.com', $entry->after_state['email']);
    }

    public function test_a_correction_records_only_what_moved(): void
    {
        $member = $this->member(['first_name' => 'Margarett', 'postcode' => 'SP4 6AB']);

        $this->patchJson('/api/admin/members/'.$member->id, [
            'first_name' => 'Margaret',
        ], $this->headersFor('agent', 640010))
            ->assertOk()
            ->assertJsonPath('first_name', 'Margaret')
            ->assertJsonPath('postcode', 'SP4 6AB');

        $entry = AuditEntry::where('action', AuditAction::ACCOUNT_UPDATED)->sole();

        $this->assertSame(['first_name' => 'Margarett'], $entry->before_state);
        $this->assertSame(['first_name' => 'Margaret'], $entry->after_state);
    }

    /**
     * The email is the identifier. Correcting one is a merge, which replays
     * both ledgers, not an overwrite that would silently reassign every past
     * movement to a different person.
     */
    public function test_an_email_change_is_refused(): void
    {
        $member = $this->member(['email' => 'original@example.com']);

        $this->patchJson('/api/admin/members/'.$member->id, [
            'email' => 'different@example.com',
        ], $this->headersFor('agent', 640011))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'email_immutable')
            ->assertJsonPath('error.details.account_id', $member->id);

        $this->assertSame('original@example.com', $member->refresh()->email);
    }

    public function test_resending_the_same_email_unchanged_is_not_a_change(): void
    {
        $member = $this->member(['email' => 'original@example.com', 'first_name' => 'Old']);

        $this->patchJson('/api/admin/members/'.$member->id, [
            'email' => 'ORIGINAL@example.com',
            'first_name' => 'New',
        ], $this->headersFor('agent', 640012))
            ->assertOk()
            ->assertJsonPath('first_name', 'New');
    }

    public function test_a_correction_stamps_the_consent_date_when_consent_moves(): void
    {
        $member = $this->member(['email_marketing_consent' => false]);

        $this->patchJson('/api/admin/members/'.$member->id, [
            'email_marketing_consent' => true,
        ], $this->headersFor('agent', 640013))->assertOk();

        $this->assertTrue($member->refresh()->email_marketing_consent);
        $this->assertNotNull($member->consent_updated_at);
    }

    public function test_a_merged_account_refuses_edits(): void
    {
        $surviving = $this->member(['email' => 'surviving@example.com']);
        $merged = $this->member(['email' => 'merged@example.com']);

        $merged->forceFill([
            'status' => 'merged',
            'merged_into_account_id' => $surviving->id,
        ])->save();

        $this->patchJson('/api/admin/members/'.$merged->id, [
            'first_name' => 'Anything',
        ], $this->headersFor('agent', 640014))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'account_merged')
            ->assertJsonPath('error.details.merged_into_account_id', $surviving->id);
    }

    public function test_a_member_on_another_shop_is_not_found_rather_than_forbidden(): void
    {
        $elsewhere = LoyaltyAccount::create([
            'shop_domain' => 'other-store.myshopify.com',
            'email' => 'elsewhere@example.com',
            'enrolment_channel' => 'online',
            'enrolled_at' => now(),
        ]);

        $headers = $this->headersFor('agent', 640015);

        $this->getJson('/api/admin/members/'.$elsewhere->id, $headers)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');

        $this->patchJson('/api/admin/members/'.$elsewhere->id, ['first_name' => 'Nope'], $headers)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_a_viewer_cannot_enrol_or_correct(): void
    {
        $member = $this->member();
        $headers = $this->headersFor('viewer', 640016);

        $this->postJson('/api/admin/members', ['email' => 'nope@example.com'], $headers)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'role_floor_not_met')
            ->assertJsonPath('error.details.required', 'agent')
            ->assertJsonPath('error.details.held', 'viewer');

        $this->patchJson('/api/admin/members/'.$member->id, ['first_name' => 'Nope'], $headers)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'role_floor_not_met');
    }
}
