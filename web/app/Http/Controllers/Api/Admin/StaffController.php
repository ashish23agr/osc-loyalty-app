<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Identity\LastAdministratorException;
use App\Domain\Identity\StaffRoleResolver;
use App\Domain\Rules\RulesVersionRepository;
use App\Http\Requests\Admin\AssignStaffRoleRequest;
use App\Models\StaffRole;
use App\Support\Api\ApiException;
use App\Support\Api\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff and their roles. Administrator only, both ways.
 *
 * Assignment goes through StaffRoleResolver rather than touching the model,
 * because the resolver is what refuses to remove the last Administrator and
 * what writes the audit entry with the before and after role. A shop with no
 * Administrator can no longer change its own rules or its own roles, and
 * recovering from that needs a developer.
 */
class StaffController extends AdminController
{
    public function __construct(
        private readonly StaffRoleResolver $staffRoles,
        private readonly RulesVersionRepository $rules,
    ) {}

    /** GET /api/admin/staff */
    public function index(Request $request): JsonResponse
    {
        $shop = $this->shop($request);
        $you = (int) $this->staff($request)->shopify_staff_id;
        $default = $this->rules->current($shop)->agentAdjustmentLimitPoints();

        // Sorted by rank in PHP, not by the role column: the enum sorts
        // alphabetically, which would put a Viewer above an Administrator.
        $staff = StaffRole::query()
            ->where('shop_domain', $shop)
            ->orderBy('shopify_staff_id')
            ->get()
            ->sortByDesc(fn (StaffRole $member): int => $member->rank())
            ->values();

        return response()->json([
            'data' => $staff->map(fn (StaffRole $member): array => $this->row($member, $you, $default))->all(),
            'meta' => [
                'roles' => StaffRole::ROLES,
                'permissions' => Permissions::FLOORS,
                'agent_adjustment_limit_points' => $default,
                // What the resolver will refuse to go below, surfaced so the
                // console can grey out the last Administrator rather than
                // offering a change it knows will fail.
                'administrator_count' => $staff->where('role', 'administrator')->count(),
            ],
        ]);
    }

    /**
     * PUT /api/admin/staff/{staffId} — assign a role, label a person.
     *
     * The identifier is the Shopify staff id from their session token. There is
     * no create step: a role row appears the first time someone is given one,
     * which is why this is a PUT against an identifier the console already knows
     * rather than a POST that would invent one.
     */
    public function update(AssignStaffRoleRequest $request, string $staffId): JsonResponse
    {
        $shop = $this->shop($request);

        try {
            $staff = $this->staffRoles->assign(
                shopDomain: $shop,
                staffUserId: (int) $staffId,
                role: (string) $request->input('role'),
                name: $request->input('staff_name'),
                email: $request->input('staff_email'),
                adjustmentLimitPoints: $request->input('adjustment_limit_points') === null
                    ? null
                    : (int) $request->input('adjustment_limit_points'),
            );
        } catch (LastAdministratorException $e) {
            throw ApiException::lastAdministrator($e->getMessage());
        }

        return response()->json($this->row(
            $staff,
            (int) $this->staff($request)->shopify_staff_id,
            $this->rules->current($shop)->agentAdjustmentLimitPoints(),
        ));
    }

    private function row(StaffRole $staff, int $you, int $agentDefault): array
    {
        return [
            'id' => (int) $staff->id,
            'shopify_staff_id' => (int) $staff->shopify_staff_id,
            'staff_name' => $staff->staff_name,
            'staff_email' => $staff->staff_email,
            'role' => $staff->role,
            'rank' => $staff->rank(),
            'adjustment_limit_points' => $staff->adjustment_limit_points,
            'effective_adjustment_limit_points' => $this->staffRoles->adjustmentLimit($staff, $agentDefault),
            'assigned_by_staff_id' => $staff->assigned_by_staff_id === null
                ? null
                : (int) $staff->assigned_by_staff_id,
            'permissions' => Permissions::for($staff),
            // So the console can warn before someone demotes themselves out of
            // the screen they are standing on.
            'is_you' => (int) $staff->shopify_staff_id === $you,
            'created_at' => $staff->created_at?->toIso8601String(),
            'updated_at' => $staff->updated_at?->toIso8601String(),
        ];
    }
}
