<?php

namespace Tests\Feature\Admin;

use App\Domain\Redemption\ExclusionSync;
use App\Domain\Redemption\MetafieldWriter;
use App\Domain\Redemption\ProductCatalogue;
use App\Domain\Rules\RuleSet;
use App\Domain\Rules\RulesVersionRepository;
use App\Jobs\SyncExclusionsToShopify;
use App\Models\AuditEntry;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeMetafieldWriter;

/**
 * V6a: the saved qualification rule reaching the discount function.
 *
 * The point of the whole mechanism is that OSC changes a rule in Settings and
 * the function's answer changes — online and at the till — with no developer
 * involved and no redeploy. These are the tests that hold that.
 */
class ExclusionSyncTest extends AdminApiTestCase
{
    private FakeMetafieldWriter $metafields;

    protected function setUp(): void
    {
        parent::setUp();

        $this->metafields = new FakeMetafieldWriter;
        $this->app->instance(MetafieldWriter::class, $this->metafields);
    }

    /** A small catalogue: a shirt, a gift card and a clearance line. */
    private function catalogue(array $products = []): void
    {
        $products = $products ?: [
            ['gid' => 'gid://shopify/Product/1', 'tags' => ['shirt'], 'collections' => ['mens']],
            ['gid' => 'gid://shopify/Product/2', 'tags' => ['gift-card'], 'collections' => []],
            ['gid' => 'gid://shopify/Product/3', 'tags' => ['sale'], 'collections' => ['clearance']],
        ];

        $this->app->instance(ProductCatalogue::class, new class($products) implements ProductCatalogue
        {
            public function __construct(private array $products) {}

            public function each(string $shopDomain): iterable
            {
                yield from $this->products;
            }
        });
    }

    private function saveRules(array $qualification): void
    {
        app(RulesVersionRepository::class)->save(self::SHOP, RuleSet::defaults([
            'qualification' => $qualification + [
                'mode' => 'all',
                'include_collections' => [],
                'include_tags' => [],
                'exclude_collections' => [],
                'exclude_tags' => [],
            ],
        ]));
    }

    private function flagFor(string $gid): ?array
    {
        return $this->metafields->readOwner(self::SHOP, $gid, ExclusionSync::PRODUCT_KEY);
    }

    // ------------------------------------------------------- the per-product flag

    public function test_the_launch_rules_exclude_nothing(): void
    {
        $this->catalogue();

        $counts = app(ExclusionSync::class)->run(self::SHOP);

        $this->assertSame(3, $counts['products']);
        $this->assertSame(0, $counts['excluded'], 'Nothing is excluded under the launch rules.');
        $this->assertSame(3, $counts['included']);

        foreach (['1', '2', '3'] as $id) {
            $this->assertFalse($this->flagFor('gid://shopify/Product/'.$id)['excluded']);
        }
    }

    /** The requirement, in one test: OSC changes a rule, the flag changes. */
    public function test_an_excluded_tag_saved_in_settings_reaches_the_product_flag(): void
    {
        $this->catalogue();
        $this->saveRules(['exclude_tags' => ['gift-card']]);

        $counts = app(ExclusionSync::class)->run(self::SHOP);

        $this->assertSame(1, $counts['excluded']);
        $this->assertTrue($this->flagFor('gid://shopify/Product/2')['excluded'], 'The gift card is out.');
        $this->assertSame('excluded_tag', $this->flagFor('gid://shopify/Product/2')['reason']);
        $this->assertFalse($this->flagFor('gid://shopify/Product/1')['excluded'], 'The shirt is not.');
    }

    public function test_an_excluded_collection_reaches_the_product_flag(): void
    {
        $this->catalogue();
        $this->saveRules(['exclude_collections' => ['clearance']]);

        app(ExclusionSync::class)->run(self::SHOP);

        $this->assertTrue($this->flagFor('gid://shopify/Product/3')['excluded']);
        $this->assertSame('excluded_collection', $this->flagFor('gid://shopify/Product/3')['reason']);
    }

