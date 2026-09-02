<?php

namespace App\Domain\Events;

use App\Models\LoyaltyAccount;

/**
 * Builds a member event from an account, so no call site assembles one by hand.
 *
 * Every event carries the same identity block — account id, Shopify customer id
 * and email — and getting one of those wrong at one call site is exactly the
 * sort of thing that is invisible until a marketer says a flow is missing
 * people. Built once here instead.
 */
final class MemberEvents
{
    public function __construct(private readonly EventBus $bus) {}

    public function emit(LoyaltyAccount $account, string $name, array $properties = []): void
    {
        $this->bus->emit($this->make($account, $name, $properties));
    }

    public function make(LoyaltyAccount $account, string $name, array $properties = []): LoyaltyEvent
    {
        return new LoyaltyEvent(
            name: $name,
            shopDomain: (string) $account->shop_domain,
            loyaltyAccountId: (int) $account->id,
            shopifyCustomerId: $account->shopify_customer_id === null
                ? null
                : (int) $account->shopify_customer_id,
            // MD1: may be null, and that is a supported member rather than a
            // defect. The driver decides what it can do about it.
            email: $account->email,
            properties: $properties,
            occurredAt: now(),
        );
    }
}
