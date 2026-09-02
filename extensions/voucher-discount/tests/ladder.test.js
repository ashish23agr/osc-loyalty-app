import fs from 'fs';
import path from 'path';
import {describe, expect, test} from 'vitest';

import {quote, toPence} from '../src/ladder.ts';

/**
 * The TypeScript half of the shared arithmetic contract (D8).
 *
 * This reads THE SAME FILE as the PHPUnit suite in web/ — not a copy of it, and
 * not a fixture generated from it. The redemption ladder is implemented twice,
 * once in PHP for the quote and once here for the discount function, because a
 * Shopify Function cannot call back into the app at cart time. Two
 * implementations of one business rule will drift, and this is the thing that
 * stops it: change the rule and you change the file, which fails whichever side
 * has not been updated.
 *
 * The path is deliberately relative to the project root rather than copied
 * locally. A copied fixture is a fixture that goes stale silently.
 */
const FIXTURES = path.resolve(__dirname, '../../../fixtures/loyalty-arithmetic.json');

const shared = JSON.parse(fs.readFileSync(FIXTURES, 'utf8'));

/** The rule values the ladder needs, from a named set plus any per-case override. */
function rulesFor(testCase) {
  const named = shared.rule_sets[testCase.rules];

  return {
    voucher_value_pence: 500,
    max_redemption_per_order_pence: 5000,
    min_basket_pence: 0,
    ...named,
    ...(testCase.override_rules ?? {}),
  };
}

describe('the shared fixture file', () => {
  test('is the same file the PHP suite reads', () => {
    expect(fs.existsSync(FIXTURES)).toBe(true);
    expect(shared._meta.purpose).toMatch(/consumed by BOTH test suites/i);
  });

  test('carries the agreed ladder contract', () => {
    expect(shared.redemption_ladder.length).toBeGreaterThanOrEqual(10);
  });
});

describe('the redemption ladder', () => {
  shared.redemption_ladder.forEach((testCase) => {
    test(testCase.name, () => {
      const result = quote(
        testCase.input.voucher_balance_pence,
        testCase.input.eligible_subtotal_pence,
        testCase.input.basket_total_pence,
        rulesFor(testCase),
      );

      expect(result.redeemable_pence).toBe(testCase.expect.redeemable_pence);
      expect(result.reason).toBe(testCase.expect.reason);
    });
  });

  /**
   * Asserted as properties rather than as numbers, so a new fixture case cannot
   * pass by having its expectation written to match a wrong answer.
   */
  shared.redemption_ladder.forEach((testCase) => {
    test(`never exceeds any rung: ${testCase.name}`, () => {
      const rules = rulesFor(testCase);
      const {voucher_balance_pence, eligible_subtotal_pence, basket_total_pence} = testCase.input;

      const offered = quote(
        voucher_balance_pence,
        eligible_subtotal_pence,
        basket_total_pence,
        rules,
      ).redeemable_pence;

      expect(offered).toBeLessThanOrEqual(voucher_balance_pence);
      expect(offered).toBeLessThanOrEqual(eligible_subtotal_pence);
      expect(offered).toBeLessThanOrEqual(rules.max_redemption_per_order_pence);

      if (offered > 0) {
        // A voucher reduces a sale; it does not settle one.
        expect(offered).toBeLessThan(basket_total_pence);
        expect(offered % rules.voucher_value_pence).toBe(0);
      }
    });
  });

  test("the agreed UI's rejection example is in the contract", () => {
    const example = shared.redemption_ladder.find(
      (c) => c.name === "The agreed UI's rejection example: 70 pound basket, 45 eligible, offer 45",
    );

    expect(example, 'The UI file worked example is missing from the ladder fixtures.').toBeDefined();
    expect(example.expect.redeemable_pence).toBe(4500);
  });
});

describe('money at the boundary', () => {
  test('reads the decimal strings Shopify actually sends', () => {
    expect(toPence('6.0')).toBe(600);
    expect(toPence('72.40')).toBe(7240);
    expect(toPence('0.0')).toBe(0);
    expect(toPence('0.05')).toBe(5);
    expect(toPence('1234.56')).toBe(123456);
  });

  test('a missing amount is nothing, not a crash', () => {
    expect(toPence(null)).toBe(0);
    expect(toPence(undefined)).toBe(0);
    expect(toPence('not money')).toBe(0);
  });
});
