<?php

namespace Tests\Feature;

use App\Lib\DevTunnel;
use Tests\TestCase;

/**
 * The tunnel URL changes on every `shopify app dev` restart, and a wrong one
 * registers webhooks that Shopify accepts and never delivers. These cover the
 * two sources and, more importantly, the refusals.
 */
class DevTunnelTest extends TestCase
{
    private string $handshake;

    protected function setUp(): void
    {
        parent::setUp();

        // NEVER the real path. This test unlinks the file, and the real one is
        // the live credentials of a running `shopify app dev` - deleting it
        // mid-session breaks every artisan command until a request rewrites it.
        config(['shopify.dev_tunnel_file' => sys_get_temp_dir().'/dev-tunnel-test-'.getmypid().'.json']);

        $this->handshake = DevTunnel::handshakePath();
        $this->assertStringNotContainsString('storage', $this->handshake);

        @unlink($this->handshake);
        DevTunnel::flush();
    }

    protected function tearDown(): void
    {
        @unlink($this->handshake);
        DevTunnel::flush();

        parent::tearDown();
    }

    private function writeHandshake(array $values): void
    {
        @mkdir(dirname($this->handshake), 0755, true);
        file_put_contents($this->handshake, json_encode($values));
        DevTunnel::flush();
    }

    public function test_it_reads_the_tunnel_and_credentials_from_the_handshake(): void
    {
        $this->writeHandshake([
            'host' => 'https://lookup-maiden-pontiac-integrate.trycloudflare.com/',
            'api_key' => 'key-from-cli',
            'api_secret' => 'secret-from-cli',
            'started_at' => now()->toIso8601String(),
        ]);

        // The trailing slash is dropped: it is concatenated with a path later.
        $this->assertSame('https://lookup-maiden-pontiac-integrate.trycloudflare.com', DevTunnel::host());
        $this->assertSame('key-from-cli', DevTunnel::apiKey());
        $this->assertSame('secret-from-cli', DevTunnel::apiSecret());
        $this->assertSame('handshake', DevTunnel::source());
        $this->assertTrue(DevTunnel::isLive());
    }

    public function test_it_falls_back_to_the_dev_bundle_manifest_without_claiming_the_tunnel_is_live(): void
    {
        if (! is_readable(DevTunnel::manifestPath())) {
            $this->markTestSkipped('No dev-bundle manifest; `shopify app dev` has not run in this checkout.');
        }

        $this->assertSame('manifest', DevTunnel::source());
        $this->assertStringStartsWith('https://', (string) DevTunnel::host());

        // The manifest outlives the CLI, so it must never be reported as live.
        $this->assertFalse(DevTunnel::isLive());
    }

    public function test_it_refuses_a_private_or_plaintext_url(): void
    {
        $this->assertFalse(DevTunnel::isPublicUrl('http://localhost:8000'));
        $this->assertFalse(DevTunnel::isPublicUrl('https://localhost:8000'));
        $this->assertFalse(DevTunnel::isPublicUrl('https://127.0.0.1:8000'));
        $this->assertFalse(DevTunnel::isPublicUrl('http://example.trycloudflare.com'));
        $this->assertFalse(DevTunnel::isPublicUrl(null));
        $this->assertTrue(DevTunnel::isPublicUrl('https://example.trycloudflare.com'));
    }

    public function test_a_handshake_without_a_usable_host_is_ignored(): void
    {
        $this->writeHandshake(['host' => 'http://localhost:8000', 'api_secret' => 'secret-from-cli']);

        $this->assertNotSame('handshake', DevTunnel::source());
        $this->assertFalse(DevTunnel::isLive());
    }

    public function test_a_half_written_handshake_does_not_throw(): void
    {
        @mkdir(dirname($this->handshake), 0755, true);
        file_put_contents($this->handshake, '{"host": "https://example.trycl');
        DevTunnel::flush();

        $this->assertNull(DevTunnel::apiSecret());
        $this->assertIsString(DevTunnel::describe());
    }
}
