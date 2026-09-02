<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A genuinely issued reward: the birthday voucher, or a goodwill voucher a
 * manager issues by hand.
 *
 * Per D1 the points-derived voucher balance is NOT here. It is computed on read
 * from the ledger, falls automatically as the underlying points expire, and has
 * no expiry of its own. Only the objects in this table are issued, and only
 * they have their own expiry date.
 */
class Reward extends Model
{
    protected $table = 'loyalty_rewards';

    protected $fillable = [
        'shop_domain', 'loyalty_account_id', 'reward_type', 'value_pence', 'currency',
        'birthday_year', 'state', 'issued_at', 'expires_at', 'redeemed_at',
        'cancelled_at', 'cancelled_reason', 'superseded_by_reward_id',
        'issued_by_staff_id', 'rules_version_id',
    ];

    protected $casts = [
        'value_pence' => 'integer',
        'birthday_year' => 'integer',
        'issued_at' => 'datetime',
        'expires_at' => 'datetime',
        'redeemed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(LoyaltyAccount::class, 'loyalty_account_id');
    }

    public function isOutstanding(): bool
    {
        return $this->state === 'issued';
    }
}
