<?php

namespace Tests\Support;

use App\Domain\Redemption\MetafieldWriter;

/**
 * The customer metafield the discount function reads, without a shop.
 *
 * Records what was written so a test can assert the exact payload the function
 * will see — which matters more than usual here, because that payload is the
 * only thing standing between a member's entitlement and a customer setting
 * their own discount.
 */
class FakeMetafieldWriter implements MetafieldWriter
{
    /** @var array<string, array<string, mixed>> */
    public array $written = [];

    /** @var list<string> */
    public array $cleared = [];

    public bool $shouldFail = false;

    public function write(string $shopDomain, string $ownerGid, string $key, array $value): bool
    {
        if ($this->shouldFail) {
            return false;
        }

        $this->written[$this->slot($shopDomain, $ownerGid, $key)] = $value;

        return true;
    }

    public function writeMany(string $shopDomain, array $metafields): int
    {
        $written = 0;

        foreach ($metafields as $metafield) {
            if ($this->write($shopDomain, $metafield['owner_gid'], $metafield['key'], $metafield['value'])) {
                $written++;
            }
        }

        return $written;
    }

    public function clear(string $shopDomain, string $ownerGid, string $key): bool
    {
        if ($this->shouldFail) {
            return false;
        }

        $slot = $this->slot($shopDomain, $ownerGid, $key);

        unset($this->written[$slot]);
        $this->cleared[] = $slot;

        return true;
    }

    public function shopGid(string $shopDomain): ?string
    {
        return 'gid://shopify/Shop/1';
    }

    /** What the function would read for this customer, or null. */
    public function readFor(string $shopDomain, int $shopifyCustomerId, string $key = 'voucher'): ?array
    {
        return $this->readOwner($shopDomain, 'gid://shopify/Customer/'.$shopifyCustomerId, $key);
    }

    /** What the function would read for any owner, or null. */
    public function readOwner(string $shopDomain, string $ownerGid, string $key): ?array
    {
        return $this->written[$this->slot($shopDomain, $ownerGid, $key)] ?? null;
    }

    private function slot(string $shopDomain, string $ownerGid, string $key): string
    {
        return $shopDomain.':'.$ownerGid.':'.$key;
    }
}
