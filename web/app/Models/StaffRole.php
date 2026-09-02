<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Maps a Shopify staff identity to an application role.
 *
 * Identity comes from the App Bridge session token, so there is no second
 * password to manage. Resolving that identifier to a name would need the
 * protected `read_users` scope, so an Administrator labels each person once
 * instead and role management never waits on a Shopify approval.
 */
class StaffRole extends Model
{
    /** Ordered from least to most privileged. A higher role always passes. */
    public const ROLES = ['viewer', 'agent', 'manager', 'administrator'];

    protected $fillable = [
        'shop_domain', 'shopify_staff_id', 'staff_name', 'staff_email',
        'role', 'adjustment_limit_points', 'assigned_by_staff_id',
    ];

    protected $casts = [
        'shopify_staff_id' => 'integer',
        'adjustment_limit_points' => 'integer',
    ];

    public function rank(): int
    {
        return (int) array_search($this->role, self::ROLES, true);
    }

    public function satisfies(string $floor): bool
    {
        $required = array_search($floor, self::ROLES, true);

        return $required !== false && $this->rank() >= $required;
    }
}
