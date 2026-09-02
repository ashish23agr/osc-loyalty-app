<?php

declare(strict_types=1);

namespace App\Lib\Handlers;

use App\Jobs\ProcessOrderReversal;
use App\Support\Webhooks\WebhookDelivery;
use App\Support\Webhooks\WebhookIngest;
use Illuminate\Support\Facades\Log;
use Shopify\Webhooks\Handler;

/**
 * orders/cancelled (M8).
 *
 * A cancellation is a full reversal, and it takes the same job as a refund
 * because it is the same operation with the amount set to everything. There is
 * no refund id: the order itself is the reference, and the job's idempotency
 * key falls back to the order for exactly this case.
 */
class OrdersCancelled implements Handler
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

        if (! $recorded['is_new']) {
            return;
        }

        ProcessOrderReversal::dispatch($shop, $orderId, null, $recorded['event']?->id, $topic);
    }
}
