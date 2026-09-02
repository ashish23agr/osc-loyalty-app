<?php

namespace App\Support\Api;

use App\Models\StaffRole;

/**
 * What each role may do, as data.
 *
 * The floors here are the same floors the routes are guarded with, and the
 * routing test asserts that. Authorisation is always the middleware; this map
 * exists so GET /api/admin/me can hand the console a permission set and the
 * RoleGate can hide what a role may not do without hard-coding the role model
 * in two places, one of which would eventually drift.
 */
final class Permissions
{
    /**
     * permission => the lowest role that holds it. Blueprint section 12.
     *
     * Named without dots, because a dot is a path separator to every JSON
     * client there is and a permission called "members.view" would have to be
     * quoted or escaped by each of them.
     */
    public const FLOORS = [
        'view_overview' => 'viewer',
        'view_members' => 'viewer',
        'enrol_members' => 'agent',
        'edit_members' => 'agent',
        'view_ledger' => 'viewer',
        'adjust_points' => 'agent',
        'rebuild_caches' => 'manager',
        'view_rules' => 'viewer',
        'edit_rules' => 'administrator',
        'view_audit' => 'manager',
        'view_staff' => 'administrator',
        'manage_staff' => 'administrator',
    ];

    /** @return array<string, bool> Every permission, answered for this role. */
    public static function for(StaffRole $staff): array
    {
        $granted = [];

        foreach (self::FLOORS as $permission => $floor) {
            $granted[$permission] = $staff->satisfies($floor);
        }

        return $granted;
    }

    public static function floor(string $permission): string
    {
        return self::FLOORS[$permission]
            ?? throw new \InvalidArgumentException('Unknown permission: '.$permission);
    }
}
