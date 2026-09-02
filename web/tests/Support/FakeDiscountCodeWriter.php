<?php

namespace Tests\Support;

use App\Domain\Redemption\DiscountCodeWriter;
use DateTimeInterface;

/**
 * The discount codes online redemption mints, without a shop.
 *
 * Records the whole payload rather than just the fact of a call, because the
 * constraints Shopify enforces natively — the customer binding, the single use,
 * the amount, the expiry — are exactly the ones the app deliberately does NOT
 * re-check. If they are minted wrong, nothing downstream notices, so the
 * assertion has to happen here.
 */
class FakeDiscountCodeWriter implements DiscountCodeWriter
{
    /** @var array<string, array<string, mixed>> Keyed by code. */
    public array $created = [];

    /** @var list<string> Node GIDs passed to deactivate(), in order. */
    public array $deactivated = [];

    /** Make the next mint fail, the way a rate limit or a duplicate code would. */
    public bool $shouldFail = false;

    private int $nextId = 1;

    public function create(
        string $shopDomain,
        string $code,
        int $amountPence,
        string $currencyCode,
        string $customerGid,
        DateTimeInterface $endsAt,
        int $minimumSubtotalPence,
    ): ?string {
        if ($this->shouldFail) {
            return null;
        }

        $gid = 'gid://shopify/DiscountCodeNode/'.$this->nextId++;

        $this->created[$code] = [
            'shop_domain' => $shopDomain,
            'code' => $code,
            'amount_pence' => $amountPence,
            'currency_code' => $currencyCode,
            'customer_gid' => $customerGid,
            'ends_at' => $endsAt->format(DATE_ATOM),
            'minimum_subtotal_pence' => $minimumSubtotalPence,
            'node_gid' => $gid,
        ];

        return $gid;
    }

    public function deactivate(string $shopDomain, string $discountNodeGid): bool
    {
        $this->deactivated[] = $discountNodeGid;

        return true;
    }

    /** What was minted for this reference, or null. */
    public function readCode(string $code): ?array
    {
        return $this->created[$code] ?? null;
    }

    public function wasDeactivated(string $discountNodeGid): bool
    {
        return in_array($discountNodeGid, $this->deactivated, true);
    }
}
