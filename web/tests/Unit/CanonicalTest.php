<?php

namespace Tests\Unit;

use App\Domain\Rules\RuleSet;
use App\Support\Data\Canonical;
use PHPUnit\Framework\TestCase;

/**
 * The guard against a MySQL-only defect that a SQLite suite cannot see.
 *
 * MySQL's JSON columns do not preserve key order. A rules payload written as
 * {mode, include_collections, ...} comes back as {mode, exclude_tags, ...}, and
 * a plain !== against what was written reports a change in a value nothing
 * touched. That reached two places facing a person: the rules diff the Settings
 * screen shows before a save is confirmed, and the audit entry recording it.
 *
 * SQLite stores the JSON text verbatim, so on SQLite the bug is invisible and
 * the fix looks unnecessary. Everything here therefore works on **decoded
 * arrays directly**, reordered by hand to imitate what MySQL hands back, so the
 * regression is caught on either connection.
 */
class CanonicalTest extends TestCase
{
    /** The exact shape that broke: the same map, keys in a different order. */
    public function test_a_map_is_equal_to_itself_reordered(): void
    {
        $written = [
            'mode' => 'all',
            'include_collections' => [],
            'include_tags' => [],
            'exclude_collections' => [],
            'exclude_tags' => [],
        ];

        $asMysqlReturnsIt = [
            'mode' => 'all',
            'exclude_tags' => [],
            'include_tags' => [],
            'exclude_collections' => [],
            'include_collections' => [],
        ];

        $this->assertNotSame($written, $asMysqlReturnsIt, 'The probe itself must differ by order.');
        $this->assertTrue(Canonical::equals($written, $asMysqlReturnsIt));
    }

    public function test_it_reaches_into_nested_maps(): void
    {
        $a = ['rules' => ['qualification' => ['mode' => 'all', 'exclude_tags' => []]]];
        $b = ['rules' => ['qualification' => ['exclude_tags' => [], 'mode' => 'all']]];

        $this->assertTrue(Canonical::equals($a, $b));
    }

    /**
     * A list keeps its order, because the order of a list is part of its value.
     *
     * This is the half that must NOT be canonicalised: reordering the excluded
     * collections is a real change to a rule, and a comparison that sorted lists
     * would hide it.
     */
    public function test_a_reordered_list_is_a_real_change(): void
    {
        $this->assertFalse(
            Canonical::equals(
                ['exclude_tags' => ['gift-card', 'clearance']],
                ['exclude_tags' => ['clearance', 'gift-card']],
            ),
            'Reordering a list is a change and must still be reported.',
        );
    }

    public function test_a_genuine_difference_is_still_a_difference(): void
    {
        $this->assertFalse(Canonical::equals(
            ['mode' => 'all', 'exclude_tags' => []],
            ['mode' => 'collections', 'exclude_tags' => []],
        ));

        $this->assertFalse(Canonical::equals(['points_expiry_days' => 182], ['points_expiry_days' => 365]));
    }

    /** A JSON string and the array it decodes to are the same value. */
    public function test_it_decodes_a_json_string_before_comparing(): void
    {
        $this->assertTrue(Canonical::equals(
            '{"mode":"all","exclude_tags":[]}',
            ['exclude_tags' => [], 'mode' => 'all'],
        ));
    }

    public function test_scalars_and_nulls_are_compared_as_they_are(): void
    {
        $this->assertTrue(Canonical::equals(null, null));
        $this->assertTrue(Canonical::equals(182, 182));
        $this->assertFalse(Canonical::equals(182, '182'), 'Types still matter.');
        $this->assertFalse(Canonical::equals(null, 0));
    }

    /**
     * The whole launch payload, reordered the way MySQL reorders it.
     *
     * The realistic case, rather than a two-key toy: if this ever fails, every
     * rules save is about to report a change nobody made.
     */
    public function test_the_whole_launch_rule_payload_survives_reordering(): void
    {
        $written = RuleSet::defaults();
        $reordered = self::shuffleKeys($written);

        $this->assertNotSame($written, $reordered, 'The probe must actually be reordered.');
        $this->assertTrue(Canonical::equals($written, $reordered));
    }

    /** Reverses the key order of every map, recursively. */
    private static function shuffleKeys(array $value): array
    {
        $reversed = array_is_list($value) ? $value : array_reverse($value, true);

        foreach ($reversed as $key => $item) {
            if (is_array($item)) {
                $reversed[$key] = self::shuffleKeys($item);
            }
        }

        return $reversed;
    }
}
