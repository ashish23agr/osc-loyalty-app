<?php

namespace App\Domain\Redemption;

/**
 * Writing the app-owned metafields the discount function reads.
 *
 * Owner-agnostic, because V6a needs three owners for three different jobs:
 *
 *   Customer  the member's voucher entitlement (D5, V4)
 *   Product   one resolved eligibility boolean per product (V6a)
 *   Shop      the shop-wide switch (V6a)
 *
 * An interface for the same reason OrderSource is one: the real implementation
 * needs a live shop, and the suite needs neither that nor the network. It also
 * keeps the GraphQL in one place rather than inside each caller's logic.
 *
 * Owners are passed as GIDs rather than ids so a caller cannot write a customer
 * payload onto a product by getting an argument order wrong.
 */
interface MetafieldWriter
{
    /** @param  array<string, mixed>  $value  Written as a JSON metafield. */
    public function write(string $shopDomain, string $ownerGid, string $key, array $value): bool;

    public function clear(string $shopDomain, string $ownerGid, string $key): bool;

    /**
     * Write many at once.
     *
     * `metafieldsSet` accepts up to 25 per call, and the exclusion sync has a
     * whole catalogue to get through, so batching is the difference between a
     * sync that finishes and one that spends the day rate-limited.
     *
     * @param  list<array{owner_gid:string, key:string, value:array<string, mixed>}>  $metafields
     * @return int How many were written.
     */
    public function writeMany(string $shopDomain, array $metafields): int;

    /** The GID for a shop-owned metafield, which the caller cannot construct. */
    public function shopGid(string $shopDomain): ?string;
}
