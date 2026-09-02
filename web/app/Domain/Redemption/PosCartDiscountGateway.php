<?php

namespace App\Domain\Redemption;

use App\Models\LoyaltyAccount;
use App\Models\Redemption;

/**
 * Redemption at the till (D6, M7, V7).
 *
 * There is nothing to publish. The POS tile holds the quote it just asked for
 * and calls `applyCartDiscount` itself, so the value never has to be left
 * somewhere for a separate process to find — which is the opposite of the online
 * case and the reason RedemptionGateway exists at all.
 *
 * **D6 correction, from the V7 spike.** The signature is
 * `applyCartDiscount(type, title, amount)`. The middle parameter is `title`, not
 * a reason: it is the discount's customer-visible name, and it is what prints on
 * the receipt. That serves D6's requirement better than a hidden reason field
 * would, because the identifier the audit log holds is the one the customer can
 * read back off their receipt.
 *
 * The title the tile must use is built here rather than in the extension, so the
 * receipt wording and the audit trail cannot drift apart.
 */
final class PosCartDiscountGateway implements RedemptionGateway
{
    public function mechanism(): string
    {
        return 'pos_cart_discount';
    }

    /**
     * Nothing to do, deliberately.
     *
     * Present so the tile and the storefront take the same path through
     * RedemptionService. A gateway that had to exist but do nothing is better
     * than a conditional in the service.
     */
    public function publish(LoyaltyAccount $account, Redemption $redemption): void
    {
        // The tile applies the discount itself, from the response it already has.
    }

    public function withdraw(LoyaltyAccount $account, ?Redemption $redemption = null): void
    {
        // Removing the discount from the cart is the tile's to do, on the device
        // that put it there. There is no server-side residue to clean up.
    }

    /**
     * What the till shows and the receipt prints.
     *
     * The reference is in it because D6 asks the receipt and the audit log to
     * share one identifier, and a customer disputing a discount three weeks
     * later has the receipt in their hand and nothing else.
     */
    public static function discountTitle(Redemption $redemption): string
    {
        $pounds = rtrim(rtrim(number_format($redemption->amount_pence / 100, 2, '.', ''), '0'), '.');

        return 'Privilege Club £'.$pounds.' ('.$redemption->reference.')';
    }
}
