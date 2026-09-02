<?php

namespace App\Domain\Redemption;

use App\Domain\Rules\RulesVersionRepository;
use App\Models\LoyaltyAccount;
use App\Models\Redemption;
use Illuminate\Support\Facades\Log;

/**
 * Online redemption through a single-use discount code (D5, revised
 * 2 Sep 2026).
 *
 * This is what ships. The live OSC store is on Grow and this is a custom app,
 * and Shopify refuses to activate a function from a custom app below Plus, so
 * `DiscountFunctionGateway` — complete, spiked and proven — stays as the
 * Plus-and-above option rather than the production path.
 *
 * **The exchange, stated plainly.** The function re-applied the D8 ladder to
 * the live cart, so a basket that changed after a quote was re-evaluated. A code
 * cannot: it carries one fixed amount from the moment it is minted.
 * `minimumRequirement.subtotal` covers the minimum-basket rung, but the
 * eligible-subtotal rule is not expressible on a code — a code discounts the
 * order. Harmless under the launch rules, where nothing is excluded, and a real
 * gap the day OSC configures an exclusion. It is recorded rather than hidden
 * because someone will eventually ask why the two channels disagree.
 *
 * **What this gateway does not do.** It does not spend points. `publish()` mints
 * an offer and `withdraw()` retires one; the ledger moves only in
 * `RedemptionService::confirm()` when `orders/paid` arrives. That division is
 * what makes an abandoned basket cost a member nothing, and it is unchanged from
 * the function path.
 *
 * There is deliberately no gift-card guard here. Shopify excludes gift-card
 * lines from a fixed-amount code on its own — proved on paid order `#1001`,
 * 2 Sep 2026, not inferred from documentation, which says nothing either way.
 */
final class DiscountCodeGateway implements RedemptionGateway
{
    public function __construct(
        private readonly DiscountCodeWriter $codes,
        private readonly RulesVersionRepository $rules,
    ) {}

    public function mechanism(): string
    {
        return 'discount_code';
    }

    /**
     * Mint the code for this quote.
     *
     * Idempotent through `shopify_discount_gid`: a retried request finds the
     * code already minted and does nothing, because a doubled discount is a
     * great deal worse than a retried one.
     */
    public function publish(LoyaltyAccount $account, Redemption $redemption): void
    {
        // A member with no Shopify customer record cannot check out online, and
        // the code has to be bound to a customer, so there is nobody to bind it
        // to and nothing lost by saying so quietly.
        if ($account->shopify_customer_id === null) {
            return;
        }

        if ($redemption->shopify_discount_gid !== null) {
            return;
        }

        $rules = $this->rules->current($account->shop_domain);

        $gid = $this->codes->create(
            shopDomain: $account->shop_domain,
            // The reference IS the code. `AdminApiOrderSource` already reads
            // `DiscountCodeApplication.code` back off a paid order and feeds it
            // to RedemptionReference, so `orders/paid` confirms this redemption
            // with no new plumbing.
            code: (string) $redemption->reference,
            amountPence: (int) $redemption->amount_pence,
            currencyCode: $rules->currency(),
            customerGid: 'gid://shopify/Customer/'.$account->shopify_customer_id,
            // Agreed 2 Sep 2026: the code's life is the quote's life, so the
            // expiry sweep and Shopify cannot disagree about whether an offer
            // still stands. A longer window is a rule OSC would have to choose,
            // and inventing one here would put a number nobody agreed into
            // customers' hands.
            endsAt: $redemption->quote_expires_at,
            minimumSubtotalPence: $rules->minBasketPence(),
        );

        if ($gid === null) {
            // The writer has already logged why. Recorded again with the
            // reference because this is the point at which a member is standing
            // in front of a checkout without the discount they were promised.
            Log::warning('A voucher code could not be minted, so the quote has nothing to apply', [
                'shop' => $account->shop_domain,
                'reference' => $redemption->reference,
                'amount_pence' => (int) $redemption->amount_pence,
            ]);

            return;
        }

        $redemption->forceFill(['shopify_discount_gid' => $gid])->save();
    }

    /**
     * Retire the code, so it cannot be applied.
     *
     * Safe on a quote that was never published and on one already withdrawn,
     * which the interface requires and the expiry sweep relies on.
     *
     * A confirmed redemption is left alone. Shopify's own `usageLimit: 1` has
     * already spent the code, and deactivating it afterwards would only remove
     * the record of the discount from a paid order.
     */
    public function withdraw(LoyaltyAccount $account, ?Redemption $redemption = null): void
    {
        if ($redemption === null || $redemption->shopify_discount_gid === null) {
            return;
        }

        if ($redemption->state === 'confirmed') {
            return;
        }

        $this->codes->deactivate($account->shop_domain, (string) $redemption->shopify_discount_gid);
    }
}
