<?php

namespace App\Models;

use App\Domain\Rules\RuleSet;
use Illuminate\Database\Eloquent\Model;

/**
 * An immutable snapshot of every configurable rule, with who saved it and when.
 *
 * Never updated in place, so any historical calculation can be replayed against
 * the rules that were in force at the time.
 */
class RulesVersion extends Model
{
    protected $table = 'loyalty_rules_versions';

    protected $fillable = [
        'shop_domain', 'version', 'payload', 'effective_from',
        'change_summary', 'created_by_staff_id', 'created_by_name',
    ];

    protected $casts = [
        'payload' => 'array',
        'version' => 'integer',
        'effective_from' => 'datetime',
    ];

    public function ruleSet(): RuleSet
    {
        return new RuleSet($this->payload, $this->id, $this->version);
    }
}
