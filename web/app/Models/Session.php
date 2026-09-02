<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A Shopify OAuth session (offline or online access token).
 *
 * Stored in `shopify_sessions` so it never collides with Laravel's own
 * framework `sessions` table.
 */
class Session extends Model
{
    protected $table = 'shopify_sessions';

    protected $fillable = [
        'session_id', 'shop', 'state', 'is_online', 'scope', 'expires_at',
        'access_token', 'user_id', 'user_first_name', 'user_last_name',
        'user_email', 'user_email_verified', 'account_owner', 'locale',
        'collaborator',
    ];

    protected $hidden = ['access_token'];

    protected $casts = [
        'is_online' => 'boolean',
        'user_email_verified' => 'boolean',
        'account_owner' => 'boolean',
        'collaborator' => 'boolean',
        'expires_at' => 'datetime',
    ];
}
