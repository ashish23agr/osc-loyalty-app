<?php

namespace Tests\Feature;

use App\Domain\Identity\StaffRoleResolver;
use App\Models\StaffRole;
use App\Support\Audit\RequestContext;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The role floor over HTTP.
 *
 * Routes are declared here rather than relying on the real ones, so this stays a
 * test of the middleware contract - the exact status codes and error codes the
 * React app and the POS tile will branch on - and does not become a test of
 * whichever endpoints happen to exist yet.
 */
class EnsureStaffRoleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'loyalty-system.myshopify.com';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (StaffRole::ROLES as $floor) {
            Route::middleware('staff.role:'.$floor)
                ->get('/test/floor/'.$floor, function () use ($floor) {
                    $context = app(RequestContext::class);

                    return response()->json([
                        'reached' => $floor,
                        'shop' => $context->shopDomain(),
                        'actor_type' => $context->actorType(),
                        'staff_user_id' => $context->staffUserId(),
                        'channel' => $context->channel(),
                    ]);
                });
        }
    }

    private function tokenFor(int $staffUserId, array $overrides = []): string
    {
        $now = time();

        return JWT::encode(array_merge([
            'iss' => 'https://'.self::SHOP.'/admin',
            'dest' => 'https://'.self::SHOP,
            'aud' => config('shopify.api_key'),
            'sub' => (string) $staffUserId,
            'exp' => $now + 60,
            'nbf' => $now - 10,
            'iat' => $now - 10,
            'jti' => bin2hex(random_bytes(8)),
        ], $overrides), config('shopify.api_secret'), 'HS256');
    }

    private function asStaff(int $staffUserId): array
    {
        return ['Authorization' => 'Bearer '.$this->tokenFor($staffUserId)];
    }

    private function seedStaff(string $role, int $staffUserId): StaffRole
    {
        // Establish an Administrator first, so the shop is configured and the
        // bootstrap cannot fire for the staff member under test.
        app(StaffRoleResolver::class)->resolve(self::SHOP, 100000001);

        return app(StaffRoleResolver::class)->assign(self::SHOP, $staffUserId, $role);
    }

    public function test_a_request_with_no_token_is_unauthenticated(): void
    {
        $this->getJson('/test/floor/viewer')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_session_token');
    }

    public function test_a_forged_token_is_unauthenticated(): void
    {
        $forged = JWT::encode(
            ['sub' => '1', 'aud' => config('shopify.api_key'), 'dest' => 'https://'.self::SHOP, 'exp' => time() + 60],
            'ffffffffffffffffffffffffffffffff',
            'HS256',
        );

        $this->getJson('/test/floor/viewer', ['Authorization' => 'Bearer '.$forged])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_session_token');
    }

    public function test_a_staff_member_with_no_role_on_a_configured_shop_is_refused(): void
    {
        app(StaffRoleResolver::class)->resolve(self::SHOP, 100000001);

        $this->getJson('/test/floor/viewer', $this->asStaff(424242))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'no_role_assigned');
    }

    public function test_a_viewer_reaches_a_viewer_endpoint(): void
    {
        $this->seedStaff('viewer', 500001);

        $this->getJson('/test/floor/viewer', $this->asStaff(500001))
            ->assertOk()
            ->assertJsonPath('reached', 'viewer')
            ->assertJsonPath('shop', self::SHOP)
            ->assertJsonPath('actor_type', 'staff')
            ->assertJsonPath('staff_user_id', 500001)
            ->assertJsonPath('channel', 'admin');
    }

    public function test_a_viewer_is_refused_an_agent_endpoint(): void
    {
        $this->seedStaff('viewer', 500002);

        $this->getJson('/test/floor/agent', $this->asStaff(500002))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'role_floor_not_met')
            ->assertJsonPath('error.details.required', 'agent')
            ->assertJsonPath('error.details.held', 'viewer');
    }

    /**
     * The matrix from Blueprint section 12: a Viewer changes nothing, an Agent
     * adjusts but does not touch rules or rewards, a Manager acts on rewards but
     * not rules, an Administrator does everything.
     */
    public function test_the_full_role_by_floor_matrix(): void
    {
        $staffIds = [
            'viewer' => 510001,
            'agent' => 510002,
            'manager' => 510003,
            'administrator' => 510004,
        ];

        foreach ($staffIds as $role => $id) {
            $this->seedStaff($role, $id);
        }

        $expectedRank = array_flip(StaffRole::ROLES);

        foreach ($staffIds as $heldRole => $id) {
            foreach (StaffRole::ROLES as $floor) {
                $shouldPass = $expectedRank[$heldRole] >= $expectedRank[$floor];

                $response = $this->getJson('/test/floor/'.$floor, $this->asStaff($id));

                $response->assertStatus(
                    $shouldPass ? 200 : 403,
                    $heldRole.' against the '.$floor.' floor',
                );

                if (! $shouldPass) {
                    $response->assertJsonPath('error.code', 'role_floor_not_met');
                }
            }
        }
    }

    public function test_a_revoked_role_takes_effect_on_the_very_next_request(): void
    {
        $this->seedStaff('manager', 520001);

        $this->getJson('/test/floor/manager', $this->asStaff(520001))->assertOk();

        // Demoted by an Administrator, with the same token still in the browser.
        app(StaffRoleResolver::class)->assign(self::SHOP, 520001, 'viewer');

        $this->getJson('/test/floor/manager', $this->asStaff(520001))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'role_floor_not_met');
    }

    public function test_the_token_shop_is_used_even_when_a_query_parameter_disagrees(): void
    {
        $this->seedStaff('viewer', 530001);

        $this->getJson('/test/floor/viewer?shop=attacker.myshopify.com', $this->asStaff(530001))
            ->assertOk()
            ->assertJsonPath('shop', self::SHOP);
    }

    public function test_a_staff_member_from_another_shop_cannot_borrow_a_role(): void
    {
        // A role exists for this staff id, but on a different shop.
        app(StaffRoleResolver::class)->resolve('other-store.myshopify.com', 540001);
        app(StaffRoleResolver::class)->resolve(self::SHOP, 100000001);

        $this->getJson('/test/floor/viewer', $this->asStaff(540001))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'no_role_assigned');
    }
}
