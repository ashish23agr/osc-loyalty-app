<?php

namespace App\Domain\Events;

/**
 * The five member-facing events Klaviyo owns the message for (M11, Sprint 4).
 *
 * A closed list for the same reason AuditAction is one: a typo must not quietly
 * create an event nobody has built a flow for. The names are the contract with
 * Klaviyo and should be treated as published once Sprint 4 connects a real
 * driver — renaming one silently breaks a flow a marketer built.
 *
 * This app never owns the wording. It says a thing happened, to whom, with the
 * figures the message needs; the message itself lives in Klaviyo.
 */
final class LoyaltyEventName
{
    /** A member has just joined. The welcome flow. */
    public const MEMBER_ENROLLED = 'member.enrolled';

    /** Pending points have matured and are now spendable. */
    public const POINTS_AVAILABLE = 'points.available';

    /** The member has crossed into another whole voucher increment. */
    public const VOUCHER_INCREMENT_REACHED = 'voucher.increment_reached';

    /**
     * Points are approaching their expiry date.
     *
     * Fired the day a lot enters the warning window, not every night it sits
     * inside it, so the flow has one trigger per lot rather than a nightly
     * repeat it would have to suppress.
     */
    public const POINTS_EXPIRING_SOON = 'points.expiring_soon';

    /** The birthday reward has been issued (M5, C4 — lapsed members included). */
    public const BIRTHDAY_REWARD_ISSUED = 'reward.birthday_issued';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::MEMBER_ENROLLED,
            self::POINTS_AVAILABLE,
            self::VOUCHER_INCREMENT_REACHED,
            self::POINTS_EXPIRING_SOON,
            self::BIRTHDAY_REWARD_ISSUED,
        ];
    }

    public static function isKnown(string $name): bool
    {
        return in_array($name, self::all(), true);
    }
}
