<?php

namespace App\Http\Controllers;

use App\Lib\ShopifyContext;
use App\Support\Webhooks\WebhookDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Shopify\Clients\HttpHeaders;
use Shopify\Exception\InvalidWebhookException;
use Shopify\Webhooks\Registry;
use Throwable;

/**
 * Single entry point for every Shopify webhook. HMAC verification is done by
 * Registry::process(); handlers are wired up in ShopifyServiceProvider.
 */
class WebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $topic = $request->header(HttpHeaders::X_SHOPIFY_TOPIC, 'unknown');

        // Without credentials there is no API secret to verify the HMAC with,
        // and the library would fail with a TypeError deep inside hash_hmac().
        if (! ShopifyContext::isInitialized()) {
            Log::error("Cannot process '$topic' webhook: Shopify credentials are not configured.");

            return response()->json(['message' => 'Shopify credentials are not configured'], 503);
        }

        // Shopify's library hands a Handler only (topic, shop, body), so the
        // headers that identify a delivery - above all X-Shopify-Webhook-Id -
        // are gone by the time one runs. Captured here, read from the container
        // by the handlers, and used as the outer idempotency guard.
        app(WebhookDelivery::class)->capture(
            webhookId: $request->header('X-Shopify-Webhook-Id'),
            topic: $topic === 'unknown' ? null : $topic,
            shopDomain: $request->header(HttpHeaders::X_SHOPIFY_DOMAIN),
            triggeredAt: $request->header('X-Shopify-Triggered-At'),
            rawBody: $request->getContent(),
        );

        try {
            $response = Registry::process($request->header(), $request->getContent());

            if (! $response->isSuccess()) {
                Log::error("Failed to process '$topic' webhook: {$response->getErrorMessage()}");

                return response()->json(['message' => "Failed to process '$topic' webhook"], 500);
            }
        } catch (InvalidWebhookException $e) {
            Log::error("Invalid webhook request for topic '$topic': {$e->getMessage()}");

            return response()->json(['message' => 'Invalid webhook request'], 401);
        } catch (Throwable $e) {
            Log::error("Exception handling '$topic' webhook: {$e->getMessage()}");

            return response()->json(['message' => 'Webhook handling failed'], 500);
        }

        return response()->json(['message' => "Processed '$topic' webhook"]);
    }
}
