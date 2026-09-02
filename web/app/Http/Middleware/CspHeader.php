<?php

namespace App\Http\Middleware;

use App\Lib\ShopifyContext;
use Closure;
use Illuminate\Http\Request;
use Shopify\Context;
use Shopify\Utils;

/**
 * Sets the frame-ancestors directive required for the app to be framed by the
 * Shopify admin. See https://shopify.dev/docs/apps/build/security/iframe-protection
 */
class CspHeader
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $shop = Utils::sanitizeShopDomain((string) $request->query('shop', ''));

        if (ShopifyContext::isInitialized() && Context::$IS_EMBEDDED_APP) {
            $domainHost = $shop ? "https://$shop" : 'https://*.myshopify.com';
            $allowed = "$domainHost https://admin.shopify.com";
        } else {
            $allowed = "'none'";
        }

        $response->headers->set('Content-Security-Policy', "frame-ancestors $allowed;");

        return $response;
    }
}
