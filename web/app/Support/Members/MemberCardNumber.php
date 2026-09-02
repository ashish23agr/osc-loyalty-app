<?php

namespace App\Support\Members;

/**
 * The Privilege Club card number shown in the console and at the till.
 *
 * Two card numbers exist in the illustrative UI: the legacy Dynamics one
 * (D-118422), which is a real migrated column, and the club card number
 * (0041-2287), which no column holds. Rather than invent storage that the
 * migration would then have to reconcile against, the club number is derived
 * from the account id and is therefore stable, unique and reversible: a member
 * can read it off a card and staff can type it into search.
 *
 * Raised as decision C11 in DECISIONS.md. If OSC issues physical cards with
 * their own numbering, this becomes a column and only this class changes.
 */
final class MemberCardNumber
{
    /** 12 -> "0000-0012". Grouped in fours so it reads back over a counter. */
    public static function format(int $accountId): string
    {
        $digits = str_pad((string) $accountId, 8, '0', STR_PAD_LEFT);

        return substr($digits, 0, -4).'-'.substr($digits, -4);
    }

    /**
     * "0041-2287", "0041 2287" or "412287" -> 412287. Null when it is not a
     * club card number at all, which is what lets one search box accept a club
     * number, a legacy number, a postcode or a name.
     *
     * The input must CONSIST of digits, optionally grouped by a single space or
     * hyphen. It is not enough for it to contain some.
     *
     * That distinction is the whole correctness of this function. Stripping
     * every non-digit and keeping the rest turns "SP4 6AB" into 46 and returns
     * whichever member happens to hold account id 46 - a different person's
     * record, surfaced by a postcode search. It stayed invisible for a while
     * because the test suite runs on SQLite, where RefreshDatabase resets the
     * auto-increment and the seeded members are always ids 1 to 3, so nothing
     * ever collided. On MySQL the ids climb across a run and it shows.
     */
    public static function parse(string $input): ?int
    {
        $trimmed = trim($input);

        // Digits, with at most one space or hyphen between groups of them.
        if (! preg_match('/^\d+(?:[ -]\d+)*$/', $trimmed)) {
            return null;
        }

        $digits = preg_replace('/[^0-9]/', '', $trimmed);

        if ($digits === '' || $digits === null || strlen($digits) > 12) {
            return null;
        }

        $id = (int) ltrim($digits, '0');

        return $id > 0 ? $id : null;
    }
}
