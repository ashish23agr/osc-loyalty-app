<?php

namespace App\Domain\Rules;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Validates a complete rule payload before it becomes a version.
 *
 * A version is a full snapshot by definition, so a partial payload is rejected
 * rather than merged: merging would silently carry forward a value the person
 * saving never saw.
 */
final class RuleSchema
{
    public static function rules(): array
    {
        return [
            'earn_points_per_pound' => ['required', 'integer', 'min:1', 'max:1000'],
            'pending_period_days' => ['required', 'integer', 'min:0', 'max:365'],
            'points_expiry_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'expiry_clock_starts' => ['required', 'in:availability,earning'],
            'voucher_threshold_points' => ['required', 'integer', 'min:1', 'max:1000000'],
            'voucher_value_pence' => ['required', 'integer', 'min:1', 'max:1000000'],
            'birthday_reward_pence' => ['required', 'integer', 'min:0', 'max:1000000'],
            'birthday_reward_expiry_days' => ['required', 'integer', 'min:1', 'max:730'],
            'max_redemption_per_order_pence' => ['required', 'integer', 'min:1', 'max:10000000'],
            'min_basket_pence' => ['required', 'integer', 'min:0', 'max:10000000'],
            'earn_base' => ['required', 'string', 'max:64'],
            'qualification' => ['required', 'array'],
            'qualification.mode' => ['required', 'in:all,collections,tags,full_price_only'],
            'qualification.include_collections' => ['present', 'array'],
            'qualification.include_tags' => ['present', 'array'],
            'qualification.exclude_collections' => ['present', 'array'],
            'qualification.exclude_tags' => ['present', 'array'],
            'online_redemption_enabled' => ['required', 'boolean'],
            'pos_redemption_enabled' => ['required', 'boolean'],
            'expiry_warning_days' => ['required', 'integer', 'min:0', 'max:365'],
            'migrated_balance_grace_days' => ['required', 'integer', 'min:0', 'max:730'],
            'agent_adjustment_limit_points' => ['required', 'integer', 'min:0', 'max:1000000'],
            'currency' => ['required', 'string', 'size:3'],
            'reporting_timezone' => ['required', 'timezone'],
        ];
    }

    /**
     * @throws ValidationException
     */
    public static function validate(array $payload): array
    {
        $validator = Validator::make($payload, self::rules());

        $validator->after(function ($validator) use ($payload): void {
            // Points must be able to mature before they expire, or a member
            // never gets to spend anything.
            $pending = (int) ($payload['pending_period_days'] ?? 0);
            $expiry = (int) ($payload['points_expiry_days'] ?? 0);

            if ($expiry > 0 && $expiry <= $pending) {
                $validator->errors()->add(
                    'points_expiry_days',
                    'Points must expire later than the pending period, or they mature and expire together.',
                );
            }

            // A cap below one increment makes the voucher unredeemable, which
            // reads as a broken programme rather than a configuration choice.
            $cap = (int) ($payload['max_redemption_per_order_pence'] ?? 0);
            $value = (int) ($payload['voucher_value_pence'] ?? 0);

            if ($cap > 0 && $value > 0 && $cap < $value) {
                $validator->errors()->add(
                    'max_redemption_per_order_pence',
                    'The per-order cap cannot be smaller than one voucher increment.',
                );
            }

            // Q1 / C3: full_price_only is not a confirmed requirement. The mode
            // exists so it can be switched on without rework, but selecting it
            // needs an OSC decision and a technical check (V6) first.
            if (($payload['qualification']['mode'] ?? null) === 'full_price_only') {
                $validator->errors()->add(
                    'qualification.mode',
                    'Full-price-only qualification is not confirmed (C3) and is not implemented yet.',
                );
            }

            // The Blueprint asks for a currency guard: every rule is written in
            // pounds while the development store is in dollars.
            $unknown = array_diff(array_keys($payload), array_keys(RuleSet::defaults()));

            if ($unknown !== []) {
                $validator->errors()->add(
                    'payload',
                    'Unrecognised rule keys: '.implode(', ', $unknown).'.',
                );
            }
        });

        return $validator->validate();
    }
}
