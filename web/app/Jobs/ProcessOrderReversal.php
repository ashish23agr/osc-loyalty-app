<?php

namespace App\Jobs;

use App\Domain\Loyalty\OrderReversalService;
use App\Domain\Orders\OrderSource;
use App\Models\WebhookEvent;
use App\Support\Audit\RequestContext;
use App\Support\Webhooks\WebhookIngest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Reversal for one order, off the request thread (M8, D9).
 *
 * Drives both refunds/create and orders/cancelled, because they are the same
 * operation with a different amount: a refund reverses the proportion refunded,
 * a cancellation reverses everything. Keeping them in one job keeps the two
 * from drifting into two slightly different readings of D9.
 */
class ProcessOrderReversal implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(
        public readonly string $shopDomain,
        public readonly int $shopifyOrderId,
        public readonly ?int $shopifyRefundId = null,
        public readonly ?int $webhookEventId = null,
        public readonly string $topic = 'refunds/create',
    ) {}

    public function handle(
        OrderSource $orders,
        OrderReversalService $reversals,
        WebhookIngest $ingest,
        RequestContext $context,
    ): void {
        $context->forWebhook($this->shopDomain, $this->topic);

        $event = $this->webhookEventId === null
            ? null
            : WebhookEvent::query()->find($this->webhookEventId);

        try {
            $order = $orders->fetch($this->shopDomain, $this->shopifyOrderId);

            if ($order === null) {
                $ingest->markFailed($event, 'The order could not be re-read.');

                return;
            }

            $result = $reversals->apply(
                shopDomain: $this->shopDomain,
                order: $order,
                shopifyRefundId: $this->shopifyRefundId,
            );

            if ($result['outcome'] === 'no_member') {
                $ingest->markIgnored($event, 'The customer on this order is not a loyalty member.');

                return;
            }

            $ingest->markProcessed($event);
        } catch (Throwable $e) {
            $ingest->markFailed($event, $e->getMessage());

            throw $e;
        }
    }
}
