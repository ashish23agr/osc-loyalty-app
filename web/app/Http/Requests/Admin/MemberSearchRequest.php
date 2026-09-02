<?php

namespace App\Http\Requests\Admin;

use App\Domain\Members\MemberSearch;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The customers list query.
 *
 * Authorisation is the staff.role middleware on the route, so authorize()
 * returns true here and everywhere else in this namespace. A form request that
 * decided authorisation for itself would be a second, quieter role model.
 */
class MemberSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:190'],
            'field' => ['nullable', 'in:'.implode(',', MemberSearch::FIELDS)],
            'segment' => ['nullable', 'in:active,lapsed,unknown'],
            'channel' => ['nullable', 'in:online,pos,admin,migration'],
            'status' => ['nullable', 'in:active,suspended,merged,closed'],
            // MD1: "show me the members with no email" is a real operational
            // question during migration review, so it is a filter, not a
            // by-product of searching.
            'has_email' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'in:enrolled_at,last_name,points_available,voucher_balance_pence'],
            'direction' => ['nullable', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
