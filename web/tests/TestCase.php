<?php

namespace Tests;

use App\Domain\Shop\ShopCurrency;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
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
    }
}
