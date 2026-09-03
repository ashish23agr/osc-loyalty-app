<?php

declare(strict_types=1);

namespace App\Domain\Shop;

use App\Exceptions\ShopifyAdminApiException;
use App\Services\ShopifyAdminApi;
use Illuminate\Support\Facades\Log;

/**
 * Reads `shop { currencyCode }` live, every time it is asked (V13).
 *
 * **Deliberately not cached, and this is the whole design.** The failure V13
 * guards against is the shop currency changing without the programme knowing:
 * on 3 Sep 2026 the development store went GBP to EUR and back inside
 * twenty-four hours, and a `£50` voucher was minted as `€50` in between. A
 * cache would have held the stale answer through precisely the window that
 * matters, so the guard would have passed while being wrong — which is worse
 * than no guard, because it would carry the authority of having checked.
 *
 * The cost is one small Admin API query per mint. Minting is a human action at
 * a checkout or a till, not a loop, so this is the cheap side of the trade.
 *
 * Needs no scope of its own: `shop { currencyCode }` is readable under the
 * scopes this app already holds, verified against the live store 3 Sep 2026.
 */
final class AdminApiShopCurrency implements ShopCurrency
{
    private const QUERY = '{ shop { currencyCode } }';

    public function for(string $shopDomain): ?string
    {
        try {
            $data = ShopifyAdminApi::forShop($shopDomain)->query(self::QUERY);
        } catch (ShopifyAdminApiException $e) {
            // Not rethrown: the caller's job is to refuse to mint, not to put a
            // stack trace in front of someone standing at a till. Logged at
            // warning because it stops a redemption a member was promised.
            Log::warning('The shop currency could not be read, so no voucher can be minted', [
                'shop' => $shopDomain,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $code = $data['shop']['currencyCode'] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }
}
