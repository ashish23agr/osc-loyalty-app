<?php

namespace Tests\Support;

use App\Domain\Shop\ShopCurrency;

/**
 * A shop currency without a shop.
 *
 * Records every shop it was asked about, in order, because "read live rather
 * than cached" is a property of the V13 guard rather than an implementation
 * detail — a test asserts the reader is consulted again on a second mint, and
 * that assertion needs the call log to be real.
 */
class FakeShopCurrency implements ShopCurrency
{
    /** @var list<string> Every shop domain passed to for(), in order. */
    public array $asked = [];

    public function __construct(private ?string $code = 'GBP') {}

    /** Null makes the next read fail the way a rate limit or a dead token would. */
    public function set(?string $code): void
    {
        $this->code = $code;
    }

    public function for(string $shopDomain): ?string
    {
        $this->asked[] = $shopDomain;

        return $this->code;
    }
}
