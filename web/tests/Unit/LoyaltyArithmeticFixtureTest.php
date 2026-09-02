<?php

namespace Tests\Unit;

use App\Domain\Loyalty\BalanceCalculator;
use App\Domain\Loyalty\EarningCalculator;
use App\Domain\Loyalty\LotAllocator;
use App\Domain\Loyalty\QualifyingValueResolver;
use App\Domain\Loyalty\RedemptionLadder;
use App\Domain\Loyalty\RefundArithmetic;
use App\Domain\Rules\RuleSet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The PHP half of the shared arithmetic contract.
 *
 * These cases live in fixtures/loyalty-arithmetic.json at the project root and
 * are consumed by BOTH suites: this one, and the Vitest suite for the discount
 * function. The redemption ladder is implemented twice - PHP for the quote, Wasm
 * for checkout - and two implementations of one business rule will drift, so
 * changing a rule means changing the fixture, which fails whichever side was not
 * updated.
 */
class LoyaltyArithmeticFixtureTest extends TestCase
{
    private static ?array $fixtures = null;

    private static function fixtures(): array
    {
        if (self::$fixtures === null) {
            $path = dirname(__DIR__, 3).'/fixtures/loyalty-arithmetic.json';

            if (! is_file($path)) {
                self::fail('Shared fixture file is missing: '.$path);
            }

            self::$fixtures = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        }

        return self::$fixtures;
    }

    private static function ruleSetFor(array $case): RuleSet
    {
        $named = self::fixtures()['rule_sets'][$case['rules']];

        return RuleSet::fromDefaults($named)->with($case['override_rules'] ?? []);
    }

    public static function balanceDerivationProvider(): array
    {
        return array_map(
            fn (array $case): array => [$case],
            array_column(self::fixtures()['balance_derivation'], null, 'name'),
        );
    }

    #[DataProvider('balanceDerivationProvider')]
    public function test_voucher_balance_derivation(array $case): void
    {
        $result = BalanceCalculator::derive($case['available_points'], self::ruleSetFor($case));

        $this->assertSame(
            $case['expect']['increments'],
            $result->increments,
            'increments, for: '.$case['name'],
        );
        $this->assertSame(
            $case['expect']['voucher_balance_pence'],
            $result->pence(),
            'voucher_balance_pence, for: '.$case['name'],
        );
        $this->assertSame(
            $case['expect']['remainder_points'],
            $result->remainderPoints,
            'remainder_points, for: '.$case['name'],
        );
        $this->assertSame(
            $case['expect']['points_to_next_increment'],
            $result->pointsToNextIncrement,
            'points_to_next_increment, for: '.$case['name'],
        );
    }

    public static function lotAllocationProvider(): array
    {
        return array_map(
            fn (array $case): array => [$case],
            array_column(self::fixtures()['lot_allocation'], null, 'name'),
        );
    }

    #[DataProvider('lotAllocationProvider')]
    public function test_fifo_lot_allocation(array $case): void
    {
        $plan = LotAllocator::plan($case['lots'], $case['consume']);

        $this->assertSame(
            $case['expect']['allocations'],
            $plan['allocations'],
            'allocations, for: '.$case['name'],
        );
        $this->assertSame(
            $case['expect']['unallocated'],
            $plan['unallocated'],
            'unallocated, for: '.$case['name'],
        );
    }

    public static function earningProvider(): array
    {
        return array_map(
            fn (array $case): array => [$case],
            array_column(self::fixtures()['earning'], null, 'name'),
        );
    }

    /** M3 and D3: which money counts, and what it is worth in points. */
    #[DataProvider('earningProvider')]
    public function test_points_earned_on_an_order(array $case): void
    {
        $calculator = new EarningCalculator(new QualifyingValueResolver);

        $result = $calculator->forOrder($case['order']['lines'], self::ruleSetFor($case));

        $this->assertSame(
            $case['expect']['qualifying_pence'],
            $result['qualifying_pence'],
            'qualifying_pence, for: '.$case['name'],
        );
        $this->assertSame(
            $case['expect']['excluded_pence'],
            $result['excluded_pence'],
            'excluded_pence, for: '.$case['name'],
        );
        $this->assertSame(
            $case['expect']['points'],
            $result['points'],
            'points, for: '.$case['name'],
        );

        // D3 travels with the answer, so an earn can be explained later without
        // anyone having to work out which rule version was in force.
        $this->assertSame('post_discount_ex_tax_ex_shipping', $result['earn_base'], $case['name']);
    }

