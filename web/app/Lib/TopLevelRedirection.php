<?php

declare(strict_types=1);

namespace App\Lib;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Escapes the admin iframe.
 *
 * For XHR/fetch calls we return the documented reauthorization headers so the
 * React app can redirect the top-level window with App Bridge. For plain
 * document requests we issue a normal redirect.
 */
class TopLevelRedirection
{
    public static function redirect(Request $request, string $url): Response
    {
        if ($request->expectsJson() || $request->ajax() || str_starts_with($request->path(), 'api/')) {
            return response('', 403)
                ->header('X-Shopify-API-Request-Failure-Reauthorize', '1')
                ->header('X-Shopify-API-Request-Failure-Reauthorize-Url', $url);
        }

        return redirect($url);
    }
}
