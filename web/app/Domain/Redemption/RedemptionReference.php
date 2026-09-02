<?php

namespace App\Domain\Redemption;

use Illuminate\Support\Str;

/**
 * The identifier a redemption is known by, everywhere it appears (D5, D6).
 *
 * It travels further than anything else in this programme. The same string is on
 * the discount the customer sees at checkout, on the till receipt they take
 * home, in the app-owned metafield the discount function reads, on the ledger
 * entry, and in the audit log. D6 asked for exactly that: **one identifier the
 * receipt and the audit trail share**, so a dispute months later starts from
 * something the customer is holding.
 *
 * It is also what links a paid order back to the quote that produced it. There
 * is no other link: at quote time no order exists, and by the time
 * `orders/paid` arrives the only trace of the quote is the discount title. So
 * the format lives here, in one place, and both the writing and the reading of
 * it come from this class.
 *
 * **Digits only, after the prefix.** It gets read aloud across a counter and
 * typed into a search box, so the characters people confuse — O against 0, I
 * against 1 — are not in it at all.
 */
final class RedemptionReference
{
    private const PREFIX = 'PC-';

    private const DIGITS = 8;

    public static function generate(): string
    {
        return self::PREFIX.Str::password(
            self::DIGITS,
            letters: false,
            numbers: true,
            symbols: false,
            spaces: false,
        );
    }

    /**
     * Every reference in a piece of text.
     *
     * Deliberately tolerant about what surrounds it, because it is being read
     * back out of a discount title that Shopify, a POS receipt template or a
     * merchant may have wrapped in anything — "Privilege Club £50 (PC-40182233)"
     * today, something else tomorrow. What it is strict about is the shape of
     * the reference itself.
     *
     * @return list<string>
     */
    public static function extractAll(?string $text): array
    {
        if ($text === null || $text === '') {
            return [];
        }

        preg_match_all('/\b'.preg_quote(self::PREFIX, '/').'\d{'.self::DIGITS.'}\b/', $text, $matches);

        return array_values(array_unique($matches[0]));
    }

    /** Every reference across a set of strings — an order's discount titles. */
    public static function extractFromAll(array $texts): array
    {
        $found = [];

        foreach ($texts as $text) {
            foreach (self::extractAll(is_string($text) ? $text : null) as $reference) {
                $found[$reference] = true;
            }
        }

        return array_keys($found);
    }

    public static function looksValid(string $candidate): bool
    {
        return self::extractAll($candidate) === [$candidate];
    }
}
