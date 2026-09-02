<?php

namespace App\Http\Controllers;

use App\Lib\AuthRedirection;
use App\Lib\CookieHandler;
use App\Models\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Shopify\Auth\OAuth;
use Shopify\Utils;
use Shopify\Webhooks\Registry;
use Throwable;

/**
 * Classic (app-managed) Shopify OAuth flow.
 */
class ShopifyAuthController extends Controller
{
    /** GET /api/auth - kick off the grant. */
    public function begin(Request $request)
    {
        if (! $request->query('shop')) {
            return response()->json([
                'message' => 'Missing required "shop" query parameter.',
            ], 400);
        }

        $shop = Utils::sanitizeShopDomain((string) $request->query('shop'));

        // Drop half-finished OAuth attempts for this shop.
        Session::where('shop', $shop)->whereNull('access_token')->delete();

        // A grant is a security event and the hinge every install turns on.
        // Logging both ends is what makes "the merchant said they accepted"
        // a checkable claim rather than something to take on trust.
        Log::info("OAuth begin for $shop", [
            'embedded' => $request->query('embedded'),
            'callback_url' => config('shopify.host').'/api/auth/callback',
            // Echoed back so a support round trip can tell "the merchant's
            // browser reached us" apart from "someone probed the endpoint".
            'probe' => $request->query('probe'),
            'user_agent' => substr((string) $request->userAgent(), 0, 120),
        ]);

        // An authorization request is single-use: it carries a state that is
        // valid once. A browser replaying it from cache or history sends the
        // merchant to a stale consent screen whose accept goes nowhere, and
        // nothing reaches this app to say so.
        return AuthRedirection::redirect($request)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    /** GET /api/auth/callback - exchange the code for an access token. */
    public function callback(Request $request)
    {
        Log::info('OAuth callback received', [
            'shop' => $request->query('shop'),
            'has_code' => $request->query('code') !== null,
        ]);

        try {
            $session = OAuth::callback(
                $request->cookie(),
                $request->query(),
                [CookieHandler::class, 'saveShopifyCookie'],
            );
        } catch (Throwable $e) {
            // The library throws for a missing state cookie, a bad HMAC and a
            // rejected code exchange alike. Any of them leaves the stored grant
            // untouched, which is indistinguishable from "nothing happened"
            // unless it is said out loud.
            Log::error('OAuth callback failed; the stored grant is unchanged.', [
                'shop' => $request->query('shop'),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }

        Log::info('OAuth granted', [
            'shop' => $session->getShop(),
            'scope' => (string) $session->getScope(),
        ]);

        $this->registerWebhooks($session->getShop(), $session->getAccessToken());

        return redirect(Utils::getEmbeddedAppUrl($request->query('host')));
    }

    /**
     * Register the webhook topics that are NOT declared in shopify.app.toml.
     *
     * Declared topics are owned by the app version and are invisible to the
     * Admin API, so registering them here would create a duplicate subscription
     * and Shopify would deliver every event twice. config/shopify.php explains
     * why that list is normally empty.
     */
    private function registerWebhooks(string $shop, string $accessToken): void
    {
        $path = config('shopify.webhooks.path', '/api/webhooks');
        $topics = (array) config('shopify.webhooks.topics', []);

        if ($topics === []) {
            Log::info("No Admin API webhook topics to register for $shop; all topics are declared in shopify.app.toml.");

            return;
        }

        foreach ($topics as $topic) {
            try {
                $response = Registry::register($path, $topic, $shop, $accessToken);

                if ($response->isSuccess()) {
                    Log::info("Registered $topic webhook for $shop");
                } else {
                    Log::error("Failed to register $topic webhook for $shop: "
                        .print_r($response->getBody(), true));
                }
            } catch (Throwable $e) {
                Log::error("Webhook registration for $topic threw for $shop: {$e->getMessage()}");
            }
        }
    }
}
