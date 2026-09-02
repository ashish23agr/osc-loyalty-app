<?php

namespace App\Http\Requests\Admin;

use App\Domain\Loyalty\AdjustmentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The adjust-points modal.
 *
 * Direction and a positive number rather than a signed integer, because that is
 * what the modal collects and because "-50" typed into a field labelled Points
 * is ambiguous in a way that "Deduct 50" is not.
 *
 * A preview is the same request with preview=true. It writes nothing, so it
 * does not demand the reason and the notes: the projection has to appear as soon
 * as the number is typed, which is before anyone has written down why.
 */
class AdjustPointsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = Rule::requiredIf(fn (): bool => ! $this->isPreview());

        return [
            'preview' => ['nullable', 'boolean'],
            'direction' => ['required', 'in:add,deduct'],
            'points' => ['required', 'integer', 'min:1', 'max:1000000'],
            'bucket' => ['nullable', 'in:available,pending'],
            'reason_category' => [
                $required,
                'nullable',
                'in:'.implode(',', array_keys(AdjustmentService::REASON_CATEGORIES)),
            ],
            'notes' => [
                $required,
                'nullable',
                'string',
                'min:'.AdjustmentService::MINIMUM_NOTE_LENGTH,
                'max:400',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'reason_category.required' => 'Choose a reason category. Every adjustment is explained in the audit log.',
            'notes.required' => 'Describe the adjustment. Every adjustment is explained in the audit log.',
            'notes.min' => 'Give at least :min characters, so this still makes sense in six months.',
        ];
    }

    public function isPreview(): bool
    {
        return $this->boolean('preview');
    }

    /** The movement as the ledger wants it: one signed integer. */
    public function signedPoints(): int
    {
        $points = (int) $this->input('points');

        return $this->input('direction') === 'deduct' ? -$points : $points;
    }

    public function bucket(): string
    {
        return (string) ($this->input('bucket') ?? 'available');
    }
}
