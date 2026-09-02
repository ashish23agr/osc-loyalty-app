<?php

namespace Tests\Feature\Admin;

use App\Domain\Loyalty\LedgerPosting;
use App\Domain\Loyalty\LedgerService;
use App\Models\LoyaltyAccount;
use App\Support\Members\MemberCardNumber;

/**
 * GET /api/admin/ledger — the Loyalty screen.
 *
 * Every movement across the programme, with the type and channel filters the
 * screen offers and the five summary tiles alongside them, so the whole screen
 * costs one request. Each row names its member, because a programme ledger with
 * anonymous rows is unusable.
 */
class ProgrammeLedgerTest extends AdminApiTestCase
{
    private function seedActivity(): array
    {
        $ledger = app(LedgerService::class);

        $margaret = $this->member([
            'email' => 'm.whitfield@example.co.uk',
            'first_name' => 'Margaret',
            'last_name' => 'Whitfield',
            'legacy_card_number' => 'D-118422',
        ]);

        $priya = $this->member([
            'email' => 'p.osei@example.co.uk',
            'first_name' => 'Priya',
            'last_name' => 'Osei',
        ]);

        // Matured points for Margaret, and a fresh in-store earn today.
        $this->withAvailablePoints($margaret, 340);

        $ledger->post($margaret, LedgerPosting::earn(
            points: 48,
            idempotencyKey: 'earn:today:pos',
            occurredAt: now(),
            maturesAt: now()->addDays(30),
            expiresAt: now()->addDays(212),
            channel: 'pos',
            shopifyOrderId: 8841,
            orderName: '#POS-8841',
            qualifyingValuePence: 4800,
        ));

        // An online earn today for Priya.
        $ledger->post($priya, LedgerPosting::earn(
            points: 112,
            idempotencyKey: 'earn:today:online',
            occurredAt: now(),
            maturesAt: now()->addDays(30),
            expiresAt: now()->addDays(212),
            channel: 'online',
            shopifyOrderId: 10482,
            orderName: '#10482',
            qualifyingValuePence: 11200,
        ));

        return [$margaret, $priya];
    }

    public function test_the_programme_ledger_names_the_member_on_every_row(): void
    {
        [$margaret] = $this->seedActivity();

        $response = $this->getJson('/api/admin/ledger', $this->headersFor('viewer', 700001))->assertOk();

        $response->assertJsonPath('meta.total', 4);

        $row = collect($response->json('data'))->firstWhere('order_name', '#POS-8841');

        $this->assertSame($margaret->id, $row['member']['id']);
        $this->assertSame('Margaret Whitfield', $row['member']['display_name']);
        $this->assertSame(MemberCardNumber::format((int) $margaret->id), $row['member']['card_number']);
        $this->assertSame('D-118422', $row['member']['legacy_card_number']);
        $this->assertSame('pos', $row['channel']);
        $this->assertSame(48, $row['points']);
        $this->assertSame('pending', $row['status']);
    }

    public function test_the_summary_tiles_come_back_with_the_page(): void
    {
        $this->seedActivity();

        $this->getJson('/api/admin/ledger', $this->headersFor('viewer', 700002))
            ->assertOk()
            ->assertJsonPath('meta.summary.points_in_circulation', 340)
            ->assertJsonPath('meta.summary.points_pending', 160)
            ->assertJsonPath('meta.summary.points_earned_today', 160)
            ->assertJsonPath('meta.summary.points_reversed_today', 0)
            ->assertJsonPath('meta.summary.voucher_value_outstanding_pence', 1500)
            ->assertJsonPath('meta.summary.expiring_soon.window_days', 30);
    }

    public function test_points_due_to_expire_inside_the_warning_window_are_counted(): void
    {
        $member = $this->member();

        app(LedgerService::class)->post($member, LedgerPosting::openingBalance(
            points: 200,
            idempotencyKey: 'open:expiring',
            occurredAt: now()->subDays(170),
            expiresAt: now()->addDays(12),
        ));

        app(LedgerService::class)->post($member, LedgerPosting::openingBalance(
            points: 90,
            idempotencyKey: 'open:safe',
            occurredAt: now()->subDays(10),
            expiresAt: now()->addDays(170),
        ));

        $this->getJson('/api/admin/ledger', $this->headersFor('viewer', 700003))
            ->assertOk()
            ->assertJsonPath('meta.summary.expiring_soon.points', 200)
            ->assertJsonPath('meta.summary.expiring_soon.lots', 1);
    }

    public function test_the_programme_ledger_filters_by_type_and_channel(): void
    {
        $this->seedActivity();
        $headers = $this->headersFor('viewer', 700004);

        $this->getJson('/api/admin/ledger?type=earn', $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 3);

        $this->getJson('/api/admin/ledger?channel=pos', $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.order_name', '#POS-8841');

        $this->getJson('/api/admin/ledger?type=earn&channel=online', $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/admin/ledger?q=%23POS-8841', $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_an_unknown_channel_filter_is_a_validation_failure(): void
    {
        $this->getJson('/api/admin/ledger?channel=telepathy', $this->headersFor('viewer', 700005))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['channel.0']]]);
    }

    public function test_the_programme_ledger_never_shows_another_shop(): void
    {
        $this->seedActivity();

        $elsewhere = LoyaltyAccount::create([
            'shop_domain' => 'other-store.myshopify.com',
            'email' => 'elsewhere@example.com',
            'enrolment_channel' => 'online',
            'enrolled_at' => now(),
        ]);

        app(LedgerService::class)->post($elsewhere, LedgerPosting::earn(
            points: 500,
            idempotencyKey: 'earn:elsewhere',
            occurredAt: now(),
            maturesAt: now()->addDays(30),
            expiresAt: now()->addDays(212),
        ));

        $this->getJson('/api/admin/ledger', $this->headersFor('viewer', 700006))
            ->assertOk()
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.summary.points_pending', 160);
    }

    public function test_a_staff_member_with_no_role_cannot_read_the_programme_ledger(): void
    {
        $this->seedActivity();

        $this->getJson('/api/admin/ledger', $this->asStaff(700007))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'no_role_assigned');
    }
}
