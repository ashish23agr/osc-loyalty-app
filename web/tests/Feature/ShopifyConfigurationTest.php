<?php

namespace Tests\Feature;

use App\Providers\ShopifyServiceProvider;
use Shopify\Webhooks\Topics;
use Tests\TestCase;

/**
 * shopify.app.toml is the source of truth for the Shopify platform, while
 * config/shopify.php is what the Laravel runtime uses. If the two drift apart
 * the symptoms are confusing (OAuth loops, 403s on the Admin API, webhooks that
 * never arrive), so assert they agree.
 */
class ShopifyConfigurationTest extends TestCase
{
    private string $toml;

    protected function setUp(): void
    {
        parent::setUp();

        $path = base_path('../shopify.app.toml');
        $this->assertFileExists($path, 'shopify.app.toml is missing from the project root.');
        $this->toml = file_get_contents($path);
    }

    private function tomlString(string $key): ?string
    {
        return preg_match('/^\s*'.preg_quote($key, '/').'\s*=\s*"([^"]*)"/m', $this->toml, $m)
            ? $m[1]
            : null;
    }

    public function test_access_scopes_match_the_laravel_config(): void
    {
        $this->assertSame(
            $this->tomlString('scopes'),
            config('shopify.scopes'),
            'SCOPES in web/.env must match [access_scopes] scopes in shopify.app.toml.',
        );
    }

    public function test_admin_api_version_matches_the_laravel_config(): void
    {
        $this->assertSame(
            $this->tomlString('api_version'),
            config('shopify.api_version'),
            'SHOPIFY_API_VERSION in web/.env must match [webhooks] api_version in shopify.app.toml.',
        );
    }

    public function test_the_legacy_install_flow_stays_enabled(): void
    {
        // shopify/shopify-api-php v6 has no token exchange, so this app has to
        // keep using classic app-managed OAuth.
        $this->assertMatchesRegularExpression(
            '/^\s*use_legacy_install_flow\s*=\s*true/m',
            $this->toml,
            'use_legacy_install_flow must stay true or the install flow breaks.',
        );
    }

    /** @return list<string> Topics declared in [[webhooks.subscriptions]], e.g. "app/uninstalled". */
    private function declaredTopics(): array
    {
        preg_match_all('/topics\s*=\s*\[([^\]]*)\]/', $this->toml, $blocks);
        preg_match_all('/"([^"]+)"/', implode(',', $blocks[1]), $topics);

        return $topics[1];
    }

    public function test_no_webhook_subscriptions_are_declared_in_the_toml(): void
    {
        $this->assertSame(
            [],
            $this->declaredTopics(),
            'shopify.app.toml declares webhook subscriptions, which cannot be deployed while '
                .'use_legacy_install_flow is true. Register topics through the Admin API instead.',
        );
    }

    public function test_the_business_topics_are_registered_through_the_admin_api(): void
    {
        $topics = config('shopify.webhooks.topics');

        $this->assertContains(Topics::APP_UNINSTALLED, $topics);
        $this->assertContains(Topics::PRODUCTS_UPDATE, $topics);
    }

    /** Every topic with a handler must be delivered by exactly one mechanism. */
    public function test_every_handled_topic_is_covered_exactly_once(): void
    {
        $privacy = [Topics::CUSTOMERS_DATA_REQUEST, Topics::CUSTOMERS_REDACT, Topics::SHOP_REDACT];
        $declared = $this->declaredTopics();
        $registered = config('shopify.webhooks.topics');

        foreach (array_keys(ShopifyServiceProvider::HANDLERS) as $topic) {
            if (in_array($topic, $privacy, true)) {
                continue; // covered by [webhooks.privacy_compliance]
            }

            $isDeclared = in_array(str_replace('_', '/', strtolower($topic)), $declared, true);
            $isRegistered = in_array($topic, $registered, true);

            $this->assertFalse($isDeclared, "'$topic' is declared in the toml, which will not deploy.");
            $this->assertTrue($isRegistered, "'$topic' has a handler but nothing subscribes to it.");
        }
    }

    /** @return array<string, string> Extension handle => declared api_version. */
    private function extensionApiVersions(): array
    {
        $versions = [];

        foreach (glob(base_path('../extensions/*/shopify.extension.toml')) as $path) {
            $toml = file_get_contents($path);
            $handle = basename(dirname($path));

            $versions[$handle] = preg_match('/^\s*api_version\s*=\s*"([^"]*)"/m', $toml, $m)
                ? $m[1]
                : 'not declared';
        }

        return $versions;
    }

    /**
     * An extension pinned to a different version than the Admin API is a
     * runtime difference between surfaces that nothing else surfaces: the
     * scaffolds shipped at 2026-01 and 2026-07 respectively (V8).
     */
    public function test_extension_api_versions_match_the_admin_api_version(): void
    {
        $expected = config('shopify.api_version');
        $versions = $this->extensionApiVersions();

        $this->assertNotEmpty($versions, 'No extension manifests were found to check.');

        foreach ($versions as $handle => $version) {
            $this->assertSame(
                $expected,
                $version,
                "Extension '$handle' declares api_version '$version' but the Admin API is on '$expected'.",
            );
        }
    }

    /**
     * The discount function scaffold also declared
     * cart.delivery-options.discounts.generate.run, which no Blueprint module
     * uses. An unused entry point reads as load-bearing to the next person, so
     * the removal is asserted rather than trusted.
     */
    public function test_the_discount_function_declares_only_the_cart_lines_target(): void
    {
        $path = base_path('../extensions/voucher-discount/shopify.extension.toml');
        $this->assertFileExists($path);

        preg_match_all('/^\s*target\s*=\s*"([^"]+)"/m', file_get_contents($path), $matches);

        $this->assertSame(
            ['cart.lines.discounts.generate.run'],
            $matches[1],
            'The voucher discount function must target cart lines only.',
        );
    }

    public function test_privacy_compliance_webhooks_are_declared(): void
    {
        foreach (['customer_deletion_url', 'customer_data_request_url', 'shop_deletion_url'] as $key) {
            $this->assertSame(
                config('shopify.webhooks.path'),
                $this->tomlString($key),
                "Mandatory GDPR webhook '$key' is not pointed at the webhook endpoint.",
            );
        }
    }
}
