<?php

namespace App\Jobs;

use App\Domain\Events\EventBus;
use App\Domain\Loyalty\OrderEarningService;
use App\Domain\Orders\OrderSource;
use App\Domain\Redemption\RedemptionService;
use App\Models\LoyaltyAccount;
use App\Models\WebhookEvent;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\RequestContext;
use App\Support\Webhooks\WebhookIngest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Earning for one order, off the request thread (M3).
 *
 * Queued because the webhook endpoint must answer Shopify quickly: the
 * arithmetic here re-reads the order through the Admin API, which is a network
 * call this app does not control the latency of, and a slow 200 gets a webhook
 * subscription throttled and eventually removed.
 *
 * Retried with a backoff. The order re-read can fail for reasons that pass -
 * throttling, a brief outage - and losing the earning for an order because of a
 * momentary API failure is not acceptable. The idempotency in
 * OrderEarningService is what makes retrying safe.
 */
class ProcessOrderPaid implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(
        public readonly string $shopDomain,
        public readonly int $shopifyOrderId,
        public readonly ?int $webhookEventId = null,
        public readonly string $topic = 'orders/paid',
    ) {}

    public function handle(
        OrderSource $orders,
        OrderEarningService $earning,
        RedemptionService $confirmations,
        WebhookIngest $ingest,
        AuditLogger $audit,
        RequestContext $context,
        EventBus $events,
    ): void {
        $context->forWebhook($this->shopDomain, $this->topic);

        $event = $this->webhookEventId === null
            ? null
            : WebhookEvent::query()->find($this->webhookEventId);

        try {
            $order = $orders->fetch($this->shopDomain, $this->shopifyOrderId);

            if ($order === null) {
                // Not marked processed: an unreadable order should stay visible
                // as an unfinished delivery rather than disappear into a log.
                $ingest->markFailed($event, 'The order could not be re-read.');

                return;
            }

            $result = $earning->apply($this->shopDomain, $order);

            if ($result['outcome'] === 'no_member') {
                $ingest->markIgnored($event, 'The customer on this order is not a loyalty member.');

                return;
            }

            // The order is paid, so any voucher value it carries is now spent.
            // This is the only moment points leave the ledger for a redemption:
            // a quote that was never paid for costs the member nothing, and a
            // basket abandoned at checkout is the common case.
            //
            // Deliberately after earning. Both are idempotent so the order does
            // not matter for correctness, but a member reading their history
            // should see what they earned before what they spent.
            $account = $result['account_id'] === null
                ? null
                : LoyaltyAccount::query()->find($result['account_id']);

            $redemptions = $account === null
                ? ['confirmed' => 0, 'points' => 0, 'replayed' => 0, 'skipped' => []]
                : $confirmations->confirmFromOrder($account, $order);

            if ($redemptions['confirmed'] > 0) {
                // C12: the redemption side of this order, as one entry naming
                // the references, so the audit log says what the automated side
                // did without duplicating the ledger.
                $audit->log(
                    action: AuditAction::REDEMPTION_CONFIRMED,
                    subjectType: 'LoyaltyAccount',
                    subjectId: $result['account_id'],
                    after: [
                        'shopify_order_id' => $order->shopifyOrderId,
                        'order_name' => $order->orderName,
                        'references' => $order->redemptionReferences,
                        'redemptions_confirmed' => $redemptions['confirmed'],
                        'points_consumed' => $redemptions['points'],
                        'skipped' => $redemptions['skipped'],
                    ],
                    shopDomain: $this->shopDomain,
                );
            }

            if ($result['points_posted'] !== 0) {
                // C12: one audit entry per order, carrying the reference.
                $audit->log(
                    action: AuditAction::ORDER_EARNED,
                    subjectType: 'LoyaltyAccount',
                    subjectId: $result['account_id'],
                    after: [
                        'shopify_order_id' => $order->shopifyOrderId,
                        'order_name' => $order->orderName,
                        'points_posted' => $result['points_posted'],
                        'target_points' => $result['target_points'],
                        'qualifying_pence' => $result['qualifying_pence'],
                        'excluded_pence' => $result['excluded_pence'],
                        'outcome' => $result['outcome'],
                    ],
                    shopDomain: $this->shopDomain,
                );
            }

            $ingest->markProcessed($event);
        } catch (Throwable $e) {
            $ingest->markFailed($event, $e->getMessage());

            throw $e;
        }
    }
}
