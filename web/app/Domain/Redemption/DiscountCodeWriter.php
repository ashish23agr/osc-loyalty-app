<?php

namespace App\Domain\Redemption;

use DateTimeInterface;

/**
 * Creating and retiring the single-use discount codes online redemption uses
 * (D5, revised 2 Sep 2026).
 *
 * An interface for the same two reasons `MetafieldWriter` is one: the real
 * implementation needs a live shop and the suite needs neither that nor the
 * network, and the GraphQL belongs in one place rather than inside the
 * gateway's logic.
 *
 * **The code is the reference.** Callers pass `PC-nnnnnnnn` as the code itself,
 * which is why this interface never invents one. That is not a convenience: the
 * reference is the only link between a paid order and the quote that produced
 * it, and `AdminApiOrderSource` already reads `DiscountCodeApplication.code`
 * back out of the order. Making them the same string is what lets `orders/paid`
 * confirm a code redemption with no new plumbing at all.
 *
 * Money is passed in pence because that is the unit the whole ledger works in.
 * Turning it into the decimal string Shopify wants is this layer's job, so no
 * caller has to think about it.
 */
interface DiscountCodeWriter
{
    /**
     * Mint one single-use code for one customer.
     *
     * The constraints Shopify enforces natively are set here and are not
     * re-checked in the app: bound to this customer, usable once, fixed amount,
     * expiring at `$endsAt`, and refused below `$minimumSubtotalPence`.
     *
     * @param  string  $code  The redemption reference, used verbatim as the code.
     * @param  string  $customerGid  Passed as a GID, so a customer id cannot be
     *                               mistaken for an account id.
     * @return string|null The discount node GID, or null if it could not be
     *                     created. Never throws: see the note on failure below.
     */
    public function create(
        string $shopDomain,
        string $code,
        int $amountPence,
        string $currencyCode,
        string $customerGid,
        DateTimeInterface $endsAt,
        int $minimumSubtotalPence,
    ): ?string;

    /**
     * Retire a code that will not be used.
     *
     * Deactivated, never deleted. A deleted code takes its history with it, and
     * the audit trail has to be able to answer what a member was offered even
     * after the offer lapsed. Must be safe to call twice, and safe on a code
     * Shopify no longer has.
     */
    public function deactivate(string $shopDomain, string $discountNodeGid): bool;
}
