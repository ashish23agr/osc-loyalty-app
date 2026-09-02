<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * One movement of points. Append-only: a correction is a new row.
 *
 * Points live in one of two buckets, and every entry is a transfer between them
 * or in and out of one:
 *
 *   earn                pending +N
 *   maturity            pending -N, available +N
 *   expiry              available -N
 *   redemption          available -N
 *   redemption_restore  available +N
 *   earn_reversal       pending -N or available -N, depending where they sit
 *   adjustment          either bucket, signed
 *   opening_balance     available +N   (migration, D4)
 */
class LedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'loyalty_ledger';

    protected $fillable = [
        'shop_domain', 'loyalty_account_id', 'entry_type', 'pending_delta',
        'available_delta', 'idempotency_key', 'rules_version_id', 'channel',
        'shopify_order_id', 'order_name', 'shopify_refund_id', 'shopify_location_id',
        'staff_reference', 'redemption_id', 'reward_id', 'parent_entry_id',
        'qualifying_value_pence', 'matures_at', 'expires_at', 'reason', 'occurred_at',
    ];

    protected $casts = [
        'pending_delta' => 'integer',
        'available_delta' => 'integer',
        'qualifying_value_pence' => 'integer',
        'matures_at' => 'datetime',
        'expires_at' => 'datetime',
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // The ledger is the source of truth for every balance. Nothing in the
        // application may edit or remove an entry, so the model refuses rather
        // than relying on reviewers to notice. Production should also withhold
        // UPDATE and DELETE from the application database user.
        static::updating(function (): never {
            throw new RuntimeException(
                'loyalty_ledger is append-only. Post a correcting entry instead of editing one.'
            );
        });

        static::deleting(function (): never {
            throw new RuntimeException('loyalty_ledger is append-only. Entries cannot be deleted.');
        });
    }

    /** Entry types that create points that can later be consumed or expire. */
    public const LOT_TYPES = ['earn', 'opening_balance'];

    public function isLot(): bool
    {
        return in_array($this->entry_type, self::LOT_TYPES, true);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LoyaltyAccount::class, 'loyalty_account_id');
    }

    public function rulesVersion(): BelongsTo
    {
        return $this->belongsTo(RulesVersion::class, 'rules_version_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_entry_id');
    }

    /** Allocations drawn FROM this entry, when it is a lot. */
    public function allocationsOut(): HasMany
    {
        return $this->hasMany(LotAllocation::class, 'lot_entry_id');
    }

    /** Allocations this entry consumed, when it is a redemption, expiry or reversal. */
    public function allocationsIn(): HasMany
    {
        return $this->hasMany(LotAllocation::class, 'consuming_entry_id');
    }
}
