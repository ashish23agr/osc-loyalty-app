<?php

namespace App\Support\Webhooks;

use App\Models\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * Records a delivery and says whether it is new (M3, M8).
 *
 * The outer of two idempotency guards. This one is cheap and stops a redelivery
 * before any handler runs; the ledger's idempotency key is the inner one and
 * stops it even if this is bypassed - by a replay, by a manual reprocess, or by
 * a delivery arriving without an id.
 *
 * Two guards rather than one because they fail differently. The webhook id
 * catches Shopify sending the same delivery twice. The ledger key catches the
 * same EFFECT arriving by two different routes, which is what a replay is.
 */
class WebhookIngest
{
    /**
     * @return array{event:?WebhookEvent, is_new:bool}
     */
    public function record(WebhookDelivery $delivery, ?int $resourceId = null): array
    {
        $shop = $delivery->shopDomain();
        $topic = $delivery->topic();

        if ($shop === null || $topic === null) {
            return ['event' => null, 'is_new' => false];
        }

        try {
            $event = WebhookEvent::create([
                'shop_domain' => $shop,
                'topic' => $topic,
                'shopify_webhook_id' => $delivery->identifier(),
                'payload_hash' => (string) $delivery->payloadHash(),
                'resource_id' => $resourceId,
                'state' => 'received',
                'attempts' => 1,
                'received_at' => now(),
            ]);

            return ['event' => $event, 'is_new' => true];
        } catch (UniqueConstraintViolationException) {
            // Already seen. Recorded as an extra attempt so a redelivery storm
            // is visible rather than invisible, and handed back so the caller
            // can decide - normally to do nothing at all.
            $event = WebhookEvent::query()
                ->where('shop_domain', $shop)
                ->where('shopify_webhook_id', $delivery->identifier())
                ->first();

            if ($event !== null) {
                $event->forceFill(['attempts' => (int) $event->attempts + 1])->save();
            }

            Log::info('Redelivered Shopify webhook ignored', [
                'shop' => $shop,
                'topic' => $topic,
                'webhook_id' => $delivery->identifier(),
                'attempts' => $event?->attempts,
            ]);

            return ['event' => $event, 'is_new' => false];
        }
    }

    public function markProcessed(?WebhookEvent $event): void
    {
        $event?->forceFill(['state' => 'processed', 'processed_at' => now()])->save();
    }

    public function markIgnored(?WebhookEvent $event, string $why): void
    {
        $event?->forceFill([
            'state' => 'ignored',
            'processed_at' => now(),
            'error' => mb_substr($why, 0, 400),
        ])->save();
    }

    public function markFailed(?WebhookEvent $event, string $error): void
    {
        $event?->forceFill([
            'state' => 'failed',
            'error' => mb_substr($error, 0, 400),
        ])->save();
    }
}
