<?php

namespace App\Domain\Orders;

/**
 * Where an order's authoritative figures come from.
 *
 * M3 and M8 both say the same thing and it is worth stating once: **the webhook
 * payload is a trigger, not the arithmetic input.** A payload can be stale, can
 * arrive out of order, and does not carry the discount allocation or the current
 * quantities after an edit. Every figure the ledger acts on is re-read.
 *
 * An interface because the replay command and the test suite need to supply
 * orders from somewhere other than a live shop. `read_orders` is granted on the
 * development store, so the real implementation is the one that runs.
 */
interface OrderSource
{
    /**
     * @return OrderSnapshot|null Null when the order cannot be read at all.
     */
    public function fetch(string $shopDomain, int $shopifyOrderId): ?OrderSnapshot;
}
