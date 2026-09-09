<?php

namespace Tests;

use App\Domain\Redemption\MetafieldWriter;
use App\Domain\Shop\ShopCurrency;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FakeMetafieldWriter;
use Tests\Support\FakeShopCurrency;

abstract class TestCase extends BaseTestCase
{
    /**
     * The shop currency every test gets unless it says otherwise.
     *
     * Bound here rather than in each test file for one reason: the V13 guard
     * sits on the redemption path, so without a default every existing test
     * that holds a quote would have to be edited to say something it does not
     * care about, and a test edited to satisfy a guard is a test that no longer
     * says what it was written to say.
     *
     * GBP because that is what `RuleSet::defaults()` uses. A test that wants a
     * mismatch rebinds this with its own FakeShopCurrency.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ShopCurrency::class, new FakeShopCurrency('GBP'));

        // The metafield writer, for exactly the reason above and one more.
        //
        // M4 publishes the balance from BalanceCalculator::refreshCache(), which
        // every ledger posting passes through, and the test queue is `sync` - so
        // without a default binding every test that moves a point would attempt
        // a live Admin API call. A test that needs a real shop to assert
        // arithmetic is not a test of arithmetic.
        //
        // A test that cares about what was published replaces this with its own
        // instance and reads it back.
        $this->app->instance(MetafieldWriter::class, new FakeMetafieldWriter);
    }
}
