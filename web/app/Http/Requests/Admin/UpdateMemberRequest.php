<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Correcting profile fields.
 *
 * The email address is accepted by the validator and then refused by the
 * controller with 409 email_immutable, rather than being rejected here as an
 * unknown field. The distinction is deliberate: sending an email address is not
 * a malformed request, it is a request to do something the programme does not
 * allow, and the console needs a code it can turn into "correct this by merging
 * the records" rather than a field-level validation message.
 *
 * Segment and status are absent because neither is a profile field: segment is
 * calculated from spend, and a status change is a merge or a closure, each with
 * its own audited path.
 */
class UpdateMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['nullable', 'email', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'postcode' => ['nullable', 'string', 'max:16'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'dob_month' => ['nullable', 'integer', 'between:1,12'],
            'dob_day' => ['nullable', 'integer', 'between:1,31'],
            'gender' => ['nullable', 'string', 'max:32'],
            'legacy_card_number' => ['nullable', 'string', 'max:64'],
            'shopify_customer_id' => ['nullable', 'integer', 'min:1'],
            'email_marketing_consent' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $month = $this->input('dob_month');
            $day = $this->input('dob_day');

            if ($month === null || $day === null) {
                return;
            }

            if (! checkdate((int) $month, (int) $day, 2000)) {
                $validator->errors()->add('dob_day', 'That day and month are not a real date.');
            }
        });
    }

    /** Only the fields actually sent, so an absent field is left alone. */
    public function changes(): array
    {
        return array_intersect_key($this->validated(), array_flip([
            'first_name', 'last_name', 'postcode', 'date_of_birth',
            'dob_month', 'dob_day', 'gender', 'legacy_card_number',
            'shopify_customer_id', 'email_marketing_consent',
        ]));
    }
}
