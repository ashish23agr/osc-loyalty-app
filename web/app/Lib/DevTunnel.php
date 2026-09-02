<?php

declare(strict_types=1);

namespace App\Lib;

use Illuminate\Support\Carbon;
use JsonException;

/**
 * Resolves the credentials and public tunnel URL that `shopify app dev` holds.
 *
 * `shopify app dev` injects HOST, SHOPIFY_API_KEY, SHOPIFY_API_SECRET and
 * SCOPES into the backend process it spawns, and nowhere else. A plain
 * `php artisan` shell inherits none of it, so `shopify:webhooks --register`
 * saw app URL http://localhost:8000 and refused - correctly, because
 * registering from there would point Shopify at a private address and silently
 * break every delivery.
 *
 * Exporting the values by hand is not a fix: the tunnel URL changes on every
 * `shopify app dev` restart, so a hand-exported HOST is wrong the moment the
 * CLI is restarted, and wrong in a way that looks like it worked.
 *
 * Two sources, in order:
 *
 *  1. The handshake file storage/app/dev-tunnel.json, written by serve.mjs the
 *     moment the CLI starts the backend and deleted when it stops. This is the
 *     only source carrying the API secret, and its presence is the closest
 *     thing available to "the tunnel is live right now".
 *  2. ../.shopify/dev-bundle/manifest.json, which the CLI rewrites on every dev
 *     start. It carries the URL but no secret, and it survives the CLI exiting,
 *     so it is a fallback and is always reported as possibly stale.
 *
 * Only ever consulted in the local environment. Production takes its host and
 * credentials from the real environment, as it should.
 */
class DevTunnel
{
    /** @var array<string, mixed>|null */
    private static ?array $handshake = null;

    private static bool $handshakeLoaded = false;

    public static function handshakePath(): string
    {
        return (string) config('shopify.dev_tunnel_file') ?: storage_path('app/dev-tunnel.json');
    }

    public static function manifestPath(): string
    {
        return base_path('../.shopify/dev-bundle/manifest.json');
    }

    /**
     * The public HTTPS URL Shopify should call back on, or null if the CLI has
     * not run here.
     */
    public static function host(): ?string
    {
        $host = self::handshakeValue('host') ?? self::manifestHost();

        return self::isPublicUrl($host) ? rtrim($host, '/') : null;
    }

    public static function apiKey(): ?string
    {
        return self::handshakeValue('api_key');
    }

    public static function apiSecret(): ?string
    {
        return self::handshakeValue('api_secret');
    }

    public static function scopes(): ?string
    {
        return self::handshakeValue('scopes');
    }

    /**
     * Which of the two sources answered, for the human reading the output.
     * 'handshake' means the CLI backend is running; 'manifest' means it ran at
     * some point and may not be running now.
     */
    public static function source(): ?string
    {
        if (self::isPublicUrl(self::handshakeValue('host'))) {
            return 'handshake';
        }

        return self::isPublicUrl(self::manifestHost()) ? 'manifest' : null;
    }

    /** When serve.mjs recorded the handshake, if it did. */
    public static function startedAt(): ?Carbon
    {
        $at = self::handshakeValue('started_at');

        return $at ? Carbon::parse($at) : null;
    }

    /**
     * True when `shopify app dev` is running its backend against this checkout.
     * Scope grants and webhook registration only succeed while that is true.
     */
    public static function isLive(): bool
    {
        return self::source() === 'handshake';
    }

    /** A one-line explanation of where the values came from, for command output. */
    public static function describe(): string
    {
        return match (self::source()) {
            'handshake' => sprintf(
                'from the running `shopify app dev` backend (recorded %s)',
                self::startedAt()?->diffForHumans() ?? 'at an unknown time',
            ),
            'manifest' => 'from .shopify/dev-bundle/manifest.json - `shopify app dev` may no longer be running',
            default => 'unavailable: `shopify app dev` has not run against this checkout',
        };
    }

    private static function manifestHost(): ?string
    {
        $manifest = self::readJson(self::manifestPath());

        foreach ($manifest['modules'] ?? [] as $module) {
            if (($module['type'] ?? null) === 'app_home') {
                return $module['config']['app_url'] ?? null;
            }
        }

        return null;
    }

    private static function handshakeValue(string $key): ?string
    {
        if (! self::$handshakeLoaded) {
            self::$handshake = self::readJson(self::handshakePath());
            self::$handshakeLoaded = true;
        }

        $value = self::$handshake[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array<string, mixed>|null */
    private static function readJson(string $path): ?array
    {
        if (! is_readable($path)) {
            return null;
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // A half-written file during a CLI restart is not worth an
            // exception in a service provider.
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /** Shopify will only accept a public HTTPS callback. */
    public static function isPublicUrl(?string $url): bool
    {
        return is_string($url)
            && str_starts_with($url, 'https://')
            && ! str_contains($url, 'localhost')
            && ! str_contains($url, '127.0.0.1');
    }

    /**
     * Record what this process knows, so shells the CLI did not spawn can use it.
     *
     * serve.mjs writes the handshake first, but its lifetime is tied to one
     * backend process and the CLI is free to restart that. Every Laravel boot
     * under the CLI has the same values in its own environment, and the dev
     * server bootstraps the framework per request, so refreshing here makes the
     * handshake self-healing: one request to the backend restores it.
     *
     * An existing `pid` is preserved, because serve.mjs uses it to decide
     * whether the file is still its own to delete.
     */
    public static function record(string $host, string $apiKey, string $apiSecret, ?string $scopes = null): void
    {
        $path = self::handshakePath();
        $existing = self::readJson($path) ?? [];

        $payload = json_encode([
            'host' => rtrim($host, '/'),
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
            'scopes' => $scopes ?? ($existing['scopes'] ?? ''),
            'backend_port' => $existing['backend_port'] ?? '',
            'pid' => $existing['pid'] ?? null,
            'started_at' => $existing['started_at'] ?? now()->toIso8601String(),
            'refreshed_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT);

        if ($payload === false) {
            return;
        }

        // Unchanged content is the common case (every request), so skip the
        // write rather than churn the file the CLI process may be reading.
        if (is_readable($path) && file_get_contents($path) === $payload) {
            return;
        }

        if (! is_dir(dirname($path))) {
            @mkdir(dirname($path), 0755, true);
        }

        // Best effort. A dev convenience must never break a request.
        if (@file_put_contents($path, $payload, LOCK_EX) !== false) {
            @chmod($path, 0600);
            self::flush();
        }
    }

    /** Tests only: forget the cached handshake. */
    public static function flush(): void
    {
        self::$handshake = null;
        self::$handshakeLoaded = false;
    }
}
