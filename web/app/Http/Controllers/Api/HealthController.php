<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Lib\ShopifyContext;
use App\Models\Session;
use App\Providers\ShopifyServiceProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Shopify\Webhooks\Registry;
use Throwable;

/**
 * Unauthenticated status endpoint used by the React test screen to prove that
 * React -> Laravel communication, MySQL and the Shopify library are all live.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'backend' => [
                'framework' => 'Laravel',
                'laravel_version' => app()->version(),
                'php_version' => PHP_VERSION,
                'environment' => app()->environment(),
            ],
            'database' => $this->database(),
            'webhooks' => $this->webhooks(),
            'shopify' => [
                'library' => 'shopify/shopify-api (PHP)',
                'context_initialized' => ShopifyContext::isInitialized(),
                'api_version' => config('shopify.api_version'),
                'embedded' => (bool) config('shopify.embedded'),
                'scopes' => config('shopify.scopes'),
                'api_key_configured' => (bool) config('shopify.api_key'),
                'app_url' => config('shopify.host'),
            ],
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Which topics are wired up, and whether the library actually holds a
     * handler for each of them.
     */
    private function webhooks(): array
    {
        $topics = [];

        foreach (array_keys(ShopifyServiceProvider::HANDLERS) as $topic) {
            $topics[$topic] = Registry::getHandler($topic) !== null;
        }

        return [
            'endpoint' => config('shopify.webhooks.path'),
            'handlers_registered' => $topics,
            'registered_after_oauth' => config('shopify.webhooks.topics'),
        ];
    }

    private function database(): array
    {
        try {
            $connection = DB::connection();

            return [
                'connected' => true,
                'driver' => $connection->getDriverName(),
                'name' => $connection->getDatabaseName(),
                'server_version' => $connection->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION),
                'shopify_sessions_table' => $connection->getSchemaBuilder()->hasTable('shopify_sessions'),
                'stored_shop_sessions' => Session::count(),
            ];
        } catch (Throwable $e) {
            return ['connected' => false, 'error' => $e->getMessage()];
        }
    }
}
