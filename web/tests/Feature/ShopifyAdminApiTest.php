<?php

namespace Tests\Feature;

use App\Exceptions\ShopifyAdminApiException;
use App\Models\Session;
use App\Services\ShopifyAdminApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Shopify\Auth\Session as ShopifySession;
use Tests\TestCase;

class ShopifyAdminApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_a_client_from_the_session_on_the_request(): void
    {
        $session = new ShopifySession('offline_shop.myshopify.com', 'shop.myshopify.com', false, 'state');
        $session->setAccessToken('shpat_test_token');

        $request = Request::create('/api/shop');
        $request->attributes->set(ShopifyAdminApi::SESSION_ATTRIBUTE, $session);

        $this->assertSame('shop.myshopify.com', ShopifyAdminApi::forRequest($request)->shop());
    }

    public function test_it_refuses_a_request_that_carries_no_session(): void
    {
        $this->expectException(ShopifyAdminApiException::class);

        ShopifyAdminApi::forRequest(Request::create('/api/shop'));
    }

    public function test_it_loads_the_stored_offline_session_for_a_shop(): void
    {
        Session::create([
            'session_id' => 'offline_loyalty-system.myshopify.com',
            'shop' => 'loyalty-system.myshopify.com',
            'is_online' => false,
            'access_token' => 'shpat_test_token',
            'scope' => config('shopify.scopes'),
        ]);

        $api = ShopifyAdminApi::forShop('loyalty-system.myshopify.com');

        $this->assertSame('loyalty-system.myshopify.com', $api->shop());
        $this->assertSame('shpat_test_token', $api->session()->getAccessToken());
    }

    public function test_it_rejects_a_shop_that_has_never_installed_the_app(): void
    {
        try {
            ShopifyAdminApi::forShop('never-installed.myshopify.com');
            $this->fail('Expected a ShopifyAdminApiException.');
        } catch (ShopifyAdminApiException $e) {
            $this->assertSame(401, $e->statusCode());
            $this->assertSame('never-installed.myshopify.com', $e->shop());
        }
    }

    public function test_it_rejects_an_invalid_shop_domain(): void
    {
        try {
            ShopifyAdminApi::forShop('https://evil.example.com');
            $this->fail('Expected a ShopifyAdminApiException.');
        } catch (ShopifyAdminApiException $e) {
            $this->assertSame(400, $e->statusCode());
        }
    }

    public function test_the_exception_renders_itself_as_json_for_api_routes(): void
    {
        $response = (new ShopifyAdminApiException('boom', 502, [['message' => 'bad field']], 'shop.myshopify.com'))
            ->render(Request::create('/api/shop'));

        $this->assertSame(502, $response->getStatusCode());
        $this->assertSame(
            ['ok' => false, 'message' => 'boom', 'shop' => 'shop.myshopify.com', 'errors' => [['message' => 'bad field']]],
            $response->getData(true),
        );
    }
}