    /**
     * The floor is per ORDER, not per line, and this is the case that says so.
     *
     * Three £10.99 lines earn 32 points together. Flooring each line would give
     * ten apiece and cost the member two points for having bought three things
     * instead of one.
     */
    public function test_the_earning_floor_is_applied_once_per_order(): void
    {
        $case = collect(self::fixtures()['earning'])
            ->firstWhere('name', 'Several lines are floored once for the whole order, never per line');

        $this->assertNotNull($case, 'The per-order flooring case is missing from the fixtures.');

        $rules = self::ruleSetFor($case);
        $perLine = array_sum(array_map(
            fn (array $line): int => EarningCalculator::pointsFor($line['discounted_total_ex_tax_pence'], $rules),
            $case['order']['lines'],
        ));

        $this->assertSame(32, $case['expect']['points'], 'Floored once for the order.');
        $this->assertSame(30, $perLine, 'Floored per line, which is what must not happen.');
        $this->assertGreaterThan($perLine, $case['expect']['points']);
    }

    /** C3 is unanswered, so full-price-only must not quietly pay out. */
    public function test_full_price_only_qualification_is_refused_until_c3_is_answered(): void
    {
        $rules = RuleSet::fromDefaults([
            'qualification' => [
                'mode' => 'full_price_only',
                'include_collections' => [],
                'include_tags' => [],
                'exclude_collections' => [],
                'exclude_tags' => [],
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/C3/');

        (new QualifyingValueResolver)->resolve(
            [['discounted_total_ex_tax_pence' => 5000, 'current_quantity' => 1]],
            $rules,
        );
    }

    public static function lotReleaseProvider(): array
    {
        return array_map(
            fn (array $case): array => [$case],
            array_column(self::fixtures()['lot_release'], null, 'name'),
        );
    }

    /** D9a: points going back to the lots a redemption drew from. */
    #[DataProvider('lotReleaseProvider')]
    public function test_lot_release_returns_points_to_their_original_lots(array $case): void
    {
        $plan = LotAllocator::planRelease($case['allocations'], $case['release']);

        $this->assertSame(
            $case['expect']['releases'],
            $plan['releases'],
            'releases, for: '.$case['name'],
        );
        $this->assertSame(
            $case['expect']['unreleased'],
            $plan['unreleased'],
            'unreleased, for: '.$case['name'],
        );

        // The invariant D9a is built around: a lot can never be handed back
        // more than it gave, whatever the caller asks for.
        foreach ($plan['releases'] as $index => $released) {
            $allocation = $case['allocations'][$index];

            $this->assertLessThanOrEqual(
                $allocation['allocated'] - $allocation['already_released'],
                $released,
                'a lot was over-released, for: '.$case['name'],
            );
        }
    }

    public static function refundReversalProvider(): array
    {
        return array_map(
            fn (array $case): array => [$case],
            array_column(self::fixtures()['refund_reversal'], null, 'name'),
        );
    }

    /** D9c: the cumulative floor, one refund at a time. */
    #[DataProvider('refundReversalProvider')]
    public function test_refund_reversal_arithmetic(array $case): void
    {
        $result = RefundArithmetic::forRefund(
            pointsEarned: $case['input']['points_earned'],
            pointsRedeemed: $case['input']['points_redeemed'],
            cumulativeRefundedPence: $case['input']['cumulative_refunded_pence'],
            orderEligiblePence: $case['input']['order_eligible_pence'],
            alreadyReversed: $case['input']['already_reversed'],
            alreadyRestored: $case['input']['already_restored'],
        );

        foreach (['reverse', 'restore', 'cumulative_reversed', 'cumulative_restored'] as $key) {
            $this->assertSame($case['expect'][$key], $result[$key], $key.', for: '.$case['name']);
        }

        $this->assertSame(
            $case['expect']['full_refund'],
            RefundArithmetic::isFullRefund(
                $case['input']['cumulative_refunded_pence'],
                $case['input']['order_eligible_pence'],
            ),
            'full_refund, for: '.$case['name'],
        );
    }

    public static function refundSequenceProvider(): array
    {
        return array_map(
            fn (array $case): array => [$case],
            array_column(self::fixtures()['refund_reversal_sequences'], null, 'name'),
        );
    }

    /**
     * D9c's two consequences, which are the whole reason the rule is cumulative:
     * any split of a refund totals what one refund would have, and a full refund
     * always nets to exactly zero remaining.
     */
    #[DataProvider('refundSequenceProvider')]
    public function test_a_split_refund_totals_what_one_refund_would_have(array $case): void
    {
        $reversed = 0;
        $restored = 0;

        foreach ($case['expect']['steps'] as $index => $step) {
            $result = RefundArithmetic::forRefund(
                pointsEarned: $case['input']['points_earned'],
                pointsRedeemed: $case['input']['points_redeemed'],
                cumulativeRefundedPence: $step['cumulative_refunded_pence'],
                orderEligiblePence: $case['input']['order_eligible_pence'],
                alreadyReversed: $reversed,
                alreadyRestored: $restored,
            );

            $this->assertSame($step['reverse'], $result['reverse'], "step $index reverse, for: ".$case['name']);
            $this->assertSame($step['restore'], $result['restore'], "step $index restore, for: ".$case['name']);

            $reversed += $result['reverse'];
            $restored += $result['restore'];
        }

        $this->assertSame($case['expect']['total_reversed'], $reversed, 'total reversed, for: '.$case['name']);
        $this->assertSame($case['expect']['total_restored'], $restored, 'total restored, for: '.$case['name']);

        // The same total, taken in one refund rather than several, must post
        // exactly the same amounts. This is the assertion the rule exists for.
        $inOneGo = RefundArithmetic::forRefund(
            pointsEarned: $case['input']['points_earned'],
            pointsRedeemed: $case['input']['points_redeemed'],
            cumulativeRefundedPence: end($case['input']['refunds_cumulative_pence']),
            orderEligiblePence: $case['input']['order_eligible_pence'],
        );

        $this->assertSame($inOneGo['reverse'], $reversed, 'split vs single reversed, for: '.$case['name']);
        $this->assertSame($inOneGo['restore'], $restored, 'split vs single restored, for: '.$case['name']);

        if ($case['expect']['full_refund']) {
            $this->assertSame(
                $case['input']['points_earned'],
                $reversed,
                'a full refund must reverse every earned point, for: '.$case['name'],
            );
            $this->assertSame(
                $case['input']['points_redeemed'],
                $restored,
                'a full refund must restore every redeemed point, for: '.$case['name'],
            );
        }
    }

    /**
     * The case that justifies the rule rather than merely exercising it.
     *
     * Three seven pound refunds floored one at a time restore 51 points; the
     * one twenty-one pound refund they add up to restores 52. If this ever
     * stops being true, the cumulative floor has been quietly replaced by
     * per-refund rounding and members are losing points for returning things
     * separately.
     */
    public function test_per_refund_rounding_would_lose_the_member_a_point(): void
    {
        $case = collect(self::fixtures()['refund_reversal_sequences'])
            ->firstWhere('name', 'Three 7 pound refunds equal one 21 pound refund, entry for entry');

        $this->assertNotNull($case, 'The three-instalment case is missing from the fixtures.');

        $this->assertSame(
            52,
            $case['expect']['total_restored'],
            'The cumulative floor must restore 52.',
        );
        $this->assertSame(
            51,
            $case['per_refund_floor_would_total']['restored'],
            'Per-refund flooring is recorded as restoring 51 - the point being lost.',
        );
        $this->assertNotSame(
            $case['per_refund_floor_would_total']['restored'],
            $case['expect']['total_restored'],
            'If these agree the case no longer demonstrates anything.',
        );
    }

    public static function redemptionLadderProvider(): array
    {
        return array_map(
            fn (array $case): array => [$case],
            array_column(self::fixtures()['redemption_ladder'], null, 'name'),
        );
    }

    /**
     * D8, the PHP half. The TypeScript half of this same contract lives in
     * extensions/voucher-discount/tests/ladder.test.js and reads this same file.
     */
    #[DataProvider('redemptionLadderProvider')]
    public function test_the_redemption_ladder(array $case): void
    {
        $result = RedemptionLadder::quote(
            voucherBalancePence: $case['input']['voucher_balance_pence'],
            eligibleSubtotalPence: $case['input']['eligible_subtotal_pence'],
            basketTotalPence: $case['input']['basket_total_pence'],
            rules: self::ruleSetFor($case),
        );

        $this->assertSame(
            $case['expect']['redeemable_pence'],
            $result['redeemable_pence'],
            'redeemable_pence, for: '.$case['name'],
        );
        $this->assertSame(
            $case['expect']['reason'],
            $result['reason'],
            'reason, for: '.$case['name'],
        );
    }

    /**
     * A quote is never more than any single rung allows.
     *
     * Asserted as properties rather than as numbers, so a new fixture case
     * cannot pass by having its expectation written to match a wrong answer.
     */
    #[DataProvider('redemptionLadderProvider')]
    public function test_a_quote_never_exceeds_any_rung(array $case): void
    {
        $rules = self::ruleSetFor($case);
        $input = $case['input'];

        $offered = RedemptionLadder::quote(
            $input['voucher_balance_pence'],
            $input['eligible_subtotal_pence'],
            $input['basket_total_pence'],
            $rules,
        )['redeemable_pence'];

        $this->assertLessThanOrEqual($input['voucher_balance_pence'], $offered, 'over the balance');
        $this->assertLessThanOrEqual($input['eligible_subtotal_pence'], $offered, 'over the eligible subtotal');
        $this->assertLessThanOrEqual($rules->maxRedemptionPerOrderPence(), $offered, 'over the cap');

        if ($offered > 0) {
            $this->assertLessThan($input['basket_total_pence'], $offered, 'not below the basket value');
            $this->assertSame(0, $offered % $rules->voucherValuePence(), 'not a whole increment');
        }
    }

    /** The agreed UI's rejection example, asserted by name so it cannot be dropped. */
    public function test_the_agreed_uis_rejection_example_is_in_the_contract(): void
    {
        $case = collect(self::fixtures()['redemption_ladder'])
            ->firstWhere('name', "The agreed UI's rejection example: 70 pound basket, 45 eligible, offer 45");

        $this->assertNotNull($case, 'The UI file worked example is missing from the ladder fixtures.');
        $this->assertSame(7000, $case['input']['basket_total_pence']);
        $this->assertSame(4500, $case['input']['eligible_subtotal_pence']);
        $this->assertSame(4500, $case['expect']['redeemable_pence'], 'The tile offers 45, not the 50 held.');
    }

    /**
     * The ladder is sprint 3 work (M6/M7). The fixtures exist now because they
     * are the agreed contract, so this test asserts the contract is present and
     * well-formed rather than silently passing on an empty set.
     */
    public function test_the_redemption_ladder_contract_is_present_for_sprint_three(): void
    {
        $cases = self::fixtures()['redemption_ladder'];

        $this->assertGreaterThanOrEqual(8, count($cases), 'The agreed ladder cases are missing.');

        foreach ($cases as $case) {
            $this->assertArrayHasKey('voucher_balance_pence', $case['input'], $case['name']);
            $this->assertArrayHasKey('eligible_subtotal_pence', $case['input'], $case['name']);
            $this->assertArrayHasKey('basket_total_pence', $case['input'], $case['name']);
            $this->assertArrayHasKey('redeemable_pence', $case['expect'], $case['name']);
            $this->assertIsInt($case['expect']['redeemable_pence'], $case['name']);
        }

        // The Blueprint worked example must be one of them, or the contract has
        // drifted from the specification it came from.
        $blueprintCase = collect($cases)->firstWhere('expect.redeemable_pence', 5000);
        $this->assertNotNull($blueprintCase, 'The Blueprint worked example is missing from the ladder fixtures.');
    }
}
