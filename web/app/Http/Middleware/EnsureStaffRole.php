<?php

namespace App\Http\Middleware;

use App\Domain\Identity\SessionTokenVerifier;
use App\Domain\Identity\StaffRoleResolver;
use App\Models\StaffRole;
use App\Support\Audit\RequestContext;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

/**
 * Enforces the role floor for an admin endpoint, and establishes who is acting.
 *
 * Usage: ->middleware('staff.role:agent')
 *
 * Authorisation is enforced here, server-side, on every endpoint. The RoleGate
 * in the React app hides what a role may not do, but that is a convenience for
 * the person using it and never the control.
 *
 * The resolved staff member is attached to the request as `staffRole`, and the
 * shared RequestContext is populated so the audit logger knows the actor without
 * anything being threaded through call sites.
 */
class EnsureStaffRole
{
    public function __construct(
        private readonly SessionTokenVerifier $verifier,
        private readonly StaffRoleResolver $resolver,
        private readonly RequestContext $context,
    ) {}

    public function handle(Request $request, Closure $next, string $floor = 'viewer', string $channel = 'admin')
    {
        if (! in_array($floor, StaffRole::ROLES, true)) {
            throw new InvalidArgumentException('Unknown role floor: '.$floor);
        }

        try {
            $token = $this->verifier->verify($request);
        } catch (RuntimeException $e) {
            return response()->json([
                'error' => [
                    'code' => 'invalid_session_token',
                    'message' => 'This request carries no valid Shopify session token.',
                ],
            ], 401);
        }

        $staff = $this->resolver->resolve($token->shopDomain, $token->staffUserId);

        if ($staff === null) {
            // A known shop, but this staff member has no role. Deliberately not
            // granted anything: only the first person on an unconfigured shop is
            // bootstrapped, and after that an Administrator assigns roles.
            return response()->json([
                'error' => [
                    'code' => 'no_role_assigned',
                    'message' => 'This staff member has no Privilege Club role. An Administrator must assign one.',
                ],
            ], 403);
        }

        if (! $staff->satisfies($floor)) {
            return response()->json([
                'error' => [
                    'code' => 'role_floor_not_met',
                    'message' => 'This action needs the '.$floor.' role or higher.',
                    'details' => ['required' => $floor, 'held' => $staff->role],
                ],
            ], 403);
        }

        $this->context->forStaff(
            staff: $staff,
            shopDomain: $token->shopDomain,
            channel: $channel,
            ipAddress: $request->ip(),
        );

        $request->attributes->set('staffRole', $staff);
        $request->attributes->set('shopDomain', $token->shopDomain);

        return $next($request);
    }
}
