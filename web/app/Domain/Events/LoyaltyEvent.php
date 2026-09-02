<?php

namespace App\Domain\Events;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * One thing that happened to one member, ready to be told to Klaviyo.
 *
 * Carries the member's identity in every form the destination might key on -
 * the loyalty account id, the Shopify customer id and the email - because
 * Klaviyo profiles are matched on email and this system deliberately supports
 * members who have none (MD1). An event for a member with no email is still a
 * real event; it is the driver's business to decide it cannot be delivered.
 */
final readonly class LoyaltyEvent
{
    public function __construct(
        public string $name,
        public string $shopDomain,
        public int $loyaltyAccountId,
        public ?int $shopifyCustomerId = null,
        public ?string $email = null,
        public array $properties = [],
        public ?DateTimeInterface $occurredAt = null,
    ) {
        if (! LoyaltyEventName::isKnown($this->name)) {
            throw new InvalidArgumentException('Unknown loyalty event: '.$this->name);
        }
    }

    public function toArray(): array
    {
        return [
            'event' => $this->name,
            'shop_domain' => $this->shopDomain,
            'loyalty_account_id' => $this->loyaltyAccountId,
            'shopify_customer_id' => $this->shopifyCustomerId,
            'email' => $this->email,
            'occurred_at' => $this->occurredAt?->format(DATE_ATOM),
            'properties' => $this->properties,
        ];
    }

    /** MD1: a member with no email cannot be reached, and that is not a fault. */
    public function isDeliverable(): bool
    {
        return $this->email !== null && $this->email !== '';
    }
}
