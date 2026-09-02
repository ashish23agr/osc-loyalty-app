<?php

namespace App\Http\Requests\Admin;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Filters for a ledger, member-scoped or programme-wide.
 *
 * One request class for both, because the member profile and the Loyalty screen
 * offer the same filters over the same rows and only differ in what they are
 * scoped to. Types and channels arrive either repeated or comma-separated,
 * since a URL written by hand and one built by the console should both work.
 */
class LedgerQueryRequest extends FormRequest
{
    public const ENTRY_TYPES = [
        'earn', 'maturity', 'expiry', 'redemption', 'redemption_restore',
        'earn_reversal', 'adjustment', 'opening_balance',
    ];

    public const CHANNELS = ['online', 'pos', 'admin', 'system', 'migration'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'type' => self::toList($this->input('type')),
            'channel' => self::toList($this->input('channel')),
        ]);
    }

    public function rules(): array
    {
        return [
            'type' => ['nullable', 'array'],
            'type.*' => ['in:'.implode(',', self::ENTRY_TYPES)],
            'channel' => ['nullable', 'array'],
            'channel.*' => ['in:'.implode(',', self::CHANNELS)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:190'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * Narrow a ledger query to what was asked for.
     *
     * Lives on the request rather than in a controller because the member
     * ledger and the programme ledger must filter identically — the same rows
     * seen through two screens, not two dialects of the same query string.
     */
    public function applyTo(Builder $query): Builder
    {
        $types = $this->types();
        $channels = $this->channels();

        return $query
            ->when($types !== [], fn (Builder $q) => $q->whereIn('entry_type', $types))
            ->when($channels !== [], fn (Builder $q) => $q->whereIn('channel', $channels))
            ->when(
                $this->filled('from'),
                fn (Builder $q) => $q->where('occurred_at', '>=', $this->date('from')->startOfDay()),
            )
            ->when(
                $this->filled('to'),
                // Inclusive of the end date: filtering to "13 Aug" means the
                // whole of the thirteenth, not midnight at the start of it.
                fn (Builder $q) => $q->where('occurred_at', '<', $this->date('to')->addDay()->startOfDay()),
            );
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_values((array) ($this->validated()['type'] ?? []));
    }

    /** @return list<string> */
    public function channels(): array
    {
        return array_values((array) ($this->validated()['channel'] ?? []));
    }

    private static function toList(mixed $value): ?array
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        return is_array($value)
            ? array_values(array_filter($value, fn ($item): bool => $item !== null && $item !== ''))
            : array_values(array_filter(array_map('trim', explode(',', (string) $value))));
    }
}
