<?php

namespace App\Http\Requests\Admin;

use App\Models\StaffRole;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Assigning a role and labelling a member of staff.
 *
 * The name and email are labels an Administrator types, not values read from
 * Shopify: resolving a staff identifier to a name needs the protected
 * read_users scope, which this app deliberately does not request, so role
 * management never waits on a Shopify approval.
 *
 * adjustment_limit_points is present-but-nullable rather than optional, because
 * clearing a personal override and leaving it alone are different intentions and
 * the console must be able to express both.
 */
class AssignStaffRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role' => ['required', 'in:'.implode(',', StaffRole::ROLES)],
            'staff_name' => ['nullable', 'string', 'max:255'],
            'staff_email' => ['nullable', 'email', 'max:255'],
            'adjustment_limit_points' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ];
    }
}
