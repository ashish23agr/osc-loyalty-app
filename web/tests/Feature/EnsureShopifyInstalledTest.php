<?php

namespace Tests\Feature;

use App\Models\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The middleware must ask the same question the Admin API layer asks.
 *
 * When it asked a weaker one - "does a row with a token exist?" - a shop whose
 * stored scopes had gone stale was served normally while every Admin API call
 * threw "must complete OAuth first", and no request ever started the re-grant
 * that would have fixed it. These lock that behaviour down.
 */
class EnsureShopifyInstalledTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'loyalty-system.myshopify.com';

    private function storeGrant(string $scope): Session
    {
        return Session::create([
            'session_id' => 'offline_'.self::SHOP,
            'shop' => self::SHOP,
            'is_online' => false,
            'access_token' => 'shpat_test_token',
            'scope' => $scope,
        ]);
    }

    public function test_a_current_grant_is_served(): void
    {
        $this->storeGrant(config('shopify.scopes'));

        $this->get('/anything?shop='.self::SHOP)->assertOk();
    }

    public function test_a_stale_grant_is_sent_back_through_oauth(): void
    {
        // The exact state the dev store was wedged in: a valid token, but only
        // the scopes granted before the list grew.
        $this->storeGrant('read_products');

        $response = $this->get('/anything?shop='.self::SHOP);

        $response->assertRedirect();
        $this->assertStringContainsString(
            'oauth/authorize',
            $response->headers->get('Location'),
            'A stale grant must start a fresh OAuth grant, not be served as installed.',
        );
    }

    public function test_a_stale_grant_is_never_silent(): void
    {
        $this->storeGrant('read_products');

        Log::shouldReceive('info')
            ->once()
            ->withArgs(fn (string $message, array $context) => str_contains($message, 'no longer usable')
                && $context['stored_scope'] === 'read_products');

        $this->get('/anything?shop='.self::SHOP);
    }

    public function test_a_shop_that_never_installed_redirects_without_a_stale_grant_line(): void
    {
        Log::shouldReceive('info')->never();

        $this->get('/anything?shop='.self::SHOP)->assertRedirect();
    }

    public function test_a_request_without_a_shop_is_passed_through(): void
    {
        // Nothing to authorise against, so the route decides what to render.
        $this->get('/anything')->assertOk();
    }
}