    /**
     * An include-only mode is the logical inverse of an exclude list, and the
     * function never learns the difference — it reads the same one boolean.
     */
    public function test_an_include_only_mode_is_resolved_to_the_same_boolean(): void
    {
        $this->catalogue();
        $this->saveRules(['mode' => 'collections', 'include_collections' => ['mens']]);

        $counts = app(ExclusionSync::class)->run(self::SHOP);

        $this->assertSame(1, $counts['included'], 'Only the shirt is in the mens collection.');
        $this->assertSame(2, $counts['excluded']);

        $this->assertFalse($this->flagFor('gid://shopify/Product/1')['excluded']);
        $this->assertTrue($this->flagFor('gid://shopify/Product/2')['excluded']);
        $this->assertSame(
            'not_in_an_included_collection',
            $this->flagFor('gid://shopify/Product/2')['reason'],
            'And can say why, which a bare boolean could not.',
        );
    }

    /** Every flag carries the rule version that produced it. */
    public function test_each_flag_records_the_rule_version_that_produced_it(): void
    {
        $this->catalogue();
        $this->saveRules(['exclude_tags' => ['gift-card']]);

        $counts = app(ExclusionSync::class)->run(self::SHOP);

        $version = app(RulesVersionRepository::class)->current(self::SHOP)->versionId;

        $this->assertSame($version, $counts['config_version']);
        $this->assertSame($version, $this->flagFor('gid://shopify/Product/1')['config_version']);
    }

    /** Re-running writes the same answers; it does not accumulate or drift. */
    public function test_the_sync_is_idempotent(): void
    {
        $this->catalogue();
        $this->saveRules(['exclude_tags' => ['gift-card']]);

        $first = app(ExclusionSync::class)->run(self::SHOP);
        $flagsAfterFirst = $this->metafields->written;

        $second = app(ExclusionSync::class)->run(self::SHOP);

        $this->assertSame($first['excluded'], $second['excluded']);
        $this->assertSame($flagsAfterFirst, $this->metafields->written, 'Same answers, same flags.');
    }

    // ------------------------------------------------------------- the shop config

    public function test_the_shop_config_carries_the_switch_the_function_acts_on(): void
    {
        $this->catalogue();
        $this->saveRules(['exclude_tags' => ['gift-card']]);

        app(ExclusionSync::class)->run(self::SHOP);

        $config = $this->metafields->readOwner(self::SHOP, 'gid://shopify/Shop/1', ExclusionSync::SHOP_KEY);

        $this->assertNotNull($config);
        $this->assertTrue($config['online_redemption_enabled']);
        $this->assertSame('all', $config['mode']);
        $this->assertSame(['gift-card'], $config['excluded_tags']);
    }

    /** The sync reports a failed shop write rather than passing it off as done. */
    public function test_a_failed_shop_config_write_is_reported(): void
    {
        $this->catalogue();
        $this->metafields->shouldFail = true;

        $counts = app(ExclusionSync::class)->run(self::SHOP);

        $this->assertFalse($counts['shop_config_written']);
    }

    // ------------------------------------------------------------------ the trigger

    /** Saving a rule that changes qualification pushes it out. */
    public function test_saving_a_qualification_change_queues_the_sync(): void
    {
        Queue::fake();

        $this->postJson('/api/admin/rules', [
            'rules' => RuleSet::defaults(['qualification' => [
                'mode' => 'all',
                'include_collections' => [],
                'include_tags' => [],
                'exclude_collections' => [],
                'exclude_tags' => ['gift-card'],
            ]]),
            'change_summary' => 'Gift cards no longer qualify',
        ], $this->rulesHeaders())->assertStatus(201);

        Queue::assertPushed(SyncExclusionsToShopify::class);
    }

    /** And saving something else does not page a whole catalogue for nothing. */
    public function test_saving_an_unrelated_rule_does_not_queue_a_sync(): void
    {
        Queue::fake();

        $this->postJson('/api/admin/rules', [
            'rules' => RuleSet::defaults(['points_expiry_days' => 365]),
            'change_summary' => 'Longer expiry',
        ], $this->rulesHeaders())->assertStatus(201);

        Queue::assertNotPushed(SyncExclusionsToShopify::class);
    }

    /** C12: automated work is audited, one entry per run. */
    public function test_the_sync_writes_one_audit_entry_with_its_counts(): void
    {
        $this->catalogue();

        app(ExclusionSync::class)->run(self::SHOP);

        $entry = AuditEntry::query()->where('action', 'job.completed')->sole();

        $this->assertSame('system', $entry->actor_type);
        $this->assertSame('loyalty:sync-exclusions', $entry->after_state['job']);
        $this->assertSame(3, $entry->after_state['products']);
    }

    /** An Administrator's headers, since only they may save rules. */
    private function rulesHeaders(): array
    {
        return $this->headersFor('administrator', 990001);
    }
}
