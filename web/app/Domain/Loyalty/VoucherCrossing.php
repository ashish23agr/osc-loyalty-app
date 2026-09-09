<?php

namespace App\Domain\Loyalty;

use App\Domain\Events\LoyaltyEventName;
use App\Domain\Events\MemberEvents;
use App\Models\LoyaltyAccount;

/**
 * The one place that decides a member has crossed into another voucher increment.
 *
 * "You have another five pounds" is only true when the derived balance actually
 * gained an increment, so the rule is a comparison of both sides rather than a
 * fact about any one posting. That rule lived inside MaturitySweep, which made
 * maturity the only path that announced a crossing — and points reach the
 * available bucket by more routes than maturity. A manual adjustment crossed
 * £5 in silence, which is a promise the proposal makes and the code did not
 * keep (V20).
 *
 * So it lives here, and every path that moves available points announces
 * through it. A new posting route has one call to make rather than six lines to
 * remember, and the payload cannot drift between causes — which matters more
 * than it looks, because a Klaviyo flow keys on the event name and would
 * quietly receive a different shape depending on what moved the points.
 *
 * Only upward crossings are announced. A balance that falls below an increment
 * is not news anyone wants sent to them, and expiry already has its own warning.
 */
final class VoucherCrossing
{
    public function __construct(private readonly MemberEvents $events) {}

    /**
     * Announce a crossing, if there was one.
     *
     * Call AFTER the posting has committed and with a $before captured before
     * it: a crossing needs both sides, and an event emitted inside a
     * transaction that then rolls back is a notification for something that
     * never happened.
     */
    public function announce(LoyaltyAccount $account, Balances $before, Balances $after): void
    {
        if ($after->voucher->increments <= $before->voucher->increments) {
            return;
        }

        $this->events->emit($account, LoyaltyEventName::VOUCHER_INCREMENT_REACHED, [
            'increments' => $after->voucher->increments,
            'increments_gained' => $after->voucher->increments - $before->voucher->increments,
            'voucher_balance_pence' => $after->voucher->pence(),
            'points_to_next_increment' => $after->voucher->pointsToNextIncrement,
        ]);
    }
}
