<?php

namespace Tests\Support;

use App\Domain\Orders\OrderSnapshot;
use App\Domain\Orders\OrderSource;

/**
 * Orders without Shopify.
 *
 * The real source re-reads through the Admin API, which needs `read_orders` and
 * a live shop. This stands in its place and also counts its reads, which is how
 * the tests assert that a redelivery never reaches the API at all.
 */
class FakeOrderSource implements OrderSource
{
    /** @var array<int, OrderSnapshot> */
    private array $orders = [];

    public int $reads = 0;

    public function give(OrderSnapshot $order): self
    {
        $this->orders[$order->shopifyOrderId] = $order;

        return $this;
    }

    public function fetch(string $shopDomain, int $shopifyOrderId): ?OrderSnapshot
    {
        $this->reads++;

        return $this->orders[$shopifyOrderId] ?? null;
    }

    /**
     * The worked example, as an order: £90 of shirts less a £10 voucher leaves
     * an £80 eligible base, which earns 80 points at the launch rate.
     */
    public static function workedExample(
        int $orderId = 1042,
        ?int $customerId = 5001,
        array $refunds = [],
        bool $cancelled = false,
        array $redemptionReferences = [],
    ): OrderSnapshot {
        return new OrderSnapshot(
            shopifyOrderId: $orderId,
            orderName: '#'.$orderId,
            shopifyCustomerId: $customerId,
            shopifyLocationId: null,
            lines: [[
                'id' => 'gid://shopify/LineItem/1',
                'discounted_total_ex_tax_pence' => 8000,
                'current_quantity' => 1,
                'tags' => ['shirt'],
                'collections' => ['mens'],
            ]],
            refunds: $refunds,
            currency: 'GBP',
            processedAt: now(),
            cancelled: $cancelled,
            redemptionReferences: $redemptionReferences,
        );
    }

    /** A refund line item against the worked example's only line. */
    public static function refund(int $pence, ?int $refundId = 77001): array
    {
        return ['line_id' => 'gid://shopify/LineItem/1', 'refund_id' => $refundId, 'pence' => $pence];
    }
}
