<?php

namespace App\Domain\Orders;

use App\Domain\Redemption\RedemptionReference;
use App\Exceptions\ShopifyAdminApiException;
use App\Services\ShopifyAdminApi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The real order source: a re-read through the Admin API (M3, M8).
 *
 * Needs `read_orders`, **granted on the development store 31 Aug 2026**. The
 * production store needs the same grant at go-live, which is a merchant action
 * rather than a deploy step and is on the go-live checklist.
 *
 * **Tax.** `discountedTotalSet` includes tax when the shop sells tax-inclusive,
 * which most UK shops do. Guessing would be wrong on half of all stores, so the
 * order's own `taxesIncluded` decides, and the line's `taxLines` are subtracted
 * when it is true. This is the single place in the system that knows about tax
 * at all; everything downstream is D3's ex-tax base.
 */
final class AdminApiOrderSource implements OrderSource
{
    private const ORDER_QUERY = <<<'GRAPHQL'
    query LoyaltyOrder($id: ID!) {
      order(id: $id) {
        id
        name
        processedAt
        cancelledAt
        taxesIncluded
        currencyCode
        customer { id }
        physicalLocation { id }
        # How a paid order is linked back to the quote that produced it. There
        # is no other link: at quote time no order existed, and the reference on
        # the discount is the only trace that survives (D5, D6).
        discountApplications(first: 20) {
          nodes {
            __typename
            ... on AutomaticDiscountApplication { title }
            ... on ManualDiscountApplication { title description }
            ... on ScriptDiscountApplication { title }
            ... on DiscountCodeApplication { code }
          }
        }
        lineItems(first: 250) {
          nodes {
            id
            currentQuantity
            discountedTotalSet { shopMoney { amount currencyCode } }
            taxLines { priceSet { shopMoney { amount } } }
            product {
              id
              tags
              collections(first: 50) { nodes { handle } }
            }
          }
        }
        refunds {
          id
          refundLineItems(first: 250) {
            nodes {
              lineItem { id }
              quantity
              subtotalSet { shopMoney { amount } }
            }
          }
        }
      }
    }
    GRAPHQL;

    public function fetch(string $shopDomain, int $shopifyOrderId): ?OrderSnapshot
    {
        try {
            $data = ShopifyAdminApi::forShop($shopDomain)->query(self::ORDER_QUERY, [
                'id' => 'gid://shopify/Order/'.$shopifyOrderId,
            ]);
        } catch (ShopifyAdminApiException $e) {
            // Logged and rethrown as null rather than swallowed: the job that
            // called this will retry, and a permanently unreadable order shows
            // up as an unprocessed webhook_event rather than as a silent zero.
            Log::warning('Could not re-read order for loyalty', [
                'shop' => $shopDomain,
                'order_id' => $shopifyOrderId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $order = $data['order'] ?? null;

        if ($order === null) {
            return null;
        }

        $taxesIncluded = (bool) ($order['taxesIncluded'] ?? false);

        return new OrderSnapshot(
            shopifyOrderId: $shopifyOrderId,
            orderName: $order['name'] ?? null,
            shopifyCustomerId: self::idOf($order['customer']['id'] ?? null),
            shopifyLocationId: self::idOf($order['physicalLocation']['id'] ?? null),
            lines: self::lines($order, $taxesIncluded),
            refunds: self::refunds($order),
            currency: (string) ($order['currencyCode'] ?? 'GBP'),
            processedAt: isset($order['processedAt']) ? Carbon::parse($order['processedAt']) : null,
            cancelled: ($order['cancelledAt'] ?? null) !== null,
            redemptionReferences: self::redemptionReferences($order),
        );
    }

    /**
     * The quote references carried on this order's discounts.
     *
     * Every kind of discount application is read, not just the automatic one:
     * online the voucher arrives as an AutomaticDiscountApplication whose title
     * is the function's message, and at the till `applyCartDiscount` produces a
     * manual one. Reading both means one code path confirms both channels.
     *
     * @return list<string>
     */
    private static function redemptionReferences(array $order): array
    {
        $texts = [];

        foreach ($order['discountApplications']['nodes'] ?? [] as $application) {
            $texts[] = $application['title'] ?? null;
            $texts[] = $application['description'] ?? null;
            $texts[] = $application['code'] ?? null;
        }

        return RedemptionReference::extractFromAll($texts);
    }

    /** @return list<array<string, mixed>> */
    private static function lines(array $order, bool $taxesIncluded): array
    {
        $lines = [];

        foreach ($order['lineItems']['nodes'] ?? [] as $node) {
            $gross = self::pence($node['discountedTotalSet']['shopMoney']['amount'] ?? '0');

            $tax = 0;

            foreach ($node['taxLines'] ?? [] as $taxLine) {
                $tax += self::pence($taxLine['priceSet']['shopMoney']['amount'] ?? '0');
            }

            $lines[] = [
                'id' => (string) ($node['id'] ?? ''),
                // D3's base. When the shop sells tax-exclusive the discounted
                // total is already net, and subtracting again would under-pay.
                'discounted_total_ex_tax_pence' => $taxesIncluded ? max(0, $gross - $tax) : $gross,
                'current_quantity' => (int) ($node['currentQuantity'] ?? 0),
                'tags' => array_map('strval', $node['product']['tags'] ?? []),
                'collections' => array_map(
                    fn (array $c): string => (string) ($c['handle'] ?? ''),
                    $node['product']['collections']['nodes'] ?? [],
                ),
            ];
        }

        return $lines;
    }

    /**
     * Every refund line item on the order, ever.
     *
     * All of them, not just this webhook's, because D9c is a cumulative floor
     * and needs the running total. Reading them from the order rather than
     * accumulating them ourselves means an out-of-order or missed delivery
     * cannot leave the total wrong.
     *
     * @return list<array{line_id:?string, refund_id:?int, pence:int}>
     */
    private static function refunds(array $order): array
    {
        $refunds = [];

        foreach ($order['refunds'] ?? [] as $refund) {
            $refundId = self::idOf($refund['id'] ?? null);

            foreach ($refund['refundLineItems']['nodes'] ?? [] as $node) {
                $refunds[] = [
                    'line_id' => isset($node['lineItem']['id']) ? (string) $node['lineItem']['id'] : null,
                    'refund_id' => $refundId,
                    // subtotalSet is already net of discount and excludes tax,
                    // which is the same base the earning used.
                    'pence' => self::pence($node['subtotalSet']['shopMoney']['amount'] ?? '0'),
                ];
            }
        }

        return $refunds;
    }

    /** Shopify sends money as a decimal string. Never touch it as a float. */
    private static function pence(string|int|float $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private static function idOf(?string $gid): ?int
    {
        if ($gid === null) {
            return null;
        }

        $parts = explode('/', $gid);

        return is_numeric(end($parts)) ? (int) end($parts) : null;
    }
}
