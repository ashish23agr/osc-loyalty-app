<?php

namespace App\Support\Webhooks;

/**
 * Which delivery is being handled right now.
 *
 * Shopify's library calls a Handler with (topic, shop, body) and nothing else,
 * so the headers that make a delivery identifiable - above all
 * X-Shopify-Webhook-Id - are gone by the time a handler runs. The controller
 * captures them here first, and handlers read them from the container.
 *
 * A singleton for the life of one request, which is exactly the life of one
 * delivery.
 */
class WebhookDelivery
{
    private ?string $webhookId = null;

    private ?string $topic = null;

    private ?string $shopDomain = null;

    private ?string $triggeredAt = null;

    private ?string $payloadHash = null;

    public function capture(
        ?string $webhookId,
        ?string $topic,
        ?string $shopDomain,
        ?string $triggeredAt,
        string $rawBody,
    ): self {
        $this->webhookId = $webhookId;
        $this->topic = $topic;
        $this->shopDomain = $shopDomain;
        $this->triggeredAt = $triggeredAt;
        $this->payloadHash = hash('sha256', $rawBody);

        return $this;
    }

    public function webhookId(): ?string
    {
        return $this->webhookId;
    }

    public function topic(): ?string
    {
        return $this->topic;
    }

    public function shopDomain(): ?string
    {
        return $this->shopDomain;
    }

    public function triggeredAt(): ?string
    {
        return $this->triggeredAt;
    }

    public function payloadHash(): ?string
    {
        return $this->payloadHash;
    }

    /**
     * A delivery with no id cannot be deduplicated by id.
     *
     * Real Shopify deliveries always carry one; a hand-made request in a test or
     * a replayed curl might not. Falling back to the payload hash keeps the
     * outer guard working rather than letting an unidentified delivery through
     * it, and the ledger's own idempotency key is the inner guard regardless.
     */
    public function identifier(): string
    {
        return $this->webhookId ?? 'sha256:'.substr((string) $this->payloadHash, 0, 29);
    }
}
