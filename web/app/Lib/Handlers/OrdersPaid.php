<?php

declare(strict_types=1);

namespace App\Lib\Handlers;

use App\Jobs\ProcessOrderPaid;
use App\Support\Webhooks\WebhookDelivery;
use App\Support\Webhooks\WebhookIngest;
use Illuminate\Support\Facades\Log;
use Shopify\Webhooks\Handler;

/**
 * orders/paid, and orders/updated after an edit (M3).
 *
 * Does as little as possible. Records the delivery, queues the work, returns —
 * so the 200 Shopify is waiting for does not depend on an Admin API round trip
 * or on the ledger. A webhook endpoint that is slow gets throttled and
 * eventually unsubscribed, and a loyalty programme that stops earning is not
 * something anyone notices quickly.
 *
 * The payload is used for one thing only: the order id. Every figure the ledger
 * acts on is re-read in the job, per M3.
 */
class OrdersPaid implements Handler
{
    public function handle(string $topic, string $shop, array $body): void
    {
        $orderId = isset($body['id']) ? (int) $body['id'] : null;

        if ($orderId === null) {
            Log::warning("Webhook $topic for $shop carried no order id.");

            return;
        }

        $delivery = app(WebhookDelivery::class);
        $recorded = app(WebhookIngest::class)->record($delivery, $orderId);

        // Redelivery. The ledger would refuse it too, but there is no reason to
        // spend an Admin API call finding that out.
        if (! $recorded['is_new']) {
            return;
        }

        ProcessOrderPaid::dispatch(
            $shop,
            $orderId,
            $recorded['event']?->id,
            $topic,
        );
    }
}
