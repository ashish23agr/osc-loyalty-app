<?php

namespace App\Http\Middleware;

use App\Lib\AuthRedirection;
use App\Lib\ShopifyContext;
use App\Models\Session;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Shopify\Context;
use Shopify\Utils;

/**
 * Checks that the shop in the query string holds a grant this app can still use.
 *
 * Requests that carry no shop at all are passed through: the route decides what
 * to render (normally the React app shell), because we cannot start an OAuth
 * grant without knowing the shop.
 *
 * "Usable" deliberately means the same thing here as it does everywhere else in
 * the app. `ShopifyAdminApi::forShop()` reaches Shopify through
 * `Utils::loadOfflineSession()`, which returns null unless the stored scopes
 * still satisfy `Context::$SCOPES`. This middleware used to ask a weaker
 * question - does a row with an access token exist? - and the two answers came
 * apart the moment the scope list changed:
 *
 *   raw storage loadSession()   FOUND     <- middleware said "installed"
 *   loadOfflineSession()        NULL      <- every Admin API call threw
 *
 * The app then served normally while nothing it did could reach Shopify, and
 * because the middleware never redirected, the one thing that would have fixed
 * it - a fresh grant - was the one thing that could not happen. Asking the same
 * question as the API layer is what keeps the app able to heal itself.
 */
class EnsureShopifyInstalled
{
    public function handle(Request $request, Closure $next)
    {
        if (! ShopifyContext::isInitialized()) {
            return response()->json([
                'message' => 'Shopify credentials are not configured yet. Run `shopify app dev` '
                    .'from the project root, or set SHOPIFY_API_KEY, SHOPIFY_API_SECRET and '
                    .'SHOPIFY_APP_URL in web/.env.',
            ], 503);
        }

        if (preg_match('/^exitiframe/i', $request->path()) === 1) {
            return $next($request);
        }

        $shop = $request->query('shop')
            ? Utils::sanitizeShopDomain((string) $request->query('shop'))
            : null;

        if (! $shop) {
            return $next($request);
        }

        $session = Utils::loadOfflineSession($shop);

        if ($session && $session->getAccessToken()) {
            return $next($request);
        }

        $this->logStaleGrant($shop);

        return AuthRedirection::redirect($request);
    }

    /**
     * A shop with a token that is no longer usable is worth a line in the log.
     *
     * A first install redirecting to OAuth is unremarkable. A shop that *has* a
     * token and is being sent back through consent means the stored grant went
     * stale - almost always a scope change - and that is the case that
     * previously failed in silence, so it should never be silent again.
     */
    private function logStaleGrant(string $shop): void
    {
        $stored = Session::where('shop', $shop)->whereNotNull('access_token')->first();

        if (! $stored) {
            return;
        }

        Log::info("Stored grant for $shop is no longer usable; starting a fresh OAuth grant.", [
            'stored_scope' => $stored->scope,
            'required_scope' => implode(',', Context::$SCOPES->toArray()),
        ]);
    }
}
