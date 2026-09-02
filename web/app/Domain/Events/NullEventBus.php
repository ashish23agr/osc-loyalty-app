<?php

namespace App\Domain\Events;

use Illuminate\Support\Facades\Log;

/**
 * Logs the event and drops it. The Sprint 2 default (M11).
 *
 * Not a silent no-op: it logs at debug with the full payload, so the events a
 * given order or sweep would have produced are visible while Klaviyo is not
 * connected. That is what makes it possible to check the call sites are right
 * before there is any real destination to check them against.
 *
 * It also counts what it dropped, which the tests assert on - an emit that
 * never happens is otherwise indistinguishable from one that did.
 */
final class NullEventBus implements EventBus
{
    /** @var list<LoyaltyEvent> */
    private array $emitted = [];

    public function emit(LoyaltyEvent $event): void
    {
        $this->emitted[] = $event;

        Log::debug('Loyalty event dropped: no Klaviyo driver is configured (M11 is Sprint 4).', [
            'event' => $event->name,
            'deliverable' => $event->isDeliverable(),
            'payload' => $event->toArray(),
        ]);
    }

    public function emitAll(array $events): void
    {
        foreach ($events as $event) {
            $this->emit($event);
        }
    }

    /** @return list<LoyaltyEvent> */
    public function emitted(): array
    {
        return $this->emitted;
    }

    public function count(): int
    {
        return count($this->emitted);
    }

    public function forget(): void
    {
        $this->emitted = [];
    }
}
