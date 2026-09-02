<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Shopify\Webhooks\Topics;
use Tests\TestCase;

class FoundationHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_reports_backend_database_and_shopify_status(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('database.connected', true)
            ->assertJsonPath('database.shopify_sessions_table', true)
            ->assertJsonPath('shopify.context_initialized', true)
            ->assertJsonPath('shopify.api_version', config('shopify.api_version'))
            ->assertJsonStructure([
                'backend' => ['laravel_version', 'php_version', 'environment'],
                'database' => ['driver', 'name', 'stored_shop_sessions'],
                'webhooks' => ['endpoint', 'handlers_registered', 'registered_after_oauth'],
                'shopify' => ['scopes', 'embedded', 'app_url'],
            ]);
    }

    public function test_every_declared_webhook_topic_has_a_handler(): void
    {
        $topics = $this->getJson('/api/health')->json('webhooks.handlers_registered');

        $this->assertNotEmpty($topics);

        foreach ($topics as $topic => $registered) {
            $this->assertTrue($registered, "No webhook handler registered for '$topic'.");
        }

        // The business topics plus the three mandatory GDPR ones.
        $this->assertArrayHasKey(Topics::APP_UNINSTALLED, $topics);
        $this->assertArrayHasKey(Topics::PRODUCTS_UPDATE, $topics);
        $this->assertArrayHasKey(Topics::CUSTOMERS_DATA_REQUEST, $topics);
        $this->assertArrayHasKey(Topics::CUSTOMERS_REDACT, $topics);
        $this->assertArrayHasKey(Topics::SHOP_REDACT, $topics);
    }

    public function test_shop_endpoint_requires_a_shopify_session(): void
    {
        // No session token and no shop: nothing to reauthorize against, so the
        // middleware explains itself instead of pointing at /api/auth?shop=.
        $this->getJson('/api/shop')
            ->assertStatus(403)
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'No Shopify session'));
    }

    public function test_shop_endpoint_asks_a_known_shop_to_reauthorize(): void
    {
        $response = $this->getJson('/api/shop?shop=loyalty-system.myshopify.com');

        // The shopify.auth middleware answers XHR calls with the documented
        // reauthorization headers instead of a redirect the iframe cannot follow.
        $response->assertStatus(403)
            ->assertHeader('X-Shopify-API-Request-Failure-Reauthorize', '1')
            ->assertHeader(
                'X-Shopify-API-Request-Failure-Reauthorize-Url',
                '/api/auth?shop=loyalty-system.myshopify.com',
            );
    }
}
