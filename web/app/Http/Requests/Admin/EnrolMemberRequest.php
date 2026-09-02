<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Enrolling a member from the console.
 *
 * Two client confirmations shape this and neither is optional:
 *
 * MD1 — the email address is optional. It is the primary identifier where it
 * exists, but a member without one must be enrollable, so instead of requiring
 * an email the request requires *some* way to find the person again: an email,
 * a legacy card number, or a postcode with a surname.
 *
 * MD2 — a birthday may be a day and a month with no year, so dob_month and
 * dob_day are accepted directly and date_of_birth is the optional case.
 */
class EnrolMemberRequest extends FormRequest
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
            'dob_month' => ['nullable', 'integer', 'between:1,12', 'required_with:dob_day'],
            'dob_day' => ['nullable', 'integer', 'between:1,31', 'required_with:dob_month'],
            'gender' => ['nullable', 'string', 'max:32'],
            'legacy_card_number' => ['nullable', 'string', 'max:64'],
            'shopify_customer_id' => ['nullable', 'integer', 'min:1'],
            'email_marketing_consent' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->assertIdentifiable($validator);
            $this->assertBirthdayIsARealDate($validator);
        });
    }

    /**
     * Someone has to be able to find this member again.
     *
     * Without this an enrolment with a first name and nothing else creates a
     * record that is unreachable from the console and from the till, which is
     * worse than refusing it.
     */
    private function assertIdentifiable(Validator $validator): void
    {
        $hasEmail = trim((string) $this->input('email')) !== '';
        $hasCard = trim((string) $this->input('legacy_card_number')) !== '';
        $hasPostcodeAndName = trim((string) $this->input('postcode')) !== ''
            && trim((string) $this->input('last_name')) !== '';

        if ($hasEmail || $hasCard || $hasPostcodeAndName) {
            return;
        }

        $validator->errors()->add(
            'email',
            'Give an email address, a card number, or a postcode and surname, so this member can be found again.',
        );
    }

    /**
     * 31 February is not a birthday.
     *
     * Checked against a leap year so 29 February is accepted: a member born on
     * one is entitled to their voucher like anyone else.
     */
    private function assertBirthdayIsARealDate(Validator $validator): void
    {
        $month = $this->input('dob_month');
        $day = $this->input('dob_day');

        if ($month === null || $day === null) {
            return;
        }

        if (! checkdate((int) $month, (int) $day, 2000)) {
            $validator->errors()->add('dob_day', 'That day and month are not a real date.');
        }
    }
}
