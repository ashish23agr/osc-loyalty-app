<?php

declare(strict_types=1);

namespace App\Lib;

use Shopify\Context;
use Shopify\Exception\UninitializedContextException;

/**
 * Small helper around Shopify\Context.
 *
 * The library keeps its $IS_INITIALIZED flag private and only exposes
 * throwIfUninitialized(), so we wrap that in a boolean check.
 */
class ShopifyContext
{
    public static function isInitialized(): bool
    {
        try {
            Context::throwIfUninitialized();

            return true;
        } catch (UninitializedContextException) {
            return false;
        }
    }
}
