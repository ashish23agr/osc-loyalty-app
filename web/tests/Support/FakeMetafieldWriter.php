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

    /**
     * Every write, in order, including repeats to the same slot.
     *
     * `$written` is keyed by slot so it answers "what will the function see",
     * which is the usual question. M4 asks a different one - was the balance
     * published ONCE per balance change rather than once per ledger entry - and
     * that cannot be answered by a map that overwrites.
     *
     * @var list<array{shop:string, owner:string, key:string, value:array<string, mixed>}>
     */
    public array $calls = [];

    public bool $shouldFail = false;

    public function write(string $shopDomain, string $ownerGid, string $key, array $value): bool
    {
        if ($this->shouldFail) {
            return false;
        }

        $this->written[$this->slot($shopDomain, $ownerGid, $key)] = $value;
        $this->calls[] = ['shop' => $shopDomain, 'owner' => $ownerGid, 'key' => $key, 'value' => $value];

        return true;
    }

    /**
     * Only what was written under one key.
     *
     * M4 added a second app-owned customer metafield - the standing `member`
     * position - alongside the discount function's per-quote `voucher` payload.
     * A test that means "the discount function was told nothing" must say so by
     * key, because asserting on the whole map now also asserts that the balance
     * cache was not republished, which is a different claim entirely.
     *
     * @return array<string, array<string, mixed>>
     */
    public function writtenForKey(string $key): array
    {
        return array_filter(
            $this->written,
            fn (string $slot): bool => str_ends_with($slot, ':'.$key),
            ARRAY_FILTER_USE_KEY,
        );
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
