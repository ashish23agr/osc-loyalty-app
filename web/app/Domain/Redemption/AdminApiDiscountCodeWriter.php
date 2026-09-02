<?php

namespace App\Domain\Redemption;

use App\Exceptions\ShopifyAdminApiException;
use App\Services\ShopifyAdminApi;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;

/**
 * The real code writer: `discountCodeBasicCreate` and `discountCodeDeactivate`.
 *
 * Needs `write_discounts` and `read_discounts`, both granted on the development
 * store. Works on every Shopify plan, which is the entire reason this exists —
 * `discountAutomaticAppCreate` refuses a function from a custom app below Plus,
 * and the live OSC store is on Grow.
 *
 * **`context`, not `customerSelection`.** `customerSelection` is deprecated on
 * 2026-07 and `DiscountContextInput` replaces it. Every older example on the
 * internet still shows the old field, so this is worth not rediscovering.
 *
 * **What Shopify enforces for us, and one thing that was proved rather than
 * assumed.** The customer binding, the single use, the fixed amount, the expiry
 * and the minimum subtotal are all native and are not re-checked in the app.
 * Gift cards are native too: on 2 Sep 2026 a paid test order (`#1001`) with a
 * gift card and an ordinary line showed the whole discount allocated to the
 * ordinary line and an empty `discountAllocations` on the gift-card line, even
 * with `customerGets.items {all: true}`. So no app-level gift-card guard is
 * written here. The documentation is silent on it; the order is not.
 */
final class AdminApiDiscountCodeWriter implements DiscountCodeWriter
{
    private const CREATE = <<<'GRAPHQL'
    mutation CreateLoyaltyVoucherCode($input: DiscountCodeBasicInput!) {
      discountCodeBasicCreate(basicCodeDiscount: $input) {
        codeDiscountNode { id }
        userErrors { field code message }
      }
    }
    GRAPHQL;

    private const DEACTIVATE = <<<'GRAPHQL'
    mutation DeactivateLoyaltyVoucherCode($id: ID!) {
      discountCodeDeactivate(id: $id) {
        codeDiscountNode { id }
        userErrors { field code message }
      }
    }
    GRAPHQL;

    public function create(
        string $shopDomain,
        string $code,
        int $amountPence,
        string $currencyCode,
        string $customerGid,
        DateTimeInterface $endsAt,
        int $minimumSubtotalPence,
    ): ?string {
        $input = [
            // The reference is in the title as well as being the code, because
            // the title is what a merchant reads in the admin discount list and
            // what some receipt templates print.
            'title' => 'Privilege Club voucher '.$code,
            'code' => $code,
            'startsAt' => now()->toIso8601String(),
            // The quote's own expiry, so the code cannot outlive the offer it
            // represents. A code that stood longer than its quote would let a
            // member spend points the expiry sweep had already released.
            'endsAt' => $endsAt->format(DATE_ATOM),
            // Bound to one customer. The reference is printed on receipts and
            // read aloud across a counter, so it is not a secret and the
            // binding is what stops someone else spending it.
            'context' => ['customers' => ['add' => [$customerGid]]],
            'customerGets' => [
                'value' => [
                    'discountAmount' => [
                        'amount' => self::money($amountPence),
                        // Off the order, not off each item.
                        'appliesOnEachItem' => false,
                    ],
                ],
                'items' => ['all' => true],
            ],
            // Single use, both ways over. Shopify enforcing this is what makes
            // deducting points at `orders/paid` safe: the code itself is the
            // reservation, so a member cannot spend one quote twice.
            'usageLimit' => 1,
            'appliesOncePerCustomer' => true,
            // The per-order cap is enforced by the amount minted, and there is
            // no native cap, so two of our codes on one order would exceed it.
            // Refusing to combine is how the cap survives contact with a real
            // basket.
            'combinesWith' => [
                'orderDiscounts' => false,
                'productDiscounts' => true,
                'shippingDiscounts' => true,
            ],
        ];

        if ($minimumSubtotalPence > 0) {
            $input['minimumRequirement'] = [
                'subtotal' => ['greaterThanOrEqualToSubtotal' => self::money($minimumSubtotalPence)],
            ];
        }

        try {
            $data = ShopifyAdminApi::forShop($shopDomain)->query(self::CREATE, ['input' => $input]);
        } catch (ShopifyAdminApiException $e) {
            return $this->failed('create', $shopDomain, $code, $e->getMessage());
        }

        $errors = $data['discountCodeBasicCreate']['userErrors'] ?? [];

        if ($errors !== []) {
            return $this->failed('create', $shopDomain, $code, json_encode($errors));
        }

        $gid = $data['discountCodeBasicCreate']['codeDiscountNode']['id'] ?? null;

        return is_string($gid) && $gid !== '' ? $gid : $this->failed(
            'read back the node id for',
            $shopDomain,
            $code,
            'the mutation reported no errors and no node',
        );
    }

    public function deactivate(string $shopDomain, string $discountNodeGid): bool
    {
        try {
            $data = ShopifyAdminApi::forShop($shopDomain)->query(self::DEACTIVATE, ['id' => $discountNodeGid]);
        } catch (ShopifyAdminApiException $e) {
            return $this->failed('deactivate', $shopDomain, $discountNodeGid, $e->getMessage()) !== null;
        }

        $errors = $data['discountCodeDeactivate']['userErrors'] ?? [];

        if ($errors === []) {
            return true;
        }

        $this->failed('deactivate', $shopDomain, $discountNodeGid, json_encode($errors));

        return false;
    }

    /** Pence to the decimal string Shopify wants, with no locale in the way. */
    private static function money(int $pence): string
    {
        return number_format($pence / 100, 2, '.', '');
    }

    /**
     * Logged and reported as null, never thrown — the same contract
     * `AdminApiMetafieldWriter` keeps, for the same reason.
     *
     * A failed mint means the member is not offered their voucher, which is a
     * disappointment. Throwing mid-quote would leave a redemption row that
     * nothing will ever apply or clean up, which is worse. The gateway decides
     * what to do about null.
     */
    private function failed(string $what, string $shop, string $subject, ?string $error): ?string
    {
        Log::warning('Could not '.$what.' a loyalty discount code', [
            'shop' => $shop,
            'subject' => $subject,
            'error' => $error,
        ]);

        return null;
    }
}
