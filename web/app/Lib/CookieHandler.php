<?php

declare(strict_types=1);

namespace App\Lib;

use Illuminate\Support\Facades\Cookie;
use Shopify\Auth\OAuthCookie;
use Shopify\Context;

/**
 * Bridges the library's OAuth state cookie onto Laravel's cookie queue.
 */
class CookieHandler
{
    public static function saveShopifyCookie(OAuthCookie $cookie): bool
    {
        Cookie::queue(
            $cookie->getName(),
            $cookie->getValue(),
            self::lifetimeInMinutes($cookie),
            '/',
            parse_url(Context::$HOST_SCHEME.'://'.Context::$HOST_NAME, PHP_URL_HOST),
            $cookie->isSecure(),
            $cookie->isHttpOnly(),
            false,
            'Lax'
        );

        return true;
    }

    /**
     * How long the cookie actually lives, overriding the library's 60 seconds.
     *
     * `OAuth::begin()` passes `strtotime('+1 minute')`, so the merchant has one
     * minute to read the consent screen and accept. Longer than that and the
     * callback arrives without the state cookie: the library throws
     * CookieNotFoundException, nothing is stored, and the merchant is redirected
     * into a working-looking app having granted nothing. Writing the cookie is
     * ours to do, so the lifetime is ours to set.
     *
     * An expiry of 0 means a session cookie and is passed through untouched.
     */
    private static function lifetimeInMinutes(OAuthCookie $cookie): int
    {
        if (! $cookie->getExpire()) {
            return 0;
        }

        $fromLibrary = (int) ceil(($cookie->getExpire() - time()) / 60);

        return max($fromLibrary, (int) config('shopify.oauth_cookie_minutes', 10));
    }
}
