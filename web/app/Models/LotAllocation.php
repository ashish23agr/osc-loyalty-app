<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Records that a consuming entry drew N points from a specific earning.
 *
 * Expiry has to know which points are oldest, and a redemption has to consume
 * the oldest first, or expiry becomes guesswork. Because the ledger is
 * append-only the remaining life of a lot cannot be a mutable column on the
 * earn row, so it is the lot total minus its allocations.
 */
class LotAllocation extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'loyalty_lot_allocations';

    protected $fillable = ['shop_domain', 'lot_entry_id', 'consuming_entry_id', 'points'];

    protected $casts = [
        'points' => 'integer',
        'created_at' => 'datetime',
    ];

    public function lot(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class, 'lot_entry_id');
    }

    public function consumer(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class, 'consuming_entry_id');
    }
}
