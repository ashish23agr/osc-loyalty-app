<?php

namespace App\Http\Presenters;

use App\Models\AuditEntry;

/**
 * One audit row as the audit log screen reads it.
 *
 * The screen shows a single "before to after" column, so the two state
 * snapshots are also flattened into a per-field list here. The snapshots
 * themselves are always sent unchanged as well, because they are the record and
 * a presenter must not be the only place the truth survives.
 *
 * The actor role is looked up rather than stored on the entry: a role can change
 * after the fact, and the screen wants the person's role now, not a copy frozen
 * at write time that nothing would ever correct.
 */
final class AuditEntryPresenter
{
    /**
     * @param  array<int, string>  $roles  shopify_staff_id => role
     */
    public static function row(AuditEntry $entry, array $roles = []): array
    {
        return [
            'id' => (int) $entry->id,
            'created_at' => $entry->created_at?->toIso8601String(),
            'actor' => [
                'type' => $entry->actor_type,
                'staff_id' => $entry->actor_staff_id === null ? null : (int) $entry->actor_staff_id,
                'name' => $entry->actor_name,
                'role' => $entry->actor_staff_id === null
                    ? null
                    : ($roles[(int) $entry->actor_staff_id] ?? null),
            ],
            'action' => $entry->action,
            'subject_type' => $entry->subject_type,
            'subject_id' => $entry->subject_id === null ? null : (int) $entry->subject_id,
            'reason' => $entry->reason,
            'before_state' => $entry->before_state,
            'after_state' => $entry->after_state,
            'changes' => self::changes($entry),
            'channel' => $entry->channel,
            'ip_address' => $entry->ip_address,
            'request_id' => $entry->request_id,
        ];
    }

    /**
     * Every field that moved, as { field, from, to }.
     *
     * Built from the union of both snapshots so a field that only appears on one
     * side, such as a value first set or a value cleared, is still shown rather
     * than silently dropped.
     *
     * @return list<array{field:string, from:mixed, to:mixed}>
     */
    private static function changes(AuditEntry $entry): array
    {
        $before = is_array($entry->before_state) ? $entry->before_state : [];
        $after = is_array($entry->after_state) ? $entry->after_state : [];

        $fields = array_keys($before + $after);
        $changes = [];

        foreach ($fields as $field) {
            $changes[] = [
                'field' => (string) $field,
                'from' => $before[$field] ?? null,
                'to' => $after[$field] ?? null,
            ];
        }

        return $changes;
    }
}
