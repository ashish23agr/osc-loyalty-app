<?php

namespace App\Domain\Loyalty;

use App\Domain\Rules\RuleSet;

/**
 * Points earned on a paid order (M3, D3, MD6).
 *
 * Two steps, kept apart because they fail differently: the resolver decides
 * which money counts, and this decides how many points that money is worth.
 *
 * **Floor, once, per order.** Not per line. Flooring each line would lose up to
 * a point on every line of a basket, so a member buying five things would earn
 * less than the same money spent on one — and MD6 already fixed rounding down as
 * the direction for the migrated balances, so this matches. The consequence is
 * that the remainder pence are simply not paid; they are not carried forward,
 * because a carry would need a per-member balance of fractional points that
 * nothing else in the programme has.
 */
final class EarningCalculator
{
    public function __construct(private readonly QualifyingValueResolver $qualification) {}

    /**
     * @param  list<array<string, mixed>>  $lines  Line totals, ex tax, after discount.
     * @return array{
     *     points:int, qualifying_pence:int, excluded_pence:int,
     *     lines:list<array<string, mixed>>, earn_base:string
     * }
     */
    public function forOrder(array $lines, RuleSet $rules): array
    {
        $resolved = $this->qualification->resolve($lines, $rules);

        return [
            'points' => self::pointsFor($resolved['qualifying_pence'], $rules),
            'qualifying_pence' => $resolved['qualifying_pence'],
            'excluded_pence' => $resolved['excluded_pence'],
            'lines' => $resolved['lines'],
            // Recorded so an earn can be explained later without having to know
            // which rule version was in force and what it meant.
            'earn_base' => $rules->earnBase(),
        ];
    }

    /**
     * Pence to points, floored once.
     *
     * Integer arithmetic: multiply by the rate first, then divide by 100, so a
     * rate of 2 points per pound on £10.99 gives 21 rather than a float that
     * has to be rounded twice.
     */
    public static function pointsFor(int $qualifyingPence, RuleSet $rules): int
    {
        if ($qualifyingPence <= 0) {
            return 0;
        }

        return intdiv($qualifyingPence * $rules->earnPointsPerPound(), 100);
    }
}
