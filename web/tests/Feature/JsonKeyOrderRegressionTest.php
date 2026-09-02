<?php

namespace Tests\Feature;

use App\Domain\Rules\RuleSet;
use App\Domain\Rules\RulesVersionRepository;
use App\Models\RulesVersion;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\RequestContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two places a MySQL JSON key reordering reached a person.
 *
 * MySQL's JSON columns hand map keys back in their own order, so a rule set that
 * nobody edited compares unequal to itself. Unfixed, that put `qualification`
 * into the **diff a person confirms before saving** and into the **permanent
 * audit entry** for the save, on every save.
 *
 * SQLite preserves the order it was given, so on SQLite the bug cannot occur and
 * a passing suite proves nothing. Both tests here therefore build the reordered
 * "before" by hand rather than relying on the database to produce it, which is
 * what makes them catch a regression on either connection.
 *
 * Verified by deliberately reverting Canonical::equals and confirming both fail.
 */
class JsonKeyOrderRegressionTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'loyalty-system.myshopify.com';

    /**
     * The Settings diff. The screen shows this before the save is confirmed, so
     * a false entry here is a lie told to the person making the decision.
     */
    public function test_the_rules_diff_ignores_key_order_alone(): void
    {
        $rules = app(RulesVersionRepository::class);

        $after = RuleSet::defaults();
        $before = self::reorder($after);

        $this->assertNotSame($before, $after, 'The probe must actually be reordered.');

        $this->assertSame(
            [],
            $rules->diff($before, $after),
            'A rule set that only came back in a different key order has not changed.',
        );
    }

    /** And a real change is still reported, so the guard has not gone too far. */
    public function test_the_rules_diff_still_reports_a_real_change(): void
    {
        $rules = app(RulesVersionRepository::class);

        $before = self::reorder(RuleSet::defaults());
        $after = RuleSet::defaults(['points_expiry_days' => 365]);

        $diff = $rules->diff($before, $after);

        $this->assertSame(['points_expiry_days'], array_keys($diff));
        $this->assertSame(182, $diff['points_expiry_days']['from']);
        $this->assertSame(365, $diff['points_expiry_days']['to']);
    }

    /** Reordering a LIST inside the payload is a real change and must show. */
    public function test_the_rules_diff_reports_a_reordered_exclusion_list(): void
    {
        $rules = app(RulesVersionRepository::class);

        $before = RuleSet::defaults(['qualification' => [
            'mode' => 'all',
            'include_collections' => [],
            'include_tags' => [],
            'exclude_collections' => [],
            'exclude_tags' => ['gift-card', 'clearance'],
        ]]);

        $after = RuleSet::defaults(['qualification' => [
            'mode' => 'all',
            'include_collections' => [],
            'include_tags' => [],
            'exclude_collections' => [],
            'exclude_tags' => ['clearance', 'gift-card'],
        ]]);

        $this->assertSame(
            ['qualification'],
            array_keys($rules->diff($before, $after)),
            'A list keeps its order, so reordering one is a change.',
        );
    }

    /**
     * The audit entry. Permanent, and the record a dispute is settled from.
     */
    public function test_a_model_audit_diff_ignores_key_order_alone(): void
    {
        app(RequestContext::class)->forJob(self::SHOP, 'test');

        $version = RulesVersion::create([
            'shop_domain' => self::SHOP,
            'version' => 1,
            'payload' => RuleSet::defaults(),
            'effective_from' => now()->subYear(),
            'created_by_staff_id' => null,
            'created_by_name' => 'Test',
            'change_summary' => 'Baseline',
        ]);

        // What MySQL would hand back: the same payload, keys rearranged, plus
        // one field that genuinely moved.
        $version->payload = self::reorder(RuleSet::defaults(['points_expiry_days' => 365]));
        $version->change_summary = 'Board decision';

        $entry = app(AuditLogger::class)->logModelChange(
            action: AuditAction::RULES_SAVED,
            model: $version,
            reason: 'Board decision',
        );

        $this->assertEqualsCanonicalizing(
            ['change_summary', 'payload'],
            array_keys($entry->after_state),
            'Only what actually moved belongs in the entry.',
        );
        $this->assertSame(365, $entry->after_state['payload']['points_expiry_days']);
    }

    /** A payload whose keys merely moved is not recorded as having changed. */
    public function test_a_model_audit_diff_drops_a_payload_that_only_moved_keys(): void
    {
        app(RequestContext::class)->forJob(self::SHOP, 'test');

        $version = RulesVersion::create([
            'shop_domain' => self::SHOP,
            'version' => 1,
            'payload' => RuleSet::defaults(),
            'effective_from' => now()->subYear(),
            'created_by_staff_id' => null,
            'created_by_name' => 'Test',
            'change_summary' => 'Baseline',
        ]);

        $version->payload = self::reorder(RuleSet::defaults());
        $version->change_summary = 'Renamed only';

        $entry = app(AuditLogger::class)->logModelChange(
            action: AuditAction::RULES_SAVED,
            model: $version,
            reason: 'Renamed only',
        );

        $this->assertSame(
            ['change_summary'],
            array_keys($entry->after_state),
            'The payload did not change; only MySQL reordered its keys.',
        );
    }

    /** Reverses the key order of every map, recursively. */
    private static function reorder(array $value): array
    {
        $reversed = array_is_list($value) ? $value : array_reverse($value, true);

        foreach ($reversed as $key => $item) {
            if (is_array($item)) {
                $reversed[$key] = self::reorder($item);
            }
        }

        return $reversed;
    }
}
