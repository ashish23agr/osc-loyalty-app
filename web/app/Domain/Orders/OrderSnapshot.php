<?php

namespace App\Domain\Orders;

use DateTimeInterface;

/**
 * One order, normalised into the shape the loyalty arithmetic needs.
 *
 * Deliberately not a Shopify payload. Everything here is integer pence and
 * every line total is **ex tax and after discount**, which is D3's base — the
 * conversion happens once, in the order source, rather than being re-derived by
 * every caller that touches an order.
 *
 * Shipping is absent rather than zeroed, because it never qualifies and a
 * shipping figure sitting on this object would eventually be added to something.
 */
final readonly class OrderSnapshot
{
    /**
     * @param  list<array{id:string, discounted_total_ex_tax_pence:int, current_quantity:int, tags:list<string>, collections:list<string>}>  $lines
     * @param  list<array{line_id:?string, refund_id:?int, pence:int}>  $refunds  Every refund line item on the order, ever.
     * @param  list<string>  $redemptionReferences  Quote references found on the order's discounts.
     */
    public function __construct(
        public int $shopifyOrderId,
        public ?string $orderName,
        public ?int $shopifyCustomerId,
        public ?int $shopifyLocationId,
        public array $lines,
        public array $refunds = [],
        public string $currency = 'GBP',
        public ?DateTimeInterface $processedAt = null,
        public bool $cancelled = false,
        public array $redemptionReferences = [],
    ) {}

    /**
     * The cumulative refunded value limited to the lines that actually earn.
     *
     * This is the numerator D9c needs, and getting it wrong is expensive in both
     * directions: counting a refund on an excluded line would reverse points the
     * member earned elsewhere, and ignoring the line map entirely would make a
     * refund of a gift card claw back points from a shirt.
     *
     * A refund line item with no line id — a shipping refund, or an order-level
     * adjustment — counts for nothing here, which is right: shipping never
     * earned anything, so refunding it cannot reverse anything.
     *
     * @param  list<string>  $qualifyingLineIds
     */
    public function refundedQualifyingPence(array $qualifyingLineIds): int
    {
        $qualifying = array_flip($qualifyingLineIds);
        $total = 0;

        foreach ($this->refunds as $refund) {
            if ($refund['line_id'] !== null && isset($qualifying[$refund['line_id']])) {
                $total += (int) $refund['pence'];
            }
        }

        return $total;
    }

    /** A cancellation refunds everything, whatever the refund records say. */
    public function isFullyCancelled(): bool
    {
        return $this->cancelled;
    }
}
