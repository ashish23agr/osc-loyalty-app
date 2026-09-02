<?php

namespace App\Domain\Redemption;

use App\Exceptions\ShopifyAdminApiException;
use App\Services\ShopifyAdminApi;
use Illuminate\Support\Facades\Log;

/**
 * The real product catalogue, paged through the Admin API (V6a).
 *
 * Needs `read_products`. Pages with a cursor rather than an offset, because a
 * catalogue that changes mid-sync would silently skip or repeat products under
 * offset paging.
 */
final class AdminApiProductCatalogue implements ProductCatalogue
{
    private const PRODUCTS = <<<'GRAPHQL'
    query LoyaltyProducts($cursor: String) {
      products(first: 100, after: $cursor) {
        pageInfo { hasNextPage endCursor }
        nodes {
          id
          tags
          collections(first: 50) { nodes { handle } }
        }
      }
    }
    GRAPHQL;

    public function each(string $shopDomain): iterable
    {
        $cursor = null;

        do {
            try {
                $data = ShopifyAdminApi::forShop($shopDomain)
                    ->query(self::PRODUCTS, ['cursor' => $cursor]);
            } catch (ShopifyAdminApiException $e) {
                // Stopping is right: a partial catalogue read would leave some
                // products carrying a stale flag with no record of which.
                Log::warning('Could not page the product catalogue for the loyalty sync', [
                    'shop' => $shopDomain,
                    'cursor' => $cursor,
                    'error' => $e->getMessage(),
                ]);

                return;
            }

            $page = $data['products'] ?? [];

            foreach ($page['nodes'] ?? [] as $node) {
                yield [
                    'gid' => (string) $node['id'],
                    'tags' => array_map('strval', $node['tags'] ?? []),
                    'collections' => array_map(
                        fn (array $c): string => (string) ($c['handle'] ?? ''),
                        $node['collections']['nodes'] ?? [],
                    ),
                ];
            }

            $cursor = ($page['pageInfo']['hasNextPage'] ?? false)
                ? ($page['pageInfo']['endCursor'] ?? null)
                : null;
        } while ($cursor !== null);
    }
}
