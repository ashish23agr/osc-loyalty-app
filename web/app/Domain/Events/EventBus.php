<?php

namespace App\Domain\Events;

/**
 * Where member-facing events go (M11).
 *
 * The interface exists now, in Sprint 2, so that every place which should tell
 * Klaviyo something is already telling it - through a driver that drops the
 * message. Sprint 4 binds a real driver and nothing else changes.
 *
 * The alternative, adding the emit calls in Sprint 4, means going back through
 * earning, maturity, expiry, enrolment and the birthday job and finding all the
 * places again. The call sites are the hard part; the transport is not.
 */
interface EventBus
{
    public function emit(LoyaltyEvent $event): void;

    /** @param  list<LoyaltyEvent>  $events */
    public function emitAll(array $events): void;
}
