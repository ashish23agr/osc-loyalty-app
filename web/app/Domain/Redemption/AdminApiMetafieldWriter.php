<?php

namespace App\Domain\Redemption;

use App\Exceptions\ShopifyAdminApiException;
use App\Services\ShopifyAdminApi;
use Illuminate\Support\Facades\Log;

/**
 * The real metafield writer: `metafieldsSet` through the Admin API.
 *
 * Needs `write_customers` for the entitlement and `write_products` for the V6a
 * exclusion flags, both granted on the development store. The namespace is
 * omitted deliberately — Shopify then uses the app-reserved namespace, which is
 * the only reason the discount function can trust what it reads there.
 */
final class AdminApiMetafieldWriter implements MetafieldWriter
{
    /** `metafieldsSet` accepts 25 per call. */
    private const BATCH = 25;

    private const SET = <<<'GRAPHQL'
    mutation SetLoyaltyMetafields($metafields: [MetafieldsSetInput!]!) {
      metafieldsSet(metafields: $metafields) {
        metafields { id key namespace }
        userErrors { field message code }
      }
    }
    GRAPHQL;

    private const DELETE = <<<'GRAPHQL'
    mutation DeleteLoyaltyMetafield($metafields: [MetafieldIdentifierInput!]!) {
      metafieldsDelete(metafields: $metafields) {
        deletedMetafields { key namespace ownerId }
        userErrors { field message }
      }
    }
    GRAPHQL;

    public function write(string $shopDomain, string $ownerGid, string $key, array $value): bool
    {
        return $this->writeMany($shopDomain, [
            ['owner_gid' => $ownerGid, 'key' => $key, 'value' => $value],
        ]) === 1;
    }

    /**
     * Batched, because the exclusion sync has a whole catalogue to get through
     * and one call per product is the difference between a sync that finishes
     * and one that spends the day being rate-limited.
     */
    public function writeMany(string $shopDomain, array $metafields): int
    {
        $written = 0;

        foreach (array_chunk($metafields, self::BATCH) as $batch) {
            $payload = array_map(fn (array $metafield): array => [
                'ownerId' => $metafield['owner_gid'],
                'key' => $metafield['key'],
                // No namespace: app-reserved, and therefore unwritable by
                // anyone else. This is what the function's trust rests on.
                'type' => 'json',
                'value' => json_encode($metafield['value'], JSON_THROW_ON_ERROR),
            ], $batch);

            try {
                $data = ShopifyAdminApi::forShop($shopDomain)->query(self::SET, ['metafields' => $payload]);
            } catch (ShopifyAdminApiException $e) {
                $this->failed('write', $shopDomain, count($batch).' metafields', $e->getMessage());

                continue;
            }

            $errors = $data['metafieldsSet']['userErrors'] ?? [];

            if ($errors !== []) {
                $this->failed('write', $shopDomain, count($batch).' metafields', json_encode($errors));
            }

            $written += count($data['metafieldsSet']['metafields'] ?? []);
        }

        return $written;
    }

    public function clear(string $shopDomain, string $ownerGid, string $key): bool
    {
        try {
            $data = ShopifyAdminApi::forShop($shopDomain)->query(self::DELETE, [
                'metafields' => [['ownerId' => $ownerGid, 'key' => $key]],
            ]);
        } catch (ShopifyAdminApiException $e) {
            return $this->failed('clear', $shopDomain, $ownerGid, $e->getMessage());
        }

        $errors = $data['metafieldsDelete']['userErrors'] ?? [];

        return $errors === []
            ? true
            : $this->failed('clear', $shopDomain, $ownerGid, json_encode($errors));
    }

    /**
     * The shop's own GID, which nothing else in the app knows.
     *
     * Looked up rather than constructed: a shop GID carries the numeric shop id,
     * which this app never stores, and a guessed one would write the config onto
     * nothing at all.
     */
    public function shopGid(string $shopDomain): ?string
    {
        try {
            $data = ShopifyAdminApi::forShop($shopDomain)->query('query ShopId { shop { id } }');
        } catch (ShopifyAdminApiException $e) {
            $this->failed('read the shop id for', $shopDomain, 'shop', $e->getMessage());

            return null;
        }

        return $data['shop']['id'] ?? null;
    }

    /**
     * Logged and reported as false, never thrown.
     *
     * A failed publish means a customer is not offered their discount, which is
     * a disappointment. A thrown exception in the middle of a quote would mean a
     * redemption row exists that nothing will ever apply or clean up, which is
     * worse. The caller decides what to do about false.
     */
    private function failed(string $what, string $shop, string $owner, ?string $error): bool
    {
        Log::warning('Could not '.$what.' a loyalty metafield', [
            'shop' => $shop,
            'owner' => $owner,
            'error' => $error,
        ]);

        return false;
    }
}
