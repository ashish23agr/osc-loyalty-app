<?php

namespace App\Domain\Redemption;

use App\Domain\Rules\RulesVersionRepository;
use App\Models\LoyaltyAccount;
use App\Models\Redemption;

/**
 * Online redemption through the discount function (D5, M6, V4).
 *
 * The function cannot call back into the app at cart time, so everything it
 * needs has to be sitting somewhere it can read. V4 established what that is:
 *
 *   - a **customer metafield in the app-reserved namespace**, which only this
 *     app can write, carrying the balance and the rule values. Trustworthy.
 *   - a **cart attribute**, which anyone holding the cart can write, carrying
 *     what the customer asked for. A request, never an entitlement.
 *
 * That split is the whole security of online redemption. If the amount came
 * from the cart attribute, a customer could set it to the value of their basket
 * and pay nothing. The function therefore takes the balance from here and lets
 * the attribute only ask for less.
 *
 * The rule values travel with the balance rather than being read separately,
 * because the function has to re-apply the ladder against the live cart — the
 * cart can change after a quote — and it has no other way to know what the cap
 * or the increment are.
 */
final class DiscountFunctionGateway implements RedemptionGateway
{
    /**
     * The app-reserved namespace is used when none is given, which is exactly
     * what makes this unwritable by anyone else. The key is the whole contract
     * with `src/cart_lines_discounts_generate_run.graphql`.
     */
    public const METAFIELD_KEY = 'voucher';

    public function __construct(
        private readonly MetafieldWriter $metafields,
        private readonly RulesVersionRepository $rules,
    ) {}

    public function mechanism(): string
    {
        return 'function';
    }

    public function publish(LoyaltyAccount $account, Redemption $redemption): void
    {
        // A member with no Shopify customer record cannot check out online, so
        // there is nowhere to publish to and nothing lost by saying so quietly.
        if ($account->shopify_customer_id === null) {
            return;
        }

        $rules = $this->rules->current($account->shop_domain);

        $this->metafields->write(
            $account->shop_domain,
            'gid://shopify/Customer/'.$account->shopify_customer_id,
            self::METAFIELD_KEY,
            [
                // What the function is allowed to give away. The quote, not the
                // whole balance: a quote is what the member asked for and what
                // the ladder allowed against the basket they had.
                'voucher_balance_pence' => (int) $redemption->amount_pence,
                // The rule values in force, because the function re-applies the
                // ladder against the live cart and cannot ask us for them.
                'voucher_value_pence' => $rules->voucherValuePence(),
                'max_redemption_per_order_pence' => $rules->maxRedemptionPerOrderPence(),
                'min_basket_pence' => $rules->minBasketPence(),
                // Carried onto the discount message, so the order, the ledger
                // entry and the audit log share one identifier (D6 does the
                // same at the till).
                'reference' => (string) $redemption->reference,
                'account_id' => (int) $account->id,
                'rules_version_id' => $rules->versionId,
                'quote_expires_at' => $redemption->quote_expires_at?->toIso8601String(),
            ],
        );
    }

    public function withdraw(LoyaltyAccount $account, ?Redemption $redemption = null): void
    {
        if ($account->shopify_customer_id === null) {
            return;
        }

        // Cleared rather than zeroed. A zero balance would still be a published
        // entitlement that the function has to reason about; an absent metafield
        // is unambiguous, and the function already treats it as "no member".
        $this->metafields->clear(
            $account->shop_domain,
            'gid://shopify/Customer/'.$account->shopify_customer_id,
            self::METAFIELD_KEY,
        );
    }
}
