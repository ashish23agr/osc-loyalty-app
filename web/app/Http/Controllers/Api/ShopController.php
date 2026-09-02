<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ShopifyAdminApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/shop
 *
 * The Admin GraphQL test endpoint: it proves the stored offline access token
 * works end to end (React -> Laravel -> Shopify). Failures bubble up as a
 * ShopifyAdminApiException, which renders itself as JSON.
 */
class ShopController extends Controller
{
    private const SHOP_QUERY = <<<'GRAPHQL'
    query ShopFoundationCheck {
      shop {
        id
        name
        myshopifyDomain
        email
        contactEmail
        currencyCode
        ianaTimezone
        plan { publicDisplayName partnerDevelopment }
      }
    }
    GRAPHQL;

    public function __invoke(Request $request): JsonResponse
    {
        $api = ShopifyAdminApi::forRequest($request);

        $data = $api->query(self::SHOP_QUERY);

        return response()->json([
            'ok' => true,
            'api_version' => config('shopify.api_version'),
            'shop' => $data['shop'] ?? null,
            'query_cost' => $api->lastQueryCost(),
        ]);
    }
}
