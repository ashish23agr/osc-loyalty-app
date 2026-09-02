<?php

namespace App\Domain\Redemption;

use App\Domain\Loyalty\QualifyingValueResolver;
use App\Domain\Rules\RulesVersionRepository;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\RequestContext;

/**
 * Pushing the saved qualification rules out to where the function can read them
 * (V6a, DECIDED 31 Aug 2026).
 *
 * The requirement is that OSC configures eligible and excluded items in Settings,
 * with no developer involved, and that the answer is the same online and at the
 * till. A discount function cannot read a rule version, and its input query is
 * static — `hasAnyTag(tags: [...])` fixes its tag list at deploy time, and the
 * function schema offers no enumerable tag list to compare against instead. So
 * the rule cannot travel to the function; only its **answer** can.
 *
 * This resolves the rule down to one boolean per product and writes it as an
 * app-owned product metafield. The function then reads a single flag and never
 * learns what the rule was — which is why an include-only mode (`collections`,
 * `tags`) costs it nothing, even though it is the logical inverse of an exclude
 * list.
 *
 * The same evaluation that decides earning decides this, through
 * QualifyingValueResolver, so a product that earns nothing also redeems nothing
 * and the two cannot disagree.
 */
final class ExclusionSync
{
    /** Read by `product { loyaltyExcluded: metafield(key:) }` in the input query. */
    public const PRODUCT_KEY = 'excluded';

    /** Read by `shop { loyaltyConfig: metafield(key:) }` in the input query. */
    public const SHOP_KEY = 'exclusions';

    public function __construct(
        private readonly ProductCatalogue $catalogue,
        private readonly MetafieldWriter $metafields,
        private readonly QualifyingValueResolver $qualification,
        private readonly RulesVersionRepository $rules,
        private readonly AuditLogger $audit,
        private readonly RequestContext $context,
    ) {}

    /**
     * @return array{
     *     products:int, excluded:int, included:int, written:int,
     *     shop_config_written:bool, config_version:?int
     * }
     */
    public function run(string $shopDomain, int $batch = 250): array
    {
        $this->context->forJob($shopDomain, 'loyalty:sync-exclusions');

        $rules = $this->rules->current($shopDomain);
        $version = $rules->versionId;

        $counts = ['products' => 0, 'excluded' => 0, 'included' => 0, 'written' => 0];
        $pending = [];

        foreach ($this->catalogue->each($shopDomain) as $product) {
            // The same evaluation earning uses, on a single notional line, so
            // "does this product qualify" has exactly one implementation.
            $resolved = $this->qualification->resolve([[
                'id' => $product['gid'],
                'discounted_total_ex_tax_pence' => 1,
                'current_quantity' => 1,
                'tags' => $product['tags'],
                'collections' => $product['collections'],
            ]], $rules);

            $line = $resolved['lines'][0];
            $excluded = ! $line['qualifies'];

            $counts['products']++;
            $counts[$excluded ? 'excluded' : 'included']++;

            $pending[] = [
                'owner_gid' => $product['gid'],
                'key' => self::PRODUCT_KEY,
                'value' => [
                    'excluded' => $excluded,
                    // Why, not just what. A merchant asking "why is this not
                    // earning?" gets the rung that decided it.
                    'reason' => $line['reason'],
                    // Which rule version produced this answer, so a
                    // half-finished sync is visible rather than silent.
                    'config_version' => $version,
                ],
            ];

            if (count($pending) >= $batch) {
                $counts['written'] += $this->metafields->writeMany($shopDomain, $pending);
                $pending = [];
            }
        }

        if ($pending !== []) {
            $counts['written'] += $this->metafields->writeMany($shopDomain, $pending);
        }

        $shopWritten = $this->publishShopConfig($shopDomain, $rules, $version);

        // C12: one audit entry per run, with the counts. Not one per product —
        // that would be a copy of the catalogue.
        $this->audit->log(
            action: AuditAction::JOB_COMPLETED,
            subjectType: 'ScheduledJob',
            after: ['job' => 'loyalty:sync-exclusions', 'config_version' => $version] + $counts
                + ['shop_config_written' => $shopWritten],
            shopDomain: $shopDomain,
        );

        return $counts + ['shop_config_written' => $shopWritten, 'config_version' => $version];
    }

    /**
     * The shop-wide half: the switch, and the config for the record.
     *
     * `online_redemption_enabled` is the one the function acts on. The rest is
     * written so that what OSC configured is legible next to the per-product
     * flags it produced, rather than having to be inferred from them.
     */
    private function publishShopConfig(string $shopDomain, $rules, ?int $version): bool
    {
        $shopGid = $this->metafields->shopGid($shopDomain);

        if ($shopGid === null) {
            return false;
        }

        $qualification = $rules->qualification();

        return $this->metafields->write($shopDomain, $shopGid, self::SHOP_KEY, [
            'version' => $version,
            'online_redemption_enabled' => $rules->onlineRedemptionEnabled(),
            'pos_redemption_enabled' => $rules->posRedemptionEnabled(),
            'mode' => $qualification['mode'] ?? 'all',
            'excluded_tags' => array_values($qualification['exclude_tags'] ?? []),
            'excluded_collections' => array_values($qualification['exclude_collections'] ?? []),
            'included_tags' => array_values($qualification['include_tags'] ?? []),
            'included_collections' => array_values($qualification['include_collections'] ?? []),
        ]);
    }
}
