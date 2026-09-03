<?php

declare(strict_types=1);

namespace App\Domain\Shop;

/**
 * The currency the shop itself is denominated in (V13).
 *
 * An interface for one reason: this is a live Admin API read on the redemption
 * path, and the suite must be able to state a shop currency without a shop.
 *
 * `null` means **could not be determined**, which is deliberately not the same
 * as "matches". Callers fail closed on it. A voucher minted without knowing the
 * shop currency is exactly the V13 defect wearing a network error as a disguise.
 */
interface ShopCurrency
{
    /** ISO 4217 code, or null if it could not be read. */
    public function for(string $shopDomain): ?string;
}
