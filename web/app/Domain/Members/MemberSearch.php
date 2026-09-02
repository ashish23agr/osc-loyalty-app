<?php

namespace App\Domain\Members;

use App\Models\LoyaltyAccount;
use App\Support\Members\MemberCardNumber;
use Illuminate\Database\Eloquent\Builder;

/**
 * One search box over five identifiers.
 *
 * MD1 is the reason this is a class rather than a where clause in a controller:
 * a member with no email address must still be findable, so the query can never
 * be rooted in the email column. Every field is searched at once by default and
 * the console offers the same list as an explicit filter, which is also what
 * the POS lookup will consume in Sprint 3.
 *
 * Email and name are matched anywhere in the value because staff type a
 * fragment; postcode and card numbers are matched from the start because they
 * are typed in full and the indexes can then be used.
 */
final class MemberSearch
{
    public const FIELDS = ['all', 'email', 'name', 'postcode', 'card', 'legacy_card'];

    public static function apply(Builder $query, string $term, string $field = 'all'): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term, $field): void {
            match ($field) {
                'email' => self::email($q, $term),
                'name' => self::name($q, $term),
                'postcode' => self::postcode($q, $term),
                'card' => self::cardNumber($q, $term),
                'legacy_card' => self::legacyCard($q, $term),
                default => self::everything($q, $term),
            };
        });
    }

    private static function everything(Builder $q, string $term): void
    {
        self::email($q, $term);
        $q->orWhere(fn (Builder $inner) => self::name($inner, $term));
        $q->orWhere(fn (Builder $inner) => self::postcode($inner, $term));
        $q->orWhere(fn (Builder $inner) => self::legacyCard($inner, $term));
        $q->orWhere(fn (Builder $inner) => self::cardNumber($inner, $term));
    }

    private static function email(Builder $q, string $term): void
    {
        // Matched against the normalised column, so the same lower-casing that
        // deduplication relies on is what search sees.
        $q->orWhere('email_normalised', 'like', '%'.self::escape(mb_strtolower($term)).'%');
    }

    private static function name(Builder $q, string $term): void
    {
        $tokens = preg_split('/\s+/', $term) ?: [];
        $tokens = array_values(array_filter($tokens, fn (string $t): bool => $t !== ''));

        if ($tokens === []) {
            return;
        }

        if (count($tokens) === 1) {
            $like = '%'.self::escape($tokens[0]).'%';
            $q->orWhere('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like);

            return;
        }

        // "margaret whitfield" and "whitfield margaret" are the same person, and
        // staff type both, so each ordering is tried rather than assuming one.
        $first = '%'.self::escape($tokens[0]).'%';
        $last = '%'.self::escape($tokens[count($tokens) - 1]).'%';

        $q->orWhere(function (Builder $inner) use ($first, $last): void {
            $inner->where('first_name', 'like', $first)->where('last_name', 'like', $last);
        })->orWhere(function (Builder $inner) use ($first, $last): void {
            $inner->where('first_name', 'like', $last)->where('last_name', 'like', $first);
        });
    }

    private static function postcode(Builder $q, string $term): void
    {
        $normalised = LoyaltyAccount::normalisePostcode($term);

        if ($normalised === null) {
            return;
        }

        // Prefix, so "SP4" finds the district and ix_account_postcode is usable.
        $q->orWhere('postcode_normalised', 'like', self::escape($normalised).'%');
    }

    private static function legacyCard(Builder $q, string $term): void
    {
        $q->orWhere('legacy_card_number', 'like', self::escape($term).'%');
    }

    /**
     * The club card number is derived from the account id, so a search for one
     * is an exact primary-key lookup rather than a scan.
     */
    private static function cardNumber(Builder $q, string $term): void
    {
        $id = MemberCardNumber::parse($term);

        if ($id !== null) {
            $q->orWhere('id', $id);
        }
    }

    /** A member typing a % or _ into the search box is searching, not globbing. */
    private static function escape(string $term): string
    {
        return addcslashes($term, '%_'.chr(92));
    }
}
