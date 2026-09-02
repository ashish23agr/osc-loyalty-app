<?php

namespace App\Http\Requests\Admin;

use App\Support\Audit\AuditAction;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Filters for the audit log screen: who, what, and when.
 *
 * Actions are validated against the closed list, so a filter cannot quietly
 * select a category that does not exist and return an empty page that looks like
 * "nothing happened" rather than "you asked the wrong question".
 */
class AuditQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $value = $this->input('action');

        if ($value !== null && $value !== '' && $value !== []) {
            $this->merge([
                'action' => is_array($value)
                    ? array_values($value)
                    : array_values(array_filter(array_map('trim', explode(',', (string) $value)))),
            ]);
        } else {
            $this->merge(['action' => null]);
        }
    }

    public function rules(): array
    {
        return [
            'action' => ['nullable', 'array'],
            'action.*' => ['in:'.implode(',', AuditAction::all())],
            'actor_staff_id' => ['nullable', 'integer', 'min:1'],
            'actor_type' => ['nullable', 'in:staff,system,pos,migration'],
            'subject_type' => ['nullable', 'string', 'max:64'],
            'subject_id' => ['nullable', 'integer', 'min:1'],
            'channel' => ['nullable', 'in:admin,pos,account,system'],
            'q' => ['nullable', 'string', 'max:190'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** @return list<string> */
    public function actions(): array
    {
        return array_values((array) ($this->validated()['action'] ?? []));
    }
}
