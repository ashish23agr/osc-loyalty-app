<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Who did what and when. Written by the application, never by hand, and not
 * editable from the interface.
 */
class AuditEntry extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'loyalty_audit_log';

    protected $fillable = [
        'shop_domain', 'actor_type', 'actor_staff_id', 'actor_name', 'action',
        'subject_type', 'subject_id', 'reason', 'before_state', 'after_state',
        'channel', 'ip_address', 'request_id',
    ];

    protected $casts = [
        'before_state' => 'array',
        'after_state' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('The audit log is append-only.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('The audit log is append-only.');
        });
    }
}
