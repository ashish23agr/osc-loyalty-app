<?php

use App\Http\Middleware\CspHeader;
use App\Http\Middleware\EnsureShopifyInstalled;
use App\Http\Middleware\EnsureShopifySession;
use App\Http\Middleware\EnsureStaffRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Registered outside the web group on purpose. The console calls
            // these with an App Bridge session token in the Authorization
            // header, so there is no cookie session to protect and no CSRF
            // token to check; the staff.role middleware on each route is what
            // establishes who is calling.
            Route::prefix('api/admin')->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The Shopify CLI tunnel (and any production load balancer) terminates
        // TLS in front of Laravel, so trust its forwarding headers.
        $middleware->trustProxies(at: '*');

        // Every response may be framed by the Shopify admin.
        $middleware->append(CspHeader::class);

        // Shopify signs webhooks with an HMAC instead of a CSRF token.
        $middleware->validateCsrfTokens(except: [
            'api/webhooks',
        ]);

        $middleware->alias([
            'shopify.auth' => EnsureShopifySession::class,
            'shopify.installed' => EnsureShopifyInstalled::class,
            // Role floor for the admin console, e.g. staff.role:agent.
            // Authorisation is enforced server-side on every endpoint.
            'staff.role' => EnsureStaffRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // One error envelope for the whole admin API, per plan section 5.2:
        // { error: { code, message, details } }. The console branches on the
        // code, so Laravel's default validation and 404 bodies are translated
        // rather than left as a second shape the client would have to handle.
        //
        // ApiException renders itself and needs nothing here.

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/admin/*')) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'Some of what was sent could not be accepted.',
                    'details' => $e->errors(),
                ],
            ], 422);
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/admin/*')) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'That endpoint or record does not exist on this shop.',
                ],
            ], 404);
        });
    })->create();
