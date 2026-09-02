<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\ShopifyAdminApiException;
use App\Lib\DevTunnel;
use App\Lib\ShopifyContext;
use App\Models\Session;
use App\Services\ShopifyAdminApi;
use Illuminate\Console\Command;
use Shopify\Webhooks\Registry;
use Throwable;

/**
 * Inspect (and if needed re-register) this app's webhook subscriptions.
 *
 * `shopify app dev` re-resolves the topics declared in shopify.app.toml against
 * the current tunnel, but only a fresh OAuth grant re-runs the per-shop
 * registration in ShopifyAuthController. This command closes that gap and makes
 * it possible to confirm what Shopify actually has on file.
 */
class ShopifyWebhooks extends Command
{
    protected $signature = 'shopify:webhooks
        {--shop= : The myshopify domain (defaults to the only installed shop)}
        {--register : (Re)register the topics from config(shopify.webhooks.topics)}';

    protected $description = "List or register this app's Shopify webhook subscriptions";

    private const SUBSCRIPTIONS_QUERY = <<<'GRAPHQL'
    query AppWebhookSubscriptions {
      webhookSubscriptions(first: 100) {
        edges {
          node {
            id
            topic
            createdAt
            uri
          }
        }
      }
    }
    GRAPHQL;

    public function handle(): int
    {
        // Credentials normally come from `shopify app dev`; a plain artisan
        // shell has none, and the library would throw from deep inside Utils.
        if (! ShopifyContext::isInitialized()) {
            $this->error('Shopify credentials are not configured, so the Admin API cannot be reached.');
            $this->line('Dev credentials and the tunnel URL come from `shopify app dev`: '.DevTunnel::describe());
            $this->line('Start it in another terminal and run this command again - no manual exports needed.');

            return self::FAILURE;
        }

        $shop = $this->resolveShop();

        if (! $shop) {
            return self::FAILURE;
        }

        try {
            if ($this->option('register') && ! $this->register($shop)) {
                return self::FAILURE;
            }

            return $this->list($shop);
        } catch (ShopifyAdminApiException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function resolveShop(): ?string
    {
        if ($shop = $this->option('shop')) {
            return $shop;
        }

        $shops = Session::whereNotNull('access_token')->distinct()->pluck('shop');

        if ($shops->count() === 1) {
            return $shops->first();
        }

        $this->error($shops->isEmpty()
            ? 'No shop has installed this app yet. Run the OAuth flow first.'
            : 'Several shops are installed. Pick one with --shop: '.$shops->implode(', '));

        return null;
    }

    private function register(string $shop): bool
    {
        $host = (string) config('shopify.host');

        // A subscription is created against the app URL the backend currently
        // believes in. Registering from a plain `php artisan` shell would point
        // Shopify at localhost, which silently breaks delivery.
        if (! DevTunnel::isPublicUrl($host)) {
            $this->error("Refusing to register: the app URL is '$host'.");
            $this->line('Shopify needs a public HTTPS URL, and only `shopify app dev` knows the current one.');
            $this->line('Tunnel lookup says: '.DevTunnel::describe());
            $this->line('Start `shopify app dev` in another terminal, then re-run this command.');

            return false;
        }

        $this->line("Registering against <info>$host</info> (".DevTunnel::describe().')');

        // The manifest survives the CLI exiting, so its URL can name a tunnel
        // that is already dead. Registering against a dead tunnel succeeds and
        // then silently drops every delivery, which is the failure this whole
        // mechanism exists to prevent.
        if (DevTunnel::source() === 'manifest') {
            $this->warn('That URL came from the last dev-bundle manifest, not from a running backend.');
            $this->line('If `shopify app dev` is not up right now, stop and start it - the URL will have changed.');
        }

        $this->newLine();

        $topics = (array) config('shopify.webhooks.topics', []);

        if ($topics === []) {
            $this->warn('config(shopify.webhooks.topics) is empty, so there is nothing to register.');
            $this->line('That is intentional: app/uninstalled and products/update are declared in');
            $this->line('shopify.app.toml, and registering them here would duplicate every delivery.');
            $this->newLine();

            return true;
        }

        $api = ShopifyAdminApi::forShop($shop);
        $path = (string) config('shopify.webhooks.path', '/api/webhooks');

        foreach ($topics as $topic) {
            try {
                $response = Registry::register($path, $topic, $shop, $api->session()->getAccessToken());

                $response->isSuccess()
                    ? $this->info("Registered $topic -> $host$path")
                    : $this->error("Failed to register $topic: ".json_encode($response->getBody()));
            } catch (Throwable $e) {
                $this->error("Failed to register $topic: {$e->getMessage()}");
            }
        }

        return true;
    }

    private function list(string $shop): int
    {
        $api = ShopifyAdminApi::forShop($shop);
        $edges = $api->query(self::SUBSCRIPTIONS_QUERY)['webhookSubscriptions']['edges'] ?? [];
        $appUrl = rtrim((string) config('shopify.host'), '/');

        $this->line("Shop-scoped subscriptions Shopify holds for <info>$shop</info>");
        $this->line('(created through the Admin API, i.e. by ShopifyAuthController after OAuth)');
        $this->newLine();

        if ($edges === []) {
            $this->line('  (none)');
        }

        $stale = 0;

        foreach ($edges as $edge) {
            $node = $edge['node'];
            // `endpoint`/`callbackUrl` are deprecated as of 2026-07 in favour of `uri`.
            $url = $node['uri'] ?? '?';
            $isStale = $appUrl !== '' && str_starts_with($url, 'http') && ! str_starts_with($url, $appUrl);
            $stale += (int) $isStale;

            $this->line(sprintf('  %-24s %s%s', $node['topic'], $url, $isStale ? '  <comment>[stale]</comment>' : ''));
        }

        $this->newLine();
        $this->line('Topics declared in shopify.app.toml are managed by the app version, NOT by the');
        $this->line('Admin API, so they do not appear above even when they are live. Confirm those');
        $this->line('in .shopify/dev-bundle/manifest.json (during `shopify app dev`) or by triggering');
        $this->line('a real event and watching storage/logs/laravel.log.');

        if ($stale > 0) {
            $this->newLine();
            $this->warn("$stale subscription(s) still point at an old address, not $appUrl.");
            $this->line('Left over from a previous tunnel. Re-run with --register to move them.');
        }

        return self::SUCCESS;
    }
}
