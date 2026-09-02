<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One Shopify webhook delivery, recorded on arrival.
 *
 * The Blueprint assumes webhooks arrive late, twice or out of order. Recording
 * the delivery is what makes that diagnosable rather than mysterious, and it is
 * the outer guard in front of the ledger's own idempotency key.
 */
class WebhookEvent extends Model
{
    public $timestamps = false;

    protected $table = 'webhook_events';

    protected $fillable = [
        'shop_domain', 'topic', 'shopify_webhook_id', 'payload_hash',
        'resource_id', 'state', 'attempts', 'error', 'received_at', 'processed_at',
    ];

    protected $casts = [
        'resource_id' => 'integer',
        'attempts' => 'integer',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function isProcessed(): bool
    {
        return $this->state === 'processed';
    }
}
