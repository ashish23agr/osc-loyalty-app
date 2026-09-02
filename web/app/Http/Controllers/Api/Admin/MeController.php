<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Identity\StaffRoleResolver;
use App\Domain\Rules\RulesVersionRepository;
use App\Models\StaffRole;
use App\Support\Api\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/admin/me — who the console is talking to, and what they may do.
 *
 * The first call the app makes, and the only one that has to succeed before any
 * screen can render. It returns a permission set rather than only a role name,
 * so RoleGate branches on a capability the server named rather than on a role
 * comparison written a second time in JavaScript.
 *
 * None of this is authorisation. Every endpoint is guarded by its own role floor
 * server-side; this is what stops the console offering a button that would then
 * be refused.
 */
class MeController extends AdminController
{
    public function __construct(
        private readonly StaffRoleResolver $staffRoles,
        private readonly RulesVersionRepository $rules,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $staff = $this->staff($request);
        $rules = $this->rules->current($this->shop($request));

        return response()->json([
            'shop_domain' => $this->shop($request),
            'staff' => [
                'shopify_staff_id' => (int) $staff->shopify_staff_id,
                'staff_name' => $staff->staff_name,
                'staff_email' => $staff->staff_email,
                'role' => $staff->role,
                'rank' => $staff->rank(),
                'adjustment_limit_points' => $staff->adjustment_limit_points,
                // What actually applies to this person: their override if they
                // have one, the rule default if not, null when unrestricted.
                'effective_adjustment_limit_points' => $this->staffRoles->adjustmentLimit(
                    $staff,
                    $rules->agentAdjustmentLimitPoints(),
                ),
            ],
            'permissions' => Permissions::for($staff),
            'roles' => StaffRole::ROLES,
            'rules_version' => $rules->version,
            'currency' => $rules->currency(),
            'reporting_timezone' => $rules->reportingTimezone(),
        ]);
    }
}
