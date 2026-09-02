<?php

namespace App\Domain\Rules;

use InvalidArgumentException;

/**
 * An immutable, typed view over one saved rule version.
 *
 * Every calculation is evaluated against the version that was in force when the
 * thing being calculated happened, so a RuleSet is always obtained from
 * RulesVersionRepository::current() or ::asAt() and never assembled ad hoc
 * outside tests.
 */
final class RuleSet
{
    public function __construct(
        private readonly array $payload,
        public readonly ?int $versionId = null,
        public readonly ?int $version = null,
    ) {}

    /**
     * The launch values from Blueprint section 06.
     *
     * D1: points_expiry_days is what the setting labelled "voucher expiry"
     * configures - the derived voucher balance has no expiry of its own.
     * D2: expiry_clock_starts is "availability", so a member gets the full
     * expiry period of usable life rather than losing the pending month.
     */
    public static function defaults(array $overrides = []): array
    {
        $defaults = [
            'earn_points_per_pound' => 1,
            'pending_period_days' => 30,
            'points_expiry_days' => 182,
            'expiry_clock_starts' => 'availability',
            'voucher_threshold_points' => 100,
            'voucher_value_pence' => 500,
            'birthday_reward_pence' => 1000,
            'birthday_reward_expiry_days' => 90,
            'max_redemption_per_order_pence' => 5000,
            'min_basket_pence' => 0,
            'earn_base' => 'post_discount_ex_tax_ex_shipping',
            'qualification' => [
                'mode' => 'all',
                'include_collections' => [],
                'include_tags' => [],
                'exclude_collections' => [],
                'exclude_tags' => [],
            ],
            'online_redemption_enabled' => true,
            'pos_redemption_enabled' => true,
            'expiry_warning_days' => 30,
            'migrated_balance_grace_days' => 182,
            'agent_adjustment_limit_points' => 500,
            'currency' => 'GBP',
            'reporting_timezone' => 'Europe/London',
        ];

        return array_replace_recursive($defaults, $overrides);
    }

    public static function fromDefaults(array $overrides = []): self
    {
        return new self(self::defaults($overrides));
    }

    public function with(array $overrides): self
    {
        return new self(
            array_replace_recursive($this->payload, $overrides),
            $this->versionId,
            $this->version,
        );
    }

    public function earnPointsPerPound(): int
    {
        return $this->int('earn_points_per_pound');
    }

    public function pendingPeriodDays(): int
    {
        return $this->int('pending_period_days');
    }

    public function pointsExpiryDays(): int
    {
        return $this->int('points_expiry_days');
    }

    /** "availability" (D2, recommended and approved) or "earning". */
    public function expiryClockStarts(): string
    {
        return (string) ($this->payload['expiry_clock_starts'] ?? 'availability');
    }

    public function voucherThresholdPoints(): int
    {
        return $this->int('voucher_threshold_points');
    }

    public function voucherValuePence(): int
    {
        return $this->int('voucher_value_pence');
    }

    public function birthdayRewardPence(): int
    {
        return $this->int('birthday_reward_pence');
    }

    public function birthdayRewardExpiryDays(): int
    {
        return $this->int('birthday_reward_expiry_days');
    }

    public function maxRedemptionPerOrderPence(): int
    {
        return $this->int('max_redemption_per_order_pence');
    }

    public function minBasketPence(): int
    {
        return $this->int('min_basket_pence');
    }

    public function earnBase(): string
    {
        return (string) $this->payload['earn_base'];
    }

    public function qualification(): array
    {
        return (array) ($this->payload['qualification'] ?? []);
    }

    public function onlineRedemptionEnabled(): bool
    {
        return (bool) $this->payload['online_redemption_enabled'];
    }

    public function posRedemptionEnabled(): bool
    {
        return (bool) $this->payload['pos_redemption_enabled'];
    }

    public function expiryWarningDays(): int
    {
        return $this->int('expiry_warning_days');
    }

    /** D4: the grace period applied to a migrated opening balance. */
    public function migratedBalanceGraceDays(): int
    {
        return $this->int('migrated_balance_grace_days');
    }

    public function agentAdjustmentLimitPoints(): int
    {
        return $this->int('agent_adjustment_limit_points');
    }

    public function currency(): string
    {
        return (string) $this->payload['currency'];
    }

    public function reportingTimezone(): string
    {
        return (string) $this->payload['reporting_timezone'];
    }

    public function toArray(): array
    {
        return $this->payload;
    }

    private function int(string $key): int
    {
        if (! array_key_exists($key, $this->payload)) {
            throw new InvalidArgumentException("Rule '$key' is missing from this rule version.");
        }

        return (int) $this->payload[$key];
    }
}
