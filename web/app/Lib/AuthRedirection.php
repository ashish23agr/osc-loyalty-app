<?php

declare(strict_types=1);

namespace App\Lib;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Shopify\Auth\OAuth;
use Shopify\Context;
use Shopify\Utils;

/**
 * Starts the OAuth grant. When the request comes from inside the admin iframe
 * we bounce out to the top-level window first, because Shopify's OAuth consent
 * screen cannot be framed.
 */
class AuthRedirection
{
    public static function redirect(Request $request, bool $isOnline = false): RedirectResponse
    {
        $shop = Utils::sanitizeShopDomain((string) $request->query('shop'));

        if (Context::$IS_EMBEDDED_APP && $request->query('embedded', false) === '1') {
            return redirect(self::clientSideRedirectUrl($shop, $request->query()));
        }

        return redirect(OAuth::begin(
            $shop,
            '/api/auth/callback',
            $isOnline,
            [CookieHandler::class, 'saveShopifyCookie'],
        ));
    }

    private static function clientSideRedirectUrl(string $shop, array $query): string
    {
        $appUrl = Context::$HOST_SCHEME.'://'.Context::$HOST_NAME;
        $redirectUri = urlencode("$appUrl/api/auth?shop=$shop");
        $queryString = http_build_query(array_merge($query, ['redirectUri' => $redirectUri]));

        return "/exitiframe?$queryString";
    }
}
