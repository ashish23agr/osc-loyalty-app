<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Saving a new rule version.
 *
 * Only the envelope is validated here. The payload itself goes to
 * RuleSchema::validate(), which is the same validation the repository, the
 * console and any future importer share, and which rejects a partial snapshot
 * rather than merging it — merging would silently carry forward a value the
 * person saving never saw.
 *
 * The schema reports its errors under the rule names themselves rather than
 * under "rules.earn_points_per_pound", which is what the settings form needs to
 * put a message next to a field.
 */
class SaveRulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rules' => ['required', 'array'],
            'change_summary' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'rules.required' => 'Send the complete rule set. A rule version is a full snapshot, never a partial update.',
        ];
    }

    public function payload(): array
    {
        return (array) $this->input('rules', []);
    }
}
