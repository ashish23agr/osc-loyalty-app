<?php

namespace App\Domain\Loyalty;

use InvalidArgumentException;

/**
 * D9c - the cumulative floor. How many points a refund reverses and restores.
 *
 * The naive rule, "floor this refund's own fraction", is wrong because floors
 * do not add up. Three seven pound refunds against an eighty pound order that
 * consumed two hundred redeemed points each floor to 17, totalling 51; the one
 * twenty-one pound refund they amount to floors to 52. The member is a point
 * down for having returned things separately, and a full refund taken in
 * instalments never gets back to zero.
 *
 * So nothing is ever rounded per refund. Every reversal is computed from the
 * CUMULATIVE refunded value, floored once, minus whatever has already been
 * posted:
 *
 *     this = floor(points * cumulative_refunded / order_eligible) - already
 *
 * Two consequences worth stating, both asserted in the fixtures:
 *
 *   1. Any sequence of partial refunds summing to X posts exactly the totals a
 *      single refund of X would have posted.
 *   2. A full refund always nets to exactly zero remaining - every earned point
 *      reversed, every redeemed point restored - no matter how it was reached.
 *
 * Integer arithmetic throughout: multiply first, then intdiv. Nothing here ever
 * sees a float, so there is no representation error to floor around.
 */
final class RefundArithmetic
{
    /**
     * The cumulative total that should stand once this refund is applied.
     *
     * intdiv truncates toward zero, which is floor for the non-negative values
     * this is defined over. The guards below are what keep it that way.
     */
    public static function cumulative(int $points, int $cumulativeRefundedPence, int $orderEligiblePence): int
    {
        if ($orderEligiblePence <= 0) {
            throw new InvalidArgumentException(
                'An order with no eligible value cannot be refunded proportionally.'
            );
        }

        if ($points < 0) {
            throw new InvalidArgumentException('Points to apportion cannot be negative.');
        }

        if ($cumulativeRefundedPence < 0) {
            throw new InvalidArgumentException('A cumulative refunded value cannot be negative.');
        }

        // A refund cannot exceed the order. Clamping rather than throwing
        // because Shopify permits an over-refund (a goodwill top-up on top of a
        // full return), and the right answer there is "everything", not a
        // failed webhook that retries for ever.
        $refunded = min($cumulativeRefundedPence, $orderEligiblePence);

        return intdiv($points * $refunded, $orderEligiblePence);
    }

    /**
     * What THIS refund posts, given what previous refunds already posted.
     *
     * Never negative: a corrected or reordered refund total that would imply
     * giving points back to the shop posts nothing rather than an entry that
     * silently takes from the member.
     */
    public static function increment(
        int $points,
        int $cumulativeRefundedPence,
        int $orderEligiblePence,
        int $alreadyPosted,
    ): int {
        $target = self::cumulative($points, $cumulativeRefundedPence, $orderEligiblePence);

        return max(0, $target - $alreadyPosted);
    }

    /**
     * Both sides of one refund in a single answer.
     *
     * The earn reversal and the redemption restore share a denominator - the
     * order's eligible value - and differ only in the quantity apportioned:
     * points earned on the order, and points the redemption consumed.
     *
     * @return array{
     *     reverse:int, restore:int,
     *     cumulative_reversed:int, cumulative_restored:int,
     *     proportion:string
     * }
     */
    public static function forRefund(
        int $pointsEarned,
        int $pointsRedeemed,
        int $cumulativeRefundedPence,
        int $orderEligiblePence,
        int $alreadyReversed = 0,
        int $alreadyRestored = 0,
    ): array {
        $cumulativeReversed = self::cumulative($pointsEarned, $cumulativeRefundedPence, $orderEligiblePence);
        $cumulativeRestored = self::cumulative($pointsRedeemed, $cumulativeRefundedPence, $orderEligiblePence);

        return [
            'reverse' => max(0, $cumulativeReversed - $alreadyReversed),
            'restore' => max(0, $cumulativeRestored - $alreadyRestored),
            'cumulative_reversed' => $cumulativeReversed,
            'cumulative_restored' => $cumulativeRestored,
            // Kept as a string for the audit trail and the ledger reason, so a
            // dispute months later reads the fraction that was applied rather
            // than a float someone has to reconstruct.
            'proportion' => min($cumulativeRefundedPence, $orderEligiblePence).'/'.$orderEligiblePence,
        ];
    }

    /** Is the order now fully refunded? The trigger for D9d's state flip. */
    public static function isFullRefund(int $cumulativeRefundedPence, int $orderEligiblePence): bool
    {
        return $orderEligiblePence > 0 && $cumulativeRefundedPence >= $orderEligiblePence;
    }
}
