<?php

namespace App\Http\Middleware;

use App\Lib\AuthRedirection;
use App\Lib\TopLevelRedirection;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Shopify\Auth\Session as ShopifySession;
use Shopify\Context;
use Shopify\Utils;
use Throwable;

/**
 * Guarantees the request carries a valid Shopify session before it reaches the
 * route. The resolved session is attached as the `shopifySession` attribute.
 *
 * Usage: ->middleware('shopify.auth')  or  ->middleware('shopify.auth:online')
 */
class EnsureShopifySession
{
    public const ACCESS_MODE_ONLINE = 'online';

    public const ACCESS_MODE_OFFLINE = 'offline';

    public function handle(Request $request, Closure $next, string $accessMode = self::ACCESS_MODE_OFFLINE)
    {
        if (! in_array($accessMode, [self::ACCESS_MODE_ONLINE, self::ACCESS_MODE_OFFLINE], true)) {
            throw new InvalidArgumentException("Unrecognized access mode '$accessMode'.");
        }

        $isOnline = $accessMode === self::ACCESS_MODE_ONLINE;

        $shop = $request->query('shop')
            ? Utils::sanitizeShopDomain((string) $request->query('shop'))
            : null;

        $session = $this->loadSession($request, $isOnline);

        if ($session && $shop && $session->getShop() !== $shop) {
            // The stored session belongs to a different shop: start over.
            return AuthRedirection::redirect($request);
        }

        if ($session && $session->isValid()) {
            $request->attributes->set('shopifySession', $session);

            return $next($request);
        }

        $targetShop = $shop ?? $session?->getShop() ?? $this->shopFromSessionToken($request);

        // Without a shop there is nothing to reauthorize against: sending the
        // client to /api/auth?shop= would only produce a 400.
        if (! $targetShop) {
            return response()->json([
                'message' => 'No Shopify session for this request. Open the app from the Shopify admin '
                    .'so App Bridge can attach a session token, or pass ?shop=<store>.myshopify.com.',
            ], 403);
        }

        return TopLevelRedirection::redirect($request, '/api/auth?shop='.$targetShop);
    }

    /**
     * The library throws when the session token / cookie is absent or invalid.
     * For this middleware that simply means "not authenticated yet".
     */
    private function loadSession(Request $request, bool $isOnline): ?ShopifySession
    {
        try {
            return Utils::loadCurrentSession($request->header(), $request->cookie(), $isOnline);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Recovers the shop domain from the App Bridge session token when it is not
     * in the query string.
     */
    private function shopFromSessionToken(Request $request): ?string
    {
        if (! Context::$IS_EMBEDDED_APP) {
            return null;
        }

        if (! preg_match('/Bearer\s+(.+)/i', (string) $request->header('Authorization'), $matches)) {
            return null;
        }

        try {
            $payload = Utils::decodeSessionToken($matches[1]);

            return parse_url($payload['dest'], PHP_URL_HOST) ?: null;
        } catch (Throwable) {
            return null;
        }
    }
}
