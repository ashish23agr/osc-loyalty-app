<?php

namespace App\Domain\Loyalty;

use App\Domain\Rules\RuleSet;
use RuntimeException;

/**
 * Which of an order's money counts towards earning (M3, D3, Q1).
 *
 * D3, assumed and client-notified: the base is what the customer actually pays
 * for qualifying merchandise — **line totals after all discounts, excluding tax
 * and excluding shipping.** Including VAT would inflate every balance by roughly
 * a fifth, and members would see it immediately as faster accrual.
 *
 * Shipping never qualifies, so it is not a line here at all. Tax is excluded by
 * requiring each line's total to arrive ex-tax: the ORDER SOURCE is responsible
 * for that subtraction, because whether Shopify's `discountedTotalSet` already
 * includes tax depends on the shop's `taxesIncluded` setting, and guessing here
 * would be wrong on exactly half of stores.
 *
 * A line with `current_quantity` of zero contributes nothing. That is how
 * Shopify represents a line that has been refunded or removed by an edit, and
 * counting it would pay a member for something they no longer have.
 */
final class QualifyingValueResolver
{
    /**
     * @param  list<array{
     *     id?:string|int,
     *     discounted_total_ex_tax_pence:int,
     *     current_quantity?:int,
     *     tags?:list<string>,
     *     collections?:list<string>
     * }>  $lines
     * @return array{qualifying_pence:int, excluded_pence:int, lines:list<array{id:mixed, pence:int, qualifies:bool, reason:?string}>}
     */
    public function resolve(array $lines, RuleSet $rules): array
    {
        $qualification = $rules->qualification();
        $mode = (string) ($qualification['mode'] ?? 'all');

        if ($mode === 'full_price_only') {
            // C3 is unanswered and Q1 asks whether the rule is wanted at all.
            // RuleSchema already refuses to save it; refusing here too means a
            // rule version saved before that guard existed cannot quietly pay
            // out on a rule nobody has agreed.
            throw new RuntimeException(
                'Full-price-only qualification is not confirmed (C3) and must not be applied to earning.'
            );
        }

        $qualifying = 0;
        $excluded = 0;
        $decisions = [];

        foreach ($lines as $line) {
            $pence = (int) $line['discounted_total_ex_tax_pence'];
            $quantity = (int) ($line['current_quantity'] ?? 1);

            [$qualifies, $reason] = $this->decide($line, $mode, $qualification, $quantity);

            if ($qualifies) {
                $qualifying += $pence;
            } else {
                $excluded += $pence;
            }

            $decisions[] = [
                'id' => $line['id'] ?? null,
                'pence' => $pence,
                'qualifies' => $qualifies,
                'reason' => $reason,
            ];
        }

        return [
            // Never negative. A line total should not arrive negative, but a
            // reversal represented as one must not turn earning into a credit.
            'qualifying_pence' => max(0, $qualifying),
            'excluded_pence' => $excluded,
            'lines' => $decisions,
        ];
    }

    /**
     * @return array{0:bool, 1:?string}
     */
    private function decide(array $line, string $mode, array $qualification, int $quantity): array
    {
        if ($quantity <= 0) {
            return [false, 'no_quantity_remaining'];
        }

        $tags = array_map('strtolower', (array) ($line['tags'] ?? []));
        $collections = array_map('strtolower', (array) ($line['collections'] ?? []));

        // Exclusions win over inclusions in every mode. A merchant who has
        // both listed a collection and excluded a tag inside it means the
        // exclusion, or the exclusion would be unreachable and pointless.
        if (array_intersect($collections, $this->lower($qualification['exclude_collections'] ?? [])) !== []) {
            return [false, 'excluded_collection'];
        }

        if (array_intersect($tags, $this->lower($qualification['exclude_tags'] ?? [])) !== []) {
            return [false, 'excluded_tag'];
        }

        return match ($mode) {
            'collections' => array_intersect($collections, $this->lower($qualification['include_collections'] ?? [])) !== []
                ? [true, null]
                : [false, 'not_in_an_included_collection'],
            'tags' => array_intersect($tags, $this->lower($qualification['include_tags'] ?? [])) !== []
                ? [true, null]
                : [false, 'not_tagged_for_inclusion'],
            // 'all': the Blueprint position. Sale and promotional stock earn,
            // and only the exclusion lists take anything out.
            default => [true, null],
        };
    }

    /** @return list<string> */
    private function lower(array $values): array
    {
        return array_map(fn ($value): string => strtolower((string) $value), $values);
    }
}
