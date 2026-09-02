<?php

namespace App\Http\Presenters;

use App\Models\LedgerEntry;

/**
 * One ledger row as the console reads it.
 *
 * The signed bucket deltas are the truth and are always sent, but no screen
 * wants to work out that an earn is "+72 pending" from two columns, so a `points`
 * figure and a `bucket` are derived here once rather than in every table that
 * displays a movement.
 *
 * A `reference` is derived too. The illustrative UI shows one reference column
 * carrying an order name for a sale, ADJ- for an adjustment and EXP- for an
 * expiry; those prefixes are presentation, so they are built here and never
 * stored, which keeps them changeable without a migration.
 */
final class LedgerEntryPresenter
{
    private const PREFIXES = [
        'earn' => 'ERN',
        'maturity' => 'MAT',
        'expiry' => 'EXP',
        'redemption' => 'RED',
        'redemption_restore' => 'RST',
        'earn_reversal' => 'REV',
        'adjustment' => 'ADJ',
        'opening_balance' => 'OPN',
    ];

    /**
     * @param  array{available_after?:int, pending_after?:int}  $runningBalance
     */
    public static function row(LedgerEntry $entry, array $runningBalance = []): array
    {
        $net = $entry->pending_delta + $entry->available_delta;

        return [
            'id' => $entry->id,
            'occurred_at' => self::iso($entry->occurred_at),
            'created_at' => self::iso($entry->created_at),
            'entry_type' => $entry->entry_type,
            'channel' => $entry->channel,
            'reference' => self::reference($entry),
            'pending_delta' => (int) $entry->pending_delta,
            'available_delta' => (int) $entry->available_delta,
            // The single figure a movement column shows. A maturity nets to
            // zero, which is correct: it creates no points, it moves them.
            'points' => $net,
            'bucket' => self::bucket($entry),
            'status' => self::status($entry),
            'reason' => $entry->reason,
            'order_name' => $entry->order_name,
            'shopify_order_id' => $entry->shopify_order_id === null ? null : (int) $entry->shopify_order_id,
            'shopify_refund_id' => $entry->shopify_refund_id === null ? null : (int) $entry->shopify_refund_id,
            'shopify_location_id' => $entry->shopify_location_id === null ? null : (int) $entry->shopify_location_id,
            'staff_reference' => $entry->staff_reference,
            'redemption_id' => $entry->redemption_id === null ? null : (int) $entry->redemption_id,
            'reward_id' => $entry->reward_id === null ? null : (int) $entry->reward_id,
            'parent_entry_id' => $entry->parent_entry_id === null ? null : (int) $entry->parent_entry_id,
            'qualifying_value_pence' => $entry->qualifying_value_pence === null
                ? null
                : (int) $entry->qualifying_value_pence,
            'matures_at' => self::iso($entry->matures_at),
            'expires_at' => self::iso($entry->expires_at),
            'rules_version_id' => (int) $entry->rules_version_id,
            'available_after' => $runningBalance['available_after'] ?? null,
            'pending_after' => $runningBalance['pending_after'] ?? null,
        ];
    }

    /** The member this movement belongs to, for the programme-wide ledger. */
    public static function withMember(LedgerEntry $entry, array $member, array $runningBalance = []): array
    {
        return self::row($entry, $runningBalance) + ['member' => $member];
    }

    public static function reference(LedgerEntry $entry): string
    {
        if ($entry->order_name) {
            return $entry->order_name;
        }

        return (self::PREFIXES[$entry->entry_type] ?? 'LDG').'-'.$entry->id;
    }

    /** Which bucket the movement acted on, for a one-column display. */
    private static function bucket(LedgerEntry $entry): string
    {
        if ($entry->pending_delta !== 0 && $entry->available_delta !== 0) {
            return 'transfer';
        }

        return $entry->pending_delta !== 0 ? 'pending' : 'available';
    }

    /**
     * Where the points stand now, not where they stood when posted.
     *
     * An earn is "pending" until its maturity date passes; after that the points
     * are the member's whether or not the maturity job has caught up, and a
     * profile that still said "pending" would be contradicting the balance.
     */
    private static function status(LedgerEntry $entry): string
    {
        return match ($entry->entry_type) {
            'earn' => $entry->matures_at !== null && $entry->matures_at->isFuture() ? 'pending' : 'available',
            'maturity', 'opening_balance', 'redemption_restore' => 'available',
            'expiry' => 'expired',
            'redemption' => 'applied',
            'earn_reversal' => 'reversed',
            'adjustment' => $entry->pending_delta !== 0 ? 'pending' : 'available',
            default => 'available',
        };
    }

    private static function iso($value): ?string
    {
        return $value?->toIso8601String();
    }
}
