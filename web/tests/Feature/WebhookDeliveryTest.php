<?php

namespace Tests\Feature;

use App\Models\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Shopify\Webhooks\Handler;
use Shopify\Webhooks\Registry;
use Shopify\Webhooks\Topics;
use Tests\TestCase;

class WebhookDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'loyalty-system.myshopify.com';

    /** Signs a body the same way Shopify does. */
    private function hmac(string $body): string
    {
        return base64_encode(hash_hmac('sha256', $body, config('shopify.api_secret'), true));
    }

    /** @param array<string, mixed> $payload */
    private function deliver(string $topic, array $payload, ?string $hmac = null)
    {
        $body = json_encode($payload);

        return $this->call(
            'POST',
            config('shopify.webhooks.path'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SHOPIFY_TOPIC' => $topic,
                'HTTP_X_SHOPIFY_SHOP_DOMAIN' => self::SHOP,
                'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac ?? $this->hmac($body),
            ],
            $body,
        );
    }

    public function test_a_correctly_signed_webhook_reaches_its_handler(): void
    {
        $received = new \stdClass;
        $received->topic = null;
        $received->shop = null;
        $received->body = null;

        Registry::addHandler(Topics::PRODUCTS_UPDATE, new class($received) implements Handler
        {
            public function __construct(private \stdClass $received) {}

            public function handle(string $topic, string $shop, array $body): void
            {
                $this->received->topic = $topic;
                $this->received->shop = $shop;
                $this->received->body = $body;
            }
        });

        $this->deliver('products/update', ['id' => 123, 'title' => 'Oxford Shirt'])
            ->assertOk();

        $this->assertSame(Topics::PRODUCTS_UPDATE, $received->topic);
        $this->assertSame(self::SHOP, $received->shop);
        $this->assertSame('Oxford Shirt', $received->body['title']);
    }

    public function test_a_webhook_with_a_bad_hmac_is_rejected(): void
    {
        $this->deliver('products/update', ['id' => 123], hmac: base64_encode('not-the-right-signature'))
            ->assertStatus(401)
            ->assertJsonPath('message', 'Invalid webhook request');
    }

    public function test_the_webhook_endpoint_is_exempt_from_csrf(): void
    {
        // A CSRF rejection would be a 419; anything else means the exemption works.
        $this->assertNotSame(419, $this->deliver('products/update', ['id' => 1])->status());
    }

    public function test_app_uninstalled_removes_the_stored_sessions_for_that_shop(): void
    {
        Session::create([
            'session_id' => 'offline_'.self::SHOP,
            'shop' => self::SHOP,
            'is_online' => false,
            'access_token' => 'shpat_test_token',
            'scope' => config('shopify.scopes'),
        ]);
        Session::create([
            'session_id' => 'offline_other.myshopify.com',
            'shop' => 'other.myshopify.com',
            'is_online' => false,
            'access_token' => 'shpat_other_token',
        ]);

        $this->deliver('app/uninstalled', ['id' => 85382955248])->assertOk();

        $this->assertSame(0, Session::where('shop', self::SHOP)->count());
        $this->assertSame(1, Session::where('shop', 'other.myshopify.com')->count());
    }
}
