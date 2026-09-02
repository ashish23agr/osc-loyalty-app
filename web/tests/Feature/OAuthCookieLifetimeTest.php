<?php

namespace Tests\Feature;

use App\Lib\CookieHandler;
use Illuminate\Support\Facades\Cookie;
use Shopify\Auth\OAuthCookie;
use Tests\TestCase;

/**
 * The OAuth state cookie must outlive the consent screen.
 *
 * shopify-api-php gives it 60 seconds. A merchant who actually reads the scope
 * list takes longer, and the callback then fails with CookieNotFoundException -
 * silently, from the merchant's point of view, because they land in a working
 * app having granted nothing.
 */
class OAuthCookieLifetimeTest extends TestCase
{
    private function queueAndRead(int $expiresAt): int
    {
        Cookie::flushQueuedCookies();

        CookieHandler::saveShopifyCookie(
            new OAuthCookie('state-value', 'shopify_app_state', $expiresAt, true, true),
        );

        $queued = Cookie::getQueuedCookies();
        $this->assertCount(1, $queued);

        // Laravel queues an absolute expiry; convert back to whole minutes.
        return (int) round(($queued[0]->getExpiresTime() - time()) / 60);
    }

    public function test_the_libraries_sixty_seconds_is_widened(): void
    {
        config(['shopify.oauth_cookie_minutes' => 10]);

        $this->assertSame(10, $this->queueAndRead(strtotime('+1 minute')));
    }

    public function test_a_longer_library_expiry_is_respected(): void
    {
        // Never shorten what the library asked for.
        config(['shopify.oauth_cookie_minutes' => 10]);

        $this->assertSame(30, $this->queueAndRead(strtotime('+30 minutes')));
    }

    public function test_a_session_cookie_stays_a_session_cookie(): void
    {
        Cookie::flushQueuedCookies();

        CookieHandler::saveShopifyCookie(
            new OAuthCookie('state-value', 'shopify_app_state', 0, true, true),
        );

        $this->assertSame(0, Cookie::getQueuedCookies()[0]->getExpiresTime());
    }
}
