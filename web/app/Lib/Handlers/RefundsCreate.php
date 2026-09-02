<?php

declare(strict_types=1);

namespace App\Lib\Handlers;

use App\Jobs\ProcessOrderReversal;
use App\Support\Webhooks\WebhookDelivery;
use App\Support\Webhooks\WebhookIngest;
use Illuminate\Support\Facades\Log;
use Shopify\Webhooks\Handler;

/**
 * refunds/create (M8, D9).
 *
 * The payload gives the refund id and the order id and nothing else is trusted:
 * D9c needs the CUMULATIVE refunded eligible value, and this delivery only knows
 * about one refund. The job re-reads the order and sums every refund it has ever
 * had, which is also what makes an out-of-order or missed delivery harmless.
 */
class RefundsCreate implements Handler
{
    public function handle(string $topic, string $shop, array $body): void
    {
        $orderId = isset($body['order_id']) ? (int) $body['order_id'] : null;
        $refundId = isset($body['id']) ? (int) $body['id'] : null;

        if ($orderId === null) {
            Log::warning("Webhook $topic for $shop carried no order id.");

            return;
        }

        $delivery = app(WebhookDelivery::class);
        $recorded = app(WebhookIngest::class)->record($delivery, $orderId);

        if (! $recorded['is_new']) {
            return;
        }

        ProcessOrderReversal::dispatch(
            $shop,
            $orderId,
            $refundId,
            $recorded['event']?->id,
            $topic,
        );
    }
}
