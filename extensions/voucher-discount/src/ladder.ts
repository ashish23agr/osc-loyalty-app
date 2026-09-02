/**
 * The redemption limit ladder (D8) — the Wasm half.
 *
 * This is the SECOND implementation of one business rule. The first is
 * `App\Domain\Loyalty\RedemptionLadder` in PHP, which answers the quote the POS
 * tile and the storefront ask for. This one runs inside the discount function at
 * cart time, where it cannot call back into the app.
 *
 * Two implementations of one rule will drift. Both read
 * `fixtures/loyalty-arithmetic.json` at the project root, and changing the rule
 * means changing that file — which fails whichever side has not been updated.
 * If you are editing this file, the PHP one almost certainly needs the same
 * edit.
 *
 * Everything here is integer pence. Shopify hands money to a function as a
 * decimal string in the cart's currency, so the conversion happens once, at the
 * boundary, in `toPence`.
 */

export const NO_VOUCHER_BALANCE = 'no_voucher_balance';
export const NO_ELIGIBLE_ITEMS = 'no_eligible_items';
export const MINIMUM_BASKET_NOT_MET = 'minimum_basket_not_met';
export const BELOW_VOUCHER_INCREMENT = 'below_voucher_increment';

export interface LadderRules {
  voucher_value_pence: number;
  max_redemption_per_order_pence: number;
  min_basket_pence: number;
}

export interface LadderResult {
  redeemable_pence: number;
  reason: string | null;
}

/**
 * A decimal money string as integer pence.
 *
 * Shopify sends "6.0", "72.40", "0.0". Multiplying a float by 100 and rounding
 * is safe at these magnitudes and to two decimal places; what is not safe is
 * carrying the float any further, so nothing downstream of here sees one.
 */
export function toPence(amount: string | number | null | undefined): number {
  if (amount === null || amount === undefined) {
    return 0;
  }

  const value = typeof amount === 'number' ? amount : parseFloat(amount);

  return Number.isFinite(value) ? Math.round(value * 100) : 0;
}

function floorToMultipleOf(amount: number, increment: number): number {
  if (increment <= 0 || amount <= 0) {
    return 0;
  }

  return Math.floor(amount / increment) * increment;
}

/**
 * How much voucher value this basket may take.
 *
 * The order is the decision, and it is the same order as the PHP:
 *
 *   1. the eligible subtotal      — never more than the qualifying items are worth
 *   2. the minimum basket check   — a rejection, not a reduction
 *   3. the configured cap
 *   4. strictly below the basket  — a voucher reduces a sale, it does not settle one
 *   5. round down to the increment
 *
 * Rounding before the cap, or capping before the eligible subtotal, gives a
 * different answer on the same basket.
 */
export function quote(
  voucherBalancePence: number,
  eligibleSubtotalPence: number,
  basketTotalPence: number,
  rules: LadderRules,
): LadderResult {
  if (voucherBalancePence <= 0) {
    return {redeemable_pence: 0, reason: NO_VOUCHER_BALANCE};
  }

  if (eligibleSubtotalPence <= 0) {
    return {redeemable_pence: 0, reason: NO_ELIGIBLE_ITEMS};
  }

  if (basketTotalPence < rules.min_basket_pence) {
    return {redeemable_pence: 0, reason: MINIMUM_BASKET_NOT_MET};
  }

  let value = Math.min(voucherBalancePence, eligibleSubtotalPence);
  value = Math.min(value, rules.max_redemption_per_order_pence);
  value = Math.min(value, Math.max(0, basketTotalPence - 1));
  value = floorToMultipleOf(value, rules.voucher_value_pence);

  if (value <= 0) {
    return {redeemable_pence: 0, reason: BELOW_VOUCHER_INCREMENT};
  }

  return {redeemable_pence: value, reason: null};
}
