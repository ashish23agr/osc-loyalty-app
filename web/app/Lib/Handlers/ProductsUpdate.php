<?php

declare(strict_types=1);

namespace App\Lib\Handlers;

use Illuminate\Support\Facades\Log;
use Shopify\Webhooks\Handler;

/**
 * products/update.
 *
 * A minimal, real (non-GDPR) business webhook, used to verify the whole
 * delivery path: Shopify -> POST /api/webhooks -> HMAC check in
 * Registry::process() -> this handler.
 *
 * It only logs for now. The subscription is declared in ../shopify.app.toml and
 * re-registered per shop after OAuth by ShopifyAuthController.
 */
class ProductsUpdate implements Handler
{
    public function handle(string $topic, string $shop, array $body): void
    {
        Log::info("Webhook $topic received for $shop", [
            'product_id' => $body['id'] ?? null,
            'title' => $body['title'] ?? null,
            'status' => $body['status'] ?? null,
            'updated_at' => $body['updated_at'] ?? null,
            'variants' => is_array($body['variants'] ?? null) ? count($body['variants']) : 0,
        ]);
    }
}
