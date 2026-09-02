<?php

use Shopify\Webhooks\Topics;

return [
    /*
    |--------------------------------------------------------------------------
    | Shopify app credentials
    |--------------------------------------------------------------------------
    |
    | SHOPIFY_API_KEY / SHOPIFY_API_SECRET are injected automatically by
    | `shopify app dev`. SHOPIFY_APP_URL is the tunnel / production URL.
    |
    */
    'api_key' => env('SHOPIFY_API_KEY', ''),
    'api_secret' => env('SHOPIFY_API_SECRET', ''),

    // Comma separated list. Must match [access_scopes] in ../shopify.app.toml
    'scopes' => env('SCOPES', env('SHOPIFY_SCOPES', 'read_products')),

    // Host the app is reachable at.
    //
    // `shopify app dev` injects HOST and APP_URL (it does NOT set
    // SHOPIFY_APP_URL). We use ?: rather than env() defaults so that a blank
    // value in .env falls through instead of shadowing the injected one.
    'host' => env('SHOPIFY_APP_URL')
        ?: env('HOST')
        ?: env('APP_URL')
        ?: 'http://localhost:8000',

    /*
    |--------------------------------------------------------------------------
    | Admin API version
    |--------------------------------------------------------------------------
    |
    | Shopify ships a new stable version each quarter. Keep in sync with
    | [webhooks] api_version in ../shopify.app.toml.
    |
    */
    'api_version' => env('SHOPIFY_API_VERSION', '2026-07'),

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | `path` is the single endpoint every topic is delivered to (see
    | routes/web.php).
    |
    | `topics` are registered per shop through the Admin API right after OAuth,
    | and this list is where EVERY business topic belongs.
    |
    | Declaring subscriptions in ../shopify.app.toml is not an option for this
    | app. `shopify app deploy` rejects the whole app version with:
    |
    |     App-specific webhook subscriptions are not supported when
    |     use_legacy_install_flow is enabled.
    |
    | `shopify app dev` is more permissive and resolves declared topics against
    | the tunnel, which makes the problem easy to miss until deploy time. Since
    | `use_legacy_install_flow = true` is a hard constraint here (the PHP library
    | has no token exchange), shop-specific registration is the only mechanism
    | available.
    |
    | The mandatory GDPR topics are the exception: they live in
    | [webhooks.privacy_compliance] in the toml, they deploy fine, and they
    | cannot be created through the Admin API at all.
    |
    */
    /*
    |--------------------------------------------------------------------------
    | Dev tunnel handshake
    |--------------------------------------------------------------------------
    |
    | Where serve.mjs and ShopifyServiceProvider record what `shopify app dev`
    | injected, so a plain `php artisan` shell can reach the Admin API. See
    | App\Lib\DevTunnel. Overridable so tests never touch the real file.
    |
    */
    /*
    |--------------------------------------------------------------------------
    | OAuth state cookie lifetime, in minutes
    |--------------------------------------------------------------------------
    |
    | shopify-api-php hard-codes `strtotime('+1 minute')` for the state cookie in
    | OAuth::begin(). One minute is the time the merchant has to read the consent
    | screen and click accept - and a merchant reading a scope list carefully is
    | the normal case, not the slow one. Past it, the callback arrives with no
    | cookie and the library throws CookieNotFoundException, leaving the stored
    | grant untouched and the merchant looking at a working app.
    |
    | Ten minutes is the usual lifetime for an OAuth state parameter. It does not
    | weaken the CSRF protection: the state is still random per attempt and still
    | has to match the value Shopify echoes back.
    |
    */
    'oauth_cookie_minutes' => (int) env('SHOPIFY_OAUTH_COOKIE_MINUTES', 10),

    'dev_tunnel_file' => env('DEV_TUNNEL_FILE') ?: storage_path('app/dev-tunnel.json'),

    'webhooks' => [
        'path' => '/api/webhooks',
        'topics' => [
            Topics::APP_UNINSTALLED,
            Topics::PRODUCTS_UPDATE,
            // Sprint 2. orders/paid earns, orders/updated recalculates after an
            // edit, and the two reversal topics drive M8.
            Topics::ORDERS_PAID,
            Topics::ORDERS_UPDATED,
            Topics::ORDERS_CANCELLED,
            Topics::REFUNDS_CREATE,
        ],
    ],

    'embedded' => (bool) env('SHOPIFY_EMBEDDED', true),
];
