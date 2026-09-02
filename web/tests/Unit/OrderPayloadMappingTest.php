<?php

namespace Tests\Unit;

use App\Domain\Orders\AdminApiOrderSource;
use PHPUnit\Framework\TestCase;

/**
 * Shopify's order payload becoming our numbers (M3, D3, C14).
 *
 * **This is the step nothing tested, and it is where the bug was.** Every other
 * fixture in the suite builds an `OrderSnapshot` or a `lines` array by hand, so
 * they all start *after* the mapping and assert arithmetic that was already
 * being fed the wrong figure. On 2 Sep 2026 real order `#1002` earned 600 points
 * on a GBP 600 base when a GBP 50 voucher meant the customer paid 550, and the
 * whole suite was green.
 *
 * The trap, in one sentence: **an order-level discount allocated `ACROSS` does
 * not appear in a line's own `discountedTotalSet` or `totalDiscountSet`** — it
 * appears only in `discountAllocations`. Reading the totals says the discount
 * landed nowhere.
 *
 * Payloads here are shaped exactly like the response to `ORDER_QUERY`, taken
 * from what Shopify actually returned for `#1002`, so a mapping that stops
 * reading a field it needs fails here.
 */
class OrderPayloadMappingTest extends TestCase
{
    /**
     * @param  list<array<string, mixed>>  $allocations
     * @param  list<array<string, mixed>>  $taxLines
     * @return array<string, mixed>
     */
    private function payload(
        string $discountedTotal,
        array $allocations = [],
        array $taxLines = [],
        bool $taxesIncluded = false,
    ): array {
        return [
            'id' => 'gid://shopify/Order/7134863884528',
            'name' => '#1002',
            'processedAt' => '2026-09-02T12:54:20Z',
            'cancelledAt' => null,
            'taxesIncluded' => $taxesIncluded,
            'currencyCode' => 'GBP',
            'customer' => ['id' => 'gid://shopify/Customer/9671675085040'],
            'physicalLocation' => null,
            'discountApplications' => ['nodes' => [
                ['__typename' => 'DiscountCodeApplication', 'code' => 'PC-72347982'],
            ]],
            'lineItems' => ['nodes' => [[
                'id' => 'gid://shopify/LineItem/16858181861616',
                'currentQuantity' => 1,
                // Note this stays at the undiscounted figure even when an
                // order-level discount is allocated against the line. That is
                // Shopify's behaviour, not a contrived fixture.
                'discountedTotalSet' => ['shopMoney' => ['amount' => $discountedTotal, 'currencyCode' => 'GBP']],
                'discountAllocations' => $allocations,
                'taxLines' => $taxLines,
                'product' => [
                    'id' => 'gid://shopify/Product/10172497821936',
                    'tags' => ['Sport'],
                    'collections' => ['nodes' => [['handle' => 'hydrogen']]],
                ],
            ]]],
            'refunds' => [],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function allocation(string $amount): array
    {
        return [['allocatedAmountSet' => ['shopMoney' => ['amount' => $amount]]]];
    }

    public function test_an_order_level_allocation_reduces_the_earn_base(): void
    {
        $snapshot = AdminApiOrderSource::mapOrder(
            7134863884528,
            // Exactly order #1002: a GBP 600 line whose discountedTotalSet is
            // still 600.0, with a GBP 50 voucher allocated ACROSS.
            $this->payload('600.0', $this->allocation('50.0')),
        );

        $this->assertSame(
            55000,
            $snapshot->lines[0]['discounted_total_ex_tax_pence'],
            'A GBP 50 voucher on a GBP 600 line must leave a GBP 550 earn base (C14).',
        );
    }

    /** The regression, stated as the number that was actually wrong. */
    public function test_the_earn_base_is_not_the_undiscounted_line_total(): void
    {
        $snapshot = AdminApiOrderSource::mapOrder(
            7134863884528,
            $this->payload('600.0', $this->allocation('50.0')),
        );

        $this->assertNotSame(
            60000,
            $snapshot->lines[0]['discounted_total_ex_tax_pence'],
            'Reading discountedTotalSet alone is the 2 Sep 2026 defect: 600 points on a GBP 550 spend.',
        );
    }

    public function test_no_allocation_leaves_the_line_total_alone(): void
    {
        $snapshot = AdminApiOrderSource::mapOrder(7134863884528, $this->payload('600.0'));

        $this->assertSame(60000, $snapshot->lines[0]['discounted_total_ex_tax_pence']);
    }

    /** Several allocations on one line sum, rather than the last one winning. */
    public function test_multiple_allocations_all_come_off(): void
    {
        $snapshot = AdminApiOrderSource::mapOrder(7134863884528, $this->payload('600.0', [
            ['allocatedAmountSet' => ['shopMoney' => ['amount' => '50.0']]],
            ['allocatedAmountSet' => ['shopMoney' => ['amount' => '25.0']]],
        ]));

        $this->assertSame(52500, $snapshot->lines[0]['discounted_total_ex_tax_pence']);
    }

    /** An allocation larger than the line floors at zero rather than going negative. */
    public function test_an_over_large_allocation_floors_at_zero(): void
    {
        $snapshot = AdminApiOrderSource::mapOrder(
            7134863884528,
            $this->payload('40.0', $this->allocation('50.0')),
        );

        $this->assertSame(0, $snapshot->lines[0]['discounted_total_ex_tax_pence']);
    }

    /**
     * Tax-inclusive: allocation and tax both come off.
     *
     * **The ordering here is asserted, not proven.** OSC sells VAT-inclusive so
     * this is the branch that ships, and the development store is tax-exclusive
     * with no tax lines on #1002 — so nothing has confirmed against a real shop
     * that Shopify's `taxLines` and `discountAllocations` are on the bases this
     * assumes. V12 in DECISIONS.md carries that, and it is on the go-live
     * checklist. This test locks in current behaviour so a change to it is
     * deliberate; it does not vouch for it.
     */
    public function test_tax_inclusive_takes_off_both_the_allocation_and_the_tax(): void
    {
        $snapshot = AdminApiOrderSource::mapOrder(7134863884528, $this->payload(
            '600.0',
            $this->allocation('50.0'),
            [['priceSet' => ['shopMoney' => ['amount' => '91.67']]]],
            taxesIncluded: true,
        ));

        $this->assertSame(45833, $snapshot->lines[0]['discounted_total_ex_tax_pence']);
    }

    /** The reference still comes off the code, which is what confirms a redemption. */
    public function test_the_discount_code_is_read_as_a_redemption_reference(): void
    {
        $snapshot = AdminApiOrderSource::mapOrder(7134863884528, $this->payload('600.0'));

        $this->assertSame(['PC-72347982'], $snapshot->redemptionReferences);
    }
}
