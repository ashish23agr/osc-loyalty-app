<?php

namespace Tests\Feature;

use App\Domain\Rules\RuleSchema;
use App\Domain\Rules\RuleSet;
use App\Domain\Rules\RulesVersionRepository;
use App\Models\RulesVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Rule versions are snapshots, never updates, because a transaction must always
 * be evaluated against the rules that were in force when it happened. These
 * tests hold that property, and hold the launch values to what the Blueprint
 * specifies.
 */
class RulesVersioningTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'loyalty-system.myshopify.com';

    private RulesVersionRepository $rules;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rules = app(RulesVersionRepository::class);
    }

    public function test_the_seeded_launch_values_match_the_blueprint(): void
    {
        $rules = $this->rules->current(self::SHOP);

        // Blueprint section 06, "Configurable rules and their launch values".
        $this->assertSame(1, $rules->earnPointsPerPound());
        $this->assertSame(30, $rules->pendingPeriodDays());
        $this->assertSame(182, $rules->pointsExpiryDays());
        $this->assertSame(100, $rules->voucherThresholdPoints());
        $this->assertSame(500, $rules->voucherValuePence());
        $this->assertSame(1000, $rules->birthdayRewardPence());
        $this->assertSame(5000, $rules->maxRedemptionPerOrderPence());
        $this->assertTrue($rules->onlineRedemptionEnabled());
        $this->assertTrue($rules->posRedemptionEnabled());

        // D2, approved as recommended.
        $this->assertSame('availability', $rules->expiryClockStarts());

        // D4, client-confirmed.
        $this->assertSame(182, $rules->migratedBalanceGraceDays());
    }

    /** Recursively key-sorted, so two payloads compare by content not order. */
    private static function sorted(array $payload): array
    {
        ksort($payload);

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = self::sorted($value);
            }
        }

        return $payload;
    }

    public function test_saving_creates_a_new_version_and_never_updates_the_old_one(): void
    {
        $first = $this->rules->currentModel(self::SHOP);

        $second = $this->rules->save(
            self::SHOP,
            RuleSet::defaults(),
            staffId: 99,
            staffName: 'Test Administrator',
            changeSummary: 'No functional change, testing versioning',
        );

        $this->assertSame(1, $first->version);
        $this->assertSame(2, $second->version);
        $this->assertSame(2, RulesVersion::query()->where('shop_domain', self::SHOP)->count());
        $this->assertSame(99, $second->created_by_staff_id);
        $this->assertSame('Test Administrator', $second->created_by_name);

        // The original row is untouched.
        //
        // Compared by content rather than by key order: MySQL's JSON columns
        // hand the keys back in their own order, so assertSame on the raw
        // arrays would fail on a row nothing had touched.
        $this->assertEqualsCanonicalizing(
            $first->payload,
            $first->fresh()->payload,
            'Saving a new version must not modify the previous one.',
        );
        $this->assertSame(
            json_encode(self::sorted($first->payload)),
            json_encode(self::sorted($first->fresh()->payload)),
            'Saving a new version must not change a single value on it.',
        );
    }

    public function test_as_at_resolves_the_version_in_force_at_a_moment(): void
    {
        $original = $this->rules->currentModel(self::SHOP);

        $this->travel(2)->days();

        $updated = $this->rules->save(self::SHOP, RuleSet::defaults());

        $before = $this->rules->asAt(self::SHOP, now()->subDay());
        $after = $this->rules->asAt(self::SHOP, now());

        $this->assertSame($original->id, $before->versionId);
        $this->assertSame($updated->id, $after->versionId);
    }

    public function test_a_rule_change_is_never_retrospective(): void
    {
        $this->rules->save(self::SHOP, RuleSet::defaults(['voucher_value_pence' => 1000]));

        // An event dated before the change still sees the launch value.
        $earlier = $this->rules->asAt(self::SHOP, now()->subDays(5));

        $this->assertSame(500, $earlier->voucherValuePence());
        $this->assertSame(1000, $this->rules->current(self::SHOP)->voucherValuePence());
    }

    public function test_points_must_be_able_to_mature_before_they_expire(): void
    {
        $this->expectException(ValidationException::class);

        RuleSchema::validate(RuleSet::defaults([
            'pending_period_days' => 60,
            'points_expiry_days' => 30,
        ]));
    }

    public function test_a_cap_below_one_voucher_increment_is_refused(): void
    {
        try {
            RuleSchema::validate(RuleSet::defaults([
                'voucher_value_pence' => 500,
                'max_redemption_per_order_pence' => 100,
            ]));

            $this->fail('A cap below one increment was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('max_redemption_per_order_pence', $e->errors());
        }
    }

    public function test_full_price_only_qualification_is_refused_while_c3_is_open(): void
    {
        try {
            RuleSchema::validate(RuleSet::defaults([
                'qualification' => ['mode' => 'full_price_only'],
            ]));

            $this->fail('An unconfirmed qualification mode was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('qualification.mode', $e->errors());
        }
    }

    public function test_a_partial_payload_is_refused_because_a_version_is_a_full_snapshot(): void
    {
        try {
            RuleSchema::validate(['voucher_value_pence' => 750]);

            $this->fail('A partial rule payload was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('earn_points_per_pound', $e->errors());
        }
    }

    public function test_unrecognised_rule_keys_are_refused(): void
    {
        try {
            RuleSchema::validate(RuleSet::defaults() + ['tier_bonus_multiplier' => 2]);

            $this->fail('An unrecognised rule key was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payload', $e->errors());
        }
    }

    public function test_the_diff_names_only_what_changed(): void
    {
        $before = RuleSet::defaults();
        $after = RuleSet::defaults(['voucher_value_pence' => 750]);

        $diff = $this->rules->diff($before, $after);

        $this->assertSame(
            ['voucher_value_pence' => ['from' => 500, 'to' => 750]],
            $diff,
        );
    }
}
