<?php

namespace App\Domain\Identity;

use App\Models\StaffRole;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\RequestContext;
use Illuminate\Support\Facades\DB;

/**
 * Maps a verified staff identity to an application role.
 *
 * Identity comes from the session token, so there is no second password to
 * manage. A verified token is never trusted for a role: the role is always read
 * from staff_roles, so revoking someone takes effect on their next request.
 */
class StaffRoleResolver
{
    public function __construct(
        private readonly RequestContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function find(string $shopDomain, int $staffUserId): ?StaffRole
    {
        return StaffRole::query()
            ->where('shop_domain', $shopDomain)
            ->where('shopify_staff_id', $staffUserId)
            ->first();
    }

    /**
     * The role for this staff member, bootstrapping the first Administrator.
     *
     * C6, confirmed: the shop owner becomes Administrator automatically, and
     * every later role is assigned in the console and audited. In practice the
     * bootstrap fires for the first staff member to open the app on a shop with
     * no roles at all, which is whoever installed it. It can only ever happen
     * once per shop, because after it there is a role.
     *
     * The alternative - every staff member defaulting to Administrator until
     * configured - is unacceptable in a system that moves money, so an
     * unrecognised staff member on a shop that already has roles gets nothing
     * and is refused by the middleware.
     */
    public function resolve(string $shopDomain, int $staffUserId): ?StaffRole
    {
        $existing = $this->find($shopDomain, $staffUserId);

        if ($existing !== null) {
            return $existing;
        }

        return $this->bootstrapFirstAdministrator($shopDomain, $staffUserId);
    }

    /**
     * Grant Administrator to the first staff member on a shop with no roles.
     *
     * Guarded by a locked count inside a transaction, so two simultaneous first
     * requests cannot both decide they are the first.
     */
    public function bootstrapFirstAdministrator(string $shopDomain, int $staffUserId): ?StaffRole
    {
        return DB::transaction(function () use ($shopDomain, $staffUserId): ?StaffRole {
            $alreadyConfigured = StaffRole::query()
                ->where('shop_domain', $shopDomain)
                ->lockForUpdate()
                ->exists();

            if ($alreadyConfigured) {
                return null;
            }

            $role = StaffRole::create([
                'shop_domain' => $shopDomain,
                'shopify_staff_id' => $staffUserId,
                'role' => 'administrator',
            ]);

            // Recorded loudly, because it is the one role nobody assigned.
            $this->context->forStaff($role, $shopDomain, 'admin');

            $this->audit->log(
                action: AuditAction::STAFF_BOOTSTRAPPED,
                subjectType: 'StaffRole',
                subjectId: $role->id,
                after: ['role' => 'administrator', 'shopify_staff_id' => $staffUserId],
                shopDomain: $shopDomain,
            );

            return $role;
        });
    }

    /**
     * Assign or change a role.
     *
     * Refuses to remove the last Administrator: a shop with none can no longer
     * change its own rules or roles, and recovering from that needs a developer.
     */
    public function assign(
        string $shopDomain,
        int $staffUserId,
        string $role,
        ?string $name = null,
        ?string $email = null,
        ?int $adjustmentLimitPoints = null,
    ): StaffRole {
        if (! in_array($role, StaffRole::ROLES, true)) {
            throw new \InvalidArgumentException('Unknown role: '.$role);
        }

        return DB::transaction(function () use ($shopDomain, $staffUserId, $role, $name, $email, $adjustmentLimitPoints): StaffRole {
            $existing = StaffRole::query()
                ->where('shop_domain', $shopDomain)
                ->where('shopify_staff_id', $staffUserId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->role === 'administrator' && $role !== 'administrator') {
                $otherAdministrators = StaffRole::query()
                    ->where('shop_domain', $shopDomain)
                    ->where('role', 'administrator')
                    ->where('shopify_staff_id', '!=', $staffUserId)
                    ->count();

                if ($otherAdministrators === 0) {
                    throw new LastAdministratorException(
                        'This is the only Administrator. Promote someone else before changing this role.'
                    );
                }
            }

            $staff = $existing ?? new StaffRole([
                'shop_domain' => $shopDomain,
                'shopify_staff_id' => $staffUserId,
            ]);

            $staff->fill([
                'role' => $role,
                'staff_name' => $name ?? $staff->staff_name,
                'staff_email' => $email ?? $staff->staff_email,
                'adjustment_limit_points' => $adjustmentLimitPoints,
                'assigned_by_staff_id' => $this->context->staffUserId(),
            ]);

            // Logged before the save, because getDirty() is what carries the
            // before and after values and it is cleared once the row is written.
            // The surrounding transaction makes the pair atomic.
            $this->audit->logModelChange(
                action: AuditAction::STAFF_ROLE_ASSIGNED,
                model: $staff,
                only: ['role', 'adjustment_limit_points', 'staff_name', 'staff_email'],
            );

            $staff->save();

            return $staff;
        });
    }

    /**
     * The points an Agent may move in one adjustment.
     *
     * A per-person override beats the rule default, so one experienced member of
     * staff can be trusted further without loosening the rule for everyone.
     */
    public function adjustmentLimit(StaffRole $staff, int $ruleDefault): ?int
    {
        if ($staff->satisfies('manager')) {
            // Manager and above are unrestricted, per Blueprint section 12.
            return null;
        }

        return $staff->adjustment_limit_points ?? $ruleDefault;
    }
}
