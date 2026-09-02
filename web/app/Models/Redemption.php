<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links the money side of a redemption to the points side, which is what makes
 * redemption auditable.
 *
 * A redemption is quoted first and confirmed only when an order is paid. An
 * unconfirmed quote expires without consuming anything.
 */
class Redemption extends Model
{
    protected $table = 'loyalty_redemptions';

    protected $fillable = [
        'shop_domain', 'reference', 'loyalty_account_id', 'channel', 'state',
        'amount_pence', 'amount_reversed_pence', 'points_consumed',
        'points_restored', 'reward_id', 'discount_mechanism',
        'shopify_discount_gid', 'shopify_order_id', 'order_name',
        'shopify_location_id', 'staff_reference', 'rules_version_id',
        'validation_snapshot', 'quoted_at', 'quote_expires_at', 'confirmed_at',
        'reversed_at',
    ];

    protected $casts = [
        'amount_pence' => 'integer',
        'amount_reversed_pence' => 'integer',
        'points_consumed' => 'integer',
        'points_restored' => 'integer',
        'validation_snapshot' => 'array',
        'quoted_at' => 'datetime',
        'quote_expires_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(LoyaltyAccount::class, 'loyalty_account_id');
    }

    public function reward(): BelongsTo
    {
        return $this->belongsTo(Reward::class, 'reward_id');
    }

    /**
     * D9d: points still out on this redemption, after any partial refund.
     *
     * A partial refund tracks its value here and leaves the state alone; only a
     * full reversal flips state to 'reversed'. Asking this rather than reading
     * the state is how a caller tells "partly given back" from "finished".
     */
    public function pointsOutstanding(): int
    {
        return max(0, (int) $this->points_consumed - (int) $this->points_restored);
    }

    public function isFullyReversed(): bool
    {
        return $this->state === 'reversed';
    }

    public function isPartiallyReversed(): bool
    {
        return $this->state !== 'reversed' && (int) $this->points_restored > 0;
    }
}
