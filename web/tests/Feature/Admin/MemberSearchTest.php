<?php

namespace Tests\Feature\Admin;

use App\Models\LoyaltyAccount;
use App\Support\Members\MemberCardNumber;

/**
 * GET /api/admin/members — the customers list.
 *
 * MD1 is what these tests are really about. A member with no email address is a
 * supported state, not a data defect, so the test that matters most is the one
 * that finds Eileen Whitfield — no email held — by postcode, by surname and by
 * her legacy Dynamics card, and that finds her through the single search box
 * rather than only through a field the operator had to select first.
 */
class MemberSearchTest extends AdminApiTestCase
{
    private function seedWhitfields(): array
    {
        $margaret = $this->member([
            'email' => 'm.whitfield@example.co.uk',
            'first_name' => 'Margaret',
            'last_name' => 'Whitfield',
            'postcode' => 'SP4 6AB',
            'legacy_card_number' => 'D-118422',
            'enrolment_channel' => 'migration',
            'segment' => 'active',
        ]);

        $thomas = $this->member([
            'email' => 'tomw@example.co.uk',
            'first_name' => 'Thomas',
            'last_name' => 'Whitfield',
            'postcode' => 'SP4 6AB',
            'segment' => 'active',
        ]);

        // No email held. Migrated, findable by card or by postcode and surname.
        $eileen = $this->member([
            'email' => null,
            'first_name' => 'Eileen',
            'last_name' => 'Whitfield',
            'postcode' => 'BH21 4RG',
            'legacy_card_number' => 'D-090311',
            'enrolment_channel' => 'migration',
            'segment' => 'lapsed',
        ]);

        return [$margaret, $thomas, $eileen];
    }

    public function test_the_default_search_covers_every_identifier_at_once(): void
    {
        [$margaret, , $eileen] = $this->seedWhitfields();
        $headers = $this->headersFor('viewer', 630001);

        // Surname.
        $this->getJson('/api/admin/members?q=whitfield', $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 3);

        // Email fragment.
        $this->getJson('/api/admin/members?q=tomw', $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        // Postcode, however it is spaced.
        $this->getJson('/api/admin/members?q=sp46ab', $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        // Legacy Dynamics card number.
        $this->getJson('/api/admin/members?q=D-090311', $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $eileen->id);

        // Club card number, which is derived from the account id.
        $this->getJson('/api/admin/members?q='.MemberCardNumber::format((int) $margaret->id), $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $margaret->id);
    }

    /**
     * The MD1 promise, stated as a test: a member with no email is reachable by
     * every route that does not depend on one.
     */
    public function test_a_member_with_no_email_is_findable_by_card_postcode_and_surname(): void
    {
        [, , $eileen] = $this->seedWhitfields();
        $headers = $this->headersFor('viewer', 630002);

        foreach (['D-090311', 'BH21 4RG', 'bh214rg', 'Eileen', 'Eileen Whitfield'] as $term) {
            $response = $this->getJson('/api/admin/members?q='.urlencode($term), $headers)->assertOk();

            $this->assertContains(
                $eileen->id,
                array_column($response->json('data'), 'id'),
                'A member with no email must be findable by "'.$term.'".',
            );
        }

        $this->getJson('/api/admin/members?q=Eileen', $headers)
            ->assertOk()
            ->assertJsonPath('data.0.has_no_email', true)
            ->assertJsonPath('data.0.email', null);
    }

    public function test_each_field_can_be_searched_on_its_own(): void
    {
        [$margaret, , $eileen] = $this->seedWhitfields();
        $headers = $this->headersFor('viewer', 630003);

        // Restricting to email excludes the member who has none, even though
        // her surname matches.
        $response = $this->getJson('/api/admin/members?q=whitfield&field=email', $headers)->assertOk();
        $this->assertNotContains($eileen->id, array_column($response->json('data'), 'id'));

        $this->getJson('/api/admin/members?q=whitfield&field=name', $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 3);

        $this->getJson('/api/admin/members?q=D-118422&field=legacy_card', $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $margaret->id);
    }

    public function test_the_list_carries_the_figures_the_screen_shows(): void
    {
        $margaret = $this->member([
            'email' => 'm.whitfield@example.co.uk',
            'first_name' => 'Margaret',
            'last_name' => 'Whitfield',
            'legacy_card_number' => 'D-118422',
        ]);

        $this->withAvailablePoints($margaret, 340);

        $this->getJson('/api/admin/members?q=whitfield', $this->headersFor('viewer', 630004))
            ->assertOk()
            ->assertJsonPath('data.0.display_name', 'Margaret Whitfield')
            ->assertJsonPath('data.0.card_number', MemberCardNumber::format((int) $margaret->id))
            ->assertJsonPath('data.0.legacy_card_number', 'D-118422')
            ->assertJsonPath('data.0.points_available', 340)
            ->assertJsonPath('data.0.points_pending', 0)
            ->assertJsonPath('data.0.voucher_balance_pence', 1500)
            ->assertJsonPath('data.0.segment', 'unknown')
            ->assertJsonPath('data.0.has_no_email', false);
    }

    public function test_the_filters_narrow_the_list_and_are_published_in_the_meta(): void
    {
        $this->seedWhitfields();
        $headers = $this->headersFor('viewer', 630005);

        $this->getJson('/api/admin/members?segment=lapsed', $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/admin/members?channel=migration', $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/admin/members?has_email=0', $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/admin/members', $headers)
            ->assertOk()
            ->assertJsonPath('meta.filters.segments', ['active', 'lapsed', 'unknown'])
            ->assertJsonPath('meta.filters.channels', ['online', 'pos', 'admin', 'migration']);
    }

    public function test_an_unknown_filter_value_is_a_validation_failure(): void
    {
        $this->getJson('/api/admin/members?segment=platinum', $this->headersFor('viewer', 630006))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['segment']]]);

        $this->getJson('/api/admin/members?field=telepathy', $this->headersFor('viewer', 630007))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');

        $this->getJson('/api/admin/members?per_page=5000', $this->headersFor('viewer', 630008))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_a_member_on_another_shop_is_never_listed(): void
    {
        LoyaltyAccount::create([
            'shop_domain' => 'other-store.myshopify.com',
            'email' => 'w.whitfield@elsewhere.example',
            'last_name' => 'Whitfield',
            'enrolment_channel' => 'online',
            'enrolled_at' => now(),
        ]);

        $this->getJson('/api/admin/members?q=whitfield', $this->headersFor('viewer', 630009))
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_paging_is_stable_across_members_sharing_a_sort_value(): void
    {
        $enrolledAt = now()->subDays(5);

        foreach (range(1, 6) as $index) {
            $this->member(['last_name' => 'Shared', 'enrolled_at' => $enrolledAt]);
        }

        $headers = $this->headersFor('viewer', 630010);

        $first = $this->getJson('/api/admin/members?q=Shared&per_page=3&page=1', $headers)->assertOk();
        $second = $this->getJson('/api/admin/members?q=Shared&per_page=3&page=2', $headers)->assertOk();

        $ids = array_merge(
            array_column($first->json('data'), 'id'),
            array_column($second->json('data'), 'id'),
        );

        $this->assertCount(6, array_unique($ids), 'Paging must not repeat or skip a row.');
        $this->assertSame(6, $first->json('meta.total'));
    }
}
