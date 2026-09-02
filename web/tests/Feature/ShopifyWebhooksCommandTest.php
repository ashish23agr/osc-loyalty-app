<?php

namespace Tests\Feature;

use App\Models\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopifyWebhooksCommandTest extends TestCase
{
    use RefreshDatabase;

    private function installShop(string $shop): void
    {
        Session::create([
            'session_id' => "offline_$shop",
            'shop' => $shop,
            'is_online' => false,
            'access_token' => 'shpat_test_token',
            'scope' => config('shopify.scopes'),
        ]);
    }

    public function test_it_refuses_to_register_against_a_non_public_app_url(): void
    {
        $this->installShop('loyalty-system.myshopify.com');
        config(['shopify.host' => 'http://localhost:8000']);

        $this->artisan('shopify:webhooks --register')
            ->expectsOutputToContain("Refusing to register: the app URL is 'http://localhost:8000'.")
            ->assertFailed();
    }

    public function test_it_reports_when_no_shop_has_installed_the_app(): void
    {
        $this->artisan('shopify:webhooks')
            ->expectsOutputToContain('No shop has installed this app yet.')
            ->assertFailed();
    }

    public function test_it_asks_which_shop_when_several_are_installed(): void
    {
        $this->installShop('loyalty-system.myshopify.com');
        $this->installShop('other-store.myshopify.com');

        $this->artisan('shopify:webhooks')
            ->expectsOutputToContain('Several shops are installed.')
            ->assertFailed();
    }
}
