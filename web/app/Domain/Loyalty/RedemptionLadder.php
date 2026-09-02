<?php

namespace App\Domain\Loyalty;

use App\Domain\Rules\RuleSet;
use App\Support\Money\Pence;

/**
 * How much voucher value a basket may actually take (D8, M6, M7).
 *
 * The limits apply in a fixed order, and the order is the decision:
 *
 *   1. the eligible subtotal      - never more than the qualifying items are worth
 *   2. the minimum basket check   - a rejection, not a reduction
 *   3. the configured cap         - max_redemption_per_order_pence
 *   4. strictly below the basket  - a voucher may not settle the whole sale
 *   5. round down to the increment
 *
 * Rounding before the cap, or capping before the eligible subtotal, produces
 * different answers on the same basket. That is why this is one function with a
 * stated sequence rather than a set of independent guards.
 *
 * **This arithmetic exists twice.** Here in PHP for the quote the tile and the
 * storefront ask for, and again in TypeScript compiled to Wasm inside the
 * discount function, because a Shopify Function cannot call back into the app at
 * cart time. Two implementations of one business rule will drift, so both read
 * `fixtures/loyalty-arithmetic.json` and changing the rule means changing that
 * file - which fails whichever side has not been updated.
 *
 * A rejection carries a reason the tile can put on screen. "Nothing" with no
 * explanation is the single most expensive thing to put in front of a member of
 * staff holding a queue.
 */
final class RedemptionLadder
{
    /** No voucher value on the account at all. */
    public const NO_VOUCHER_BALANCE = 'no_voucher_balance';

    /** Nothing in the basket qualifies. */
    public const NO_ELIGIBLE_ITEMS = 'no_eligible_items';

    /** The basket is worth less than the configured minimum spend. */
    public const MINIMUM_BASKET_NOT_MET = 'minimum_basket_not_met';

    /** Something qualifies, but less than one whole voucher increment. */
    public const BELOW_VOUCHER_INCREMENT = 'below_voucher_increment';

    /**
     * @return array{
     *     redeemable_pence:int, reason:?string,
     *     binding_rung:?string, ladder:array<string, int>
     * }
     */
    public static function quote(
        int $voucherBalancePence,
        int $eligibleSubtotalPence,
        int $basketTotalPence,
        RuleSet $rules,
    ): array {
        // The rejections come first, and in this order, because each says
        // something different to the person reading it: you have nothing to
        // spend / there is nothing here to spend it on / this basket is too
        // small to qualify.
        if ($voucherBalancePence <= 0) {
            return self::refused(self::NO_VOUCHER_BALANCE);
        }

        if ($eligibleSubtotalPence <= 0) {
            return self::refused(self::NO_ELIGIBLE_ITEMS);
        }

        if ($basketTotalPence < $rules->minBasketPence()) {
            return self::refused(self::MINIMUM_BASKET_NOT_MET);
        }

        $ladder = [];

        // 1. Never more than the qualifying items are worth.
        $value = Pence::of($voucherBalancePence)->min($eligibleSubtotalPence);
        $ladder['eligible_subtotal'] = $value->toInt();

        // 3. The configured per-order cap.
        $value = $value->min($rules->maxRedemptionPerOrderPence());
        $ladder['configured_cap'] = $value->toInt();

        // 4. Strictly below the basket value: a voucher reduces a sale, it does
        //    not settle one. A basket paid entirely with voucher value takes no
        //    money and, at the till, leaves nothing to tender against.
        $value = $value->min(max(0, $basketTotalPence - 1));
        $ladder['below_basket_value'] = $value->toInt();

        // 5. Round down to the increment, last, so every rung above it is
        //    respected and the answer is always a whole voucher.
        $value = $value->floorToMultipleOf($rules->voucherValuePence());
        $ladder['rounded_to_increment'] = $value->toInt();

        if ($value->toInt() <= 0) {
            return self::refused(self::BELOW_VOUCHER_INCREMENT) + ['ladder' => $ladder];
        }

        return [
            'redeemable_pence' => $value->toInt(),
            'reason' => null,
            'binding_rung' => self::bindingRung($ladder, $voucherBalancePence),
            'ladder' => $ladder,
        ];
    }

    /**
     * Which rung actually decided the answer.
     *
     * Not needed for the arithmetic; needed for the audit trail and for the
     * explanation the tile shows, so a member asking "why only twenty pounds?"
     * gets the real reason rather than a shrug.
     */
    private static function bindingRung(array $ladder, int $balance): ?string
    {
        $previous = $balance;

        foreach ($ladder as $rung => $value) {
            if ($value < $previous) {
                return $rung;
            }

            $previous = $value;
        }

        return null;
    }

    /** @return array{redeemable_pence:int, reason:string, binding_rung:null, ladder:array} */
    private static function refused(string $reason): array
    {
        return [
            'redeemable_pence' => 0,
            'reason' => $reason,
            'binding_rung' => null,
            'ladder' => [],
        ];
    }
}
