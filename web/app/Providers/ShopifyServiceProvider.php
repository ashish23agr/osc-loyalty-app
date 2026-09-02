<?php

namespace App\Providers;

use App\Lib\DbSessionStorage;
use App\Lib\DevTunnel;
use App\Lib\Handlers\AppUninstalled;
use App\Lib\Handlers\OrdersCancelled;
use App\Lib\Handlers\OrdersPaid;
use App\Lib\Handlers\Privacy\CustomersDataRequest;
use App\Lib\Handlers\Privacy\CustomersRedact;
use App\Lib\Handlers\Privacy\ShopRedact;
use App\Lib\Handlers\ProductsUpdate;
use App\Lib\Handlers\RefundsCreate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Shopify\Context;
use Shopify\Webhooks\Registry;
use Shopify\Webhooks\Topics;

/**
 * Boots the Shopify API library and registers webhook handlers.
 */
class ShopifyServiceProvider extends ServiceProvider
{
    /**
     * Every topic this app handles, mapped to its handler.
     *
     * The non-privacy topics here must match config('shopify.webhooks.topics')
     * and the [[webhooks.subscriptions]] entries in ../shopify.app.toml.
     */
    public const HANDLERS = [
        Topics::APP_UNINSTALLED => AppUninstalled::class,
        Topics::PRODUCTS_UPDATE => ProductsUpdate::class,
        // The Sprint 2 business topics. orders/updated shares the earning
        // handler because OrderEarningService is cumulative: an edit posts the
        // difference, and a redelivery posts nothing.
        Topics::ORDERS_PAID => OrdersPaid::class,
        Topics::ORDERS_UPDATED => OrdersPaid::class,
        Topics::ORDERS_CANCELLED => OrdersCancelled::class,
        Topics::REFUNDS_CREATE => RefundsCreate::class,
        Topics::CUSTOMERS_DATA_REQUEST => CustomersDataRequest::class,
        Topics::CUSTOMERS_REDACT => CustomersRedact::class,
        Topics::SHOP_REDACT => ShopRedact::class,
    ];

    public function boot(): void
    {
        // Handlers do not depend on the Context, so wire them up unconditionally.
        // WebhookController still refuses to process anything until the app has
        // credentials, because HMAC verification needs the API secret.
        $this->registerWebhookHandlers();

        $this->applyDevTunnel();

        $apiKey = config('shopify.api_key');
        $apiSecret = config('shopify.api_secret');
        $host = config('shopify.host');

        // Skip initialization until the CLI (or .env) supplies credentials, so
        // artisan commands still run on a fresh clone.
        if (! $apiKey || ! $apiSecret || ! $host) {
            Log::warning('Shopify Context not initialized: no API key/secret/host. '
                .'In dev these come from `shopify app dev`; start it and see DevTunnel: '
                .DevTunnel::describe());

            return;
        }

        Context::initialize(
            apiKey: $apiKey,
            apiSecretKey: $apiSecret,
            scopes: config('shopify.scopes'),
            hostName: $host,
            sessionStorage: new DbSessionStorage,
            apiVersion: config('shopify.api_version'),
            isEmbeddedApp: (bool) config('shopify.embedded'),
            isPrivateApp: false,
            logger: Log::channel(),
        );
    }

    /**
     * Fill in what only `shopify app dev` knows, for shells the CLI did not spawn.
     *
     * A plain `php artisan` shell has no HOST, no API key and no secret, so the
     * Context never initializes and anything touching the Admin API - including
     * `shopify:webhooks --register` - fails or, worse, registers callbacks
     * against http://localhost:8000. DevTunnel recovers those values from what
     * the CLI itself wrote; see that class for the two sources.
     *
     * Values already present in this process always win, because when artisan
     * IS running under the CLI the injected environment is by definition
     * current. Local environment only.
     */
    private function applyDevTunnel(): void
    {
        // Dev convenience only. Never in production, and never in tests, where
        // phpunit.xml supplies its own credentials.
        if ($this->app->environment(['production', 'testing'])) {
            return;
        }

        // config('shopify.host') falls back to APP_URL, i.e. localhost, so an
        // unusable value here counts as absent rather than as a real setting.
        if (! DevTunnel::isPublicUrl(config('shopify.host')) && $host = DevTunnel::host()) {
            config(['shopify.host' => $host]);
        }

        if (! config('shopify.api_key') && $key = DevTunnel::apiKey()) {
            config(['shopify.api_key' => $key]);
        }

        if (! config('shopify.api_secret') && $secret = DevTunnel::apiSecret()) {
            config(['shopify.api_secret' => $secret]);
        }

        $this->recordDevTunnel();
    }

    /**
     * If THIS process is the one the CLI spawned, write down what it knows.
     *
     * The CLI-spawned backend runs as `development`, not `local`, and holds the
     * only copy of the API secret. `artisan serve` bootstraps the framework per
     * request, so recording here keeps the handshake alive for as long as the
     * backend is answering - independently of serve.mjs's own lifetime.
     */
    private function recordDevTunnel(): void
    {
        $host = config('shopify.host');
        $apiKey = config('shopify.api_key');
        $apiSecret = config('shopify.api_secret');

        if (! $apiKey || ! $apiSecret || ! DevTunnel::isPublicUrl($host)) {
            return;
        }

        DevTunnel::record($host, $apiKey, $apiSecret, (string) config('shopify.scopes'));
    }

    private function registerWebhookHandlers(): void
    {
        foreach (self::HANDLERS as $topic => $handler) {
            Registry::addHandler($topic, new $handler);
        }
    }
}
