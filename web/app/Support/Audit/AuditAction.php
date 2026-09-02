<?php

namespace App\Support\Audit;

/**
 * Every audited action, and which of them demand a reason.
 *
 * A closed list rather than free strings, so the audit log stays searchable and
 * a typo cannot quietly create a category nobody ever looks at.
 */
final class AuditAction
{
    public const POINTS_ADJUSTED = 'points.adjusted';

    public const BALANCE_REBUILT = 'balance.rebuilt';

    public const RULES_SAVED = 'rules.saved';

    public const REWARD_ISSUED = 'reward.issued';

    public const REWARD_CANCELLED = 'reward.cancelled';

    public const REWARD_REISSUED = 'reward.reissued';

    /**
     * A quote held and made available to a channel.
     *
     * Distinct from redemption.confirmed, which is the order being paid for.
     * Conflating them would put "redeemed" in the audit log for every abandoned
     * basket, and nothing was spent.
     */
    public const REDEMPTION_QUOTED = 'redemption.quoted';

    public const REDEMPTION_CONFIRMED = 'redemption.confirmed';

    public const REDEMPTION_VOIDED = 'redemption.voided';

    public const REDEMPTION_REVERSED = 'redemption.reversed';

    public const ACCOUNT_ENROLLED = 'account.enrolled';

    public const ACCOUNT_UPDATED = 'account.updated';

    public const ACCOUNT_MERGED = 'account.merged';

    public const STAFF_ROLE_ASSIGNED = 'staff.role_assigned';

    public const STAFF_BOOTSTRAPPED = 'staff.bootstrapped';

    public const MIGRATION_COMMITTED = 'migration.committed';

    public const MIGRATION_EXCEPTION_RESOLVED = 'migration.exception_resolved';

    /**
     * One entry per scheduled sweep run, carrying its counts (C12).
     *
     * Not one per member. The ledger already holds every movement the sweep
     * made, and an audit log that copied it would be a second, worse ledger.
     * This answers "did the engine run, when, and how much did it move".
     */
    public const JOB_COMPLETED = 'job.completed';

    /** A sweep that started and threw. Recorded so a silent failure cannot happen. */
    public const JOB_FAILED = 'job.failed';

    /** One entry per order whose earning was posted from a webhook (C12). */
    public const ORDER_EARNED = 'order.earned';

    /**
     * One entry per order reversed by a refund or a cancellation (M8, C12).
     *
     * Distinct from redemption.reversed, which is a member of staff reversing a
     * redemption by hand and rightly demands a reason. This one is automatic and
     * carries the order and refund reference instead.
     */
    public const ORDER_REVERSED = 'order.reversed';

    /**
     * Actions where a reason is mandatory.
     *
     * These are the ones that move money or rewrite identity, and where a
     * history without a stated reason is useless six months later.
     */
    public const REQUIRE_REASON = [
        self::POINTS_ADJUSTED,
        self::REWARD_CANCELLED,
        self::REWARD_REISSUED,
        self::REDEMPTION_REVERSED,
        self::ACCOUNT_MERGED,
    ];

    public static function requiresReason(string $action): bool
    {
        return in_array($action, self::REQUIRE_REASON, true);
    }

    /** @return list<string> Every action name, excluding the REQUIRE_REASON list itself. */
    public static function all(): array
    {
        $constants = (new \ReflectionClass(self::class))->getConstants();

        return array_values(array_filter($constants, 'is_string'));
    }

    public static function isKnown(string $action): bool
    {
        return in_array($action, self::all(), true);
    }
}
