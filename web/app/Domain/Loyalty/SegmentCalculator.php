<?php

namespace App\Domain\Loyalty;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Active, Lapsed, or Unknown, from one date (M9).
 *
 * A pure function of the last qualifying spend and the run date, so the
 * boundaries can be tested without a database and the nightly job has nothing
 * to decide.
 *
 * "Unknown" is deliberately not "lapsed". A member with no recorded spend might
 * be someone who has never bought anything, or might be a migrated member whose
 * history has not landed yet, and marketing to those two as though they were
 * the same is how a launch upsets people. They stay unknown until migration or
 * a first order settles it.
 */
final class SegmentCalculator
{
    /** The Blueprint window: qualifying spend within two years. */
    public const WINDOW_YEARS = 2;

    /**
     * The window is inclusive at its far edge: spend exactly two years ago to
     * the second still counts as active. A boundary has to fall one way, and
     * this is the way that never lapses a member on the anniversary of the
     * purchase that was keeping them active.
     */
    public static function for(
        ?DateTimeInterface $lastQualifyingSpendAt,
        ?DateTimeInterface $asOf = null,
        int $windowYears = self::WINDOW_YEARS,
    ): string {
        if ($lastQualifyingSpendAt === null) {
            return 'unknown';
        }

        $cutoff = Carbon::parse($asOf ?? now())->subYears($windowYears);

        return Carbon::parse($lastQualifyingSpendAt)->lessThan($cutoff) ? 'lapsed' : 'active';
    }
}
