<?php

namespace App\Support\Data;

/**
 * Comparing two decoded JSON values for a real difference.
 *
 * MySQL's JSON columns do not preserve key order. A payload written as
 * {mode, include_collections, include_tags, ...} comes back as
 * {mode, exclude_tags, include_tags, ...}, so a plain !== against the array
 * that was written reports a change in a value nothing touched.
 *
 * That is not cosmetic. It reached two places that face a person:
 *
 *   - the rules diff the Settings screen shows before a save is confirmed,
 *     which would list `qualification` as changed every single time;
 *   - the audit log entry for the save, which would record the same fiction
 *     permanently.
 *
 * SQLite stores the JSON text verbatim and hands it back in the order it was
 * written, so neither shows up in a suite that runs on SQLite. This is the same
 * class of blind spot as an unsigned column: the engine the tests use is more
 * forgiving than the engine production uses.
 *
 * A LIST keeps its order, because the order of a list is part of its value —
 * reordering the excluded collections IS a change. Only string-keyed maps are
 * canonicalised.
 */
final class Canonical
{
    /** Are these the same value, allowing for map key order? */
    public static function equals(mixed $a, mixed $b): bool
    {
        return self::of($a) === self::of($b);
    }

    /** The value with every map key-sorted, recursively. */
    public static function of(mixed $value): mixed
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (is_array($decoded)) {
                $value = $decoded;
            }
        }

        if (! is_array($value)) {
            return $value;
        }

        $canonical = array_map(self::of(...), $value);

        if (! array_is_list($canonical)) {
            ksort($canonical);
        }

        return $canonical;
    }
}
