<?php

use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\ShopController;
use App\Http\Controllers\ShopifyAuthController;
use App\Http\Controllers\WebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Shopify\Utils;

/*
|--------------------------------------------------------------------------
| Shopify OAuth
|--------------------------------------------------------------------------
*/
Route::get('/api/auth', [ShopifyAuthController::class, 'begin']);
Route::get('/api/auth/callback', [ShopifyAuthController::class, 'callback']);

/*
|--------------------------------------------------------------------------
| Webhooks (HMAC verified inside the controller, no CSRF / no session)
|--------------------------------------------------------------------------
*/
Route::post('/api/webhooks', WebhookController::class);

/*
|--------------------------------------------------------------------------
| App API
|--------------------------------------------------------------------------
*/

// Unauthenticated foundation status check consumed by the React test screen.
Route::get('/api/health', HealthController::class);

// Authenticated Admin GraphQL API call.
Route::get('/api/shop', ShopController::class)->middleware('shopify.auth');

/*
|--------------------------------------------------------------------------
| Frontend
|--------------------------------------------------------------------------
|
| In development the Vite dev server (started by `shopify app dev`) serves the
| React app and proxies /api/* here. In production Vite builds into
| web/public, and this fallback serves the built index.html.
|
*/
Route::fallback(function (Request $request) {
    $index = public_path('index.html');

    if (config('shopify.embedded') && $request->query('embedded') === '1' && file_exists($index)) {
        return response(file_get_contents($index))->header('Content-Type', 'text/html');
    }

    if (file_exists($index) && ! $request->query('shop')) {
        return response(file_get_contents($index))->header('Content-Type', 'text/html');
    }

    // Not embedded yet: send the merchant to the embedded app URL.
    if ($host = $request->query('host')) {
        return redirect(Utils::getEmbeddedAppUrl($host).'/'.$request->path());
    }

    return response()->json([
        'message' => 'Oxford Staging backend is running. Open the app from your Shopify admin.',
    ]);
})->middleware('shopify.installed');
