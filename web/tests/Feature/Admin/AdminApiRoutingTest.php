<?php

namespace Tests\Feature\Admin;

use App\Models\StaffRole;
use App\Support\Api\Permissions;
use Illuminate\Support\Facades\Route;

/**
 * The role column of plan section 5.3, asserted rather than documented.
 *
 * Authorisation is the middleware, so the risk worth testing is not that a
 * guard fails but that one is missing or set to the wrong floor — a mistake
 * nothing else in the suite would notice, because every endpoint would keep
 * working perfectly for the person who wrote it.
 */
class AdminApiRoutingTest extends AdminApiTestCase
{
    /**
     * Every Sprint 1 endpoint, its floor, and a request that reaches it.
     *
     * @return list<array{0:string, 1:string, 2:string}>
     */
    public static function endpoints(): array
    {
        return [
            ['GET', 'api/admin/me', 'viewer'],
            ['GET', 'api/admin/overview', 'viewer'],
            ['GET', 'api/admin/members', 'viewer'],
            ['POST', 'api/admin/members', 'agent'],
            ['GET', 'api/admin/members/{id}', 'viewer'],
            ['PATCH', 'api/admin/members/{id}', 'agent'],
            ['GET', 'api/admin/members/{id}/ledger', 'viewer'],
            ['POST', 'api/admin/members/{id}/adjustments', 'agent'],
            ['POST', 'api/admin/members/{id}/rebuild-cache', 'manager'],
            ['GET', 'api/admin/ledger', 'viewer'],
            ['GET', 'api/admin/rules', 'viewer'],
            ['GET', 'api/admin/rules/versions/{version}', 'viewer'],
            ['POST', 'api/admin/rules', 'administrator'],
            // Sprint 3, the POS tile. Quote is read-only, so a Viewer may ask
            // what could be offered; holding or releasing one changes what a
            // member can spend and sits at the adjustment floor. C9 asks whether
            // a till assistant on a lower role should act at all - until it is
            // answered the Sprint 1 floors govern.
            ['POST', 'api/admin/redemptions/quote', 'viewer'],
            ['POST', 'api/admin/redemptions', 'agent'],
            ['DELETE', 'api/admin/redemptions/{reference}', 'agent'],
            ['GET', 'api/admin/audit', 'manager'],
            ['GET', 'api/admin/staff', 'administrator'],
            ['PUT', 'api/admin/staff/{staffId}', 'administrator'],
        ];
    }

    public function test_every_admin_route_declares_a_role_floor(): void
    {
        $unguarded = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/admin')) {
                continue;
            }

            $floors = array_filter(
                $route->gatherMiddleware(),
                fn ($middleware): bool => is_string($middleware) && str_starts_with($middleware, 'staff.role'),
            );

            if ($floors === []) {
                $unguarded[] = implode('|', $route->methods()).' '.$route->uri();
            }
        }

        $this->assertSame(
            [],
            $unguarded,
            'An admin endpoint with no role floor is open to any staff member with any role.',
        );
    }

    public function test_each_endpoint_is_guarded_at_the_floor_the_plan_gives_it(): void
    {
        foreach (self::endpoints() as [$method, $uri, $expectedFloor]) {
            $route = collect(Route::getRoutes()->getRoutes())->first(
                fn ($candidate): bool => $candidate->uri() === $uri
                    && in_array($method, $candidate->methods(), true),
            );

            $this->assertNotNull($route, $method.' '.$uri.' is not registered.');

            $declared = collect($route->gatherMiddleware())
                ->first(fn ($middleware): bool => is_string($middleware) && str_starts_with($middleware, 'staff.role'));

            $this->assertSame(
                'staff.role:'.$expectedFloor,
                $declared,
                $method.' '.$uri.' must be guarded at the '.$expectedFloor.' floor.',
            );
        }
    }

    public function test_the_endpoint_table_covers_every_registered_admin_route(): void
    {
        $registered = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/admin')) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }

                $registered[] = $method.' '.$route->uri();
            }
        }

        $expected = array_map(
            fn (array $endpoint): string => $endpoint[0].' '.$endpoint[1],
            self::endpoints(),
        );

        sort($registered);
        sort($expected);

        $this->assertSame(
            $expected,
            $registered,
            'An endpoint was added or removed without the plan table being updated.',
        );
    }

    public function test_every_permission_names_a_real_role(): void
    {
        foreach (Permissions::FLOORS as $permission => $floor) {
            $this->assertContains(
                $floor,
                StaffRole::ROLES,
                'The permission '.$permission.' is floored at a role that does not exist.',
            );
        }
    }

    /**
     * A staff member with no role reaches nothing at all, including the Viewer
     * endpoints, because there is no role below Viewer to refuse them with.
     */
    public function test_a_staff_member_with_no_role_reaches_no_endpoint(): void
    {
        $member = $this->member();

        foreach (self::endpoints() as [$method, $uri, $floor]) {
            $response = $this->json($method, $this->resolve($uri, (int) $member->id), [], $this->asStaff(777001));

            $response->assertStatus(403);
            $response->assertJsonPath('error.code', 'no_role_assigned');
        }
    }

    /**
     * One role below the floor is refused with the code the console branches on,
     * and with both the required and the held role in the details.
     */
    public function test_the_role_immediately_below_each_floor_is_refused(): void
    {
        $member = $this->member();
        $staffId = 778000;

        foreach (self::endpoints() as [$method, $uri, $floor]) {
            $below = array_search($floor, StaffRole::ROLES, true) - 1;

            if ($below < 0) {
                // Nothing sits below Viewer; the no-role case above covers it.
                continue;
            }

            $heldRole = StaffRole::ROLES[$below];
            $headers = $this->headersFor($heldRole, ++$staffId);

            $this->json($method, $this->resolve($uri, (int) $member->id), [], $headers)
                ->assertStatus(403)
                ->assertJsonPath('error.code', 'role_floor_not_met')
                ->assertJsonPath('error.details.required', $floor)
                ->assertJsonPath('error.details.held', $heldRole);
        }
    }

    private function resolve(string $uri, int $memberId): string
    {
        return str_replace(
            ['{id}', '{version}', '{staffId}'],
            [(string) $memberId, '1', (string) self::OWNER],
            '/'.$uri,
        );
    }
}
