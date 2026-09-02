<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ShopifyAdminApiException;
use Illuminate\Http\Request;
use Shopify\Auth\Session as ShopifySession;
use Shopify\Clients\Graphql;
use Shopify\Clients\HttpResponse;
use Shopify\Exception\HttpRequestException;
use Shopify\Utils;
use Throwable;

/**
 * Thin service around Shopify\Clients\Graphql.
 *
 * The official PHP app template instantiates the GraphQL client inline in every
 * controller. That works, but it repeats the "did this actually succeed?"
 * plumbing each time: HTTP status, the GraphQL `errors` array and throttling
 * all have to be checked by hand. This class keeps that in one place so callers
 * get either the `data` payload or a ShopifyAdminApiException.
 *
 * Usage:
 *
 *   // In a controller behind the shopify.auth middleware
 *   $data = ShopifyAdminApi::forRequest($request)->query($query, $variables);
 *
 *   // In a webhook handler, queued job or console command
 *   $data = ShopifyAdminApi::forShop($shop)->query($query);
 */
final class ShopifyAdminApi
{
    /** The request attribute the shopify.auth middleware writes the session to. */
    public const SESSION_ATTRIBUTE = 'shopifySession';

    /** `extensions` block of the most recent response (query cost, throttle status). */
    private ?array $lastExtensions = null;

    public function __construct(private readonly ShopifySession $session) {}

    public static function forSession(ShopifySession $session): self
    {
        return new self($session);
    }

    /**
     * Builds a client from the session attached by the shopify.auth middleware.
     */
    public static function forRequest(Request $request): self
    {
        $session = $request->attributes->get(self::SESSION_ATTRIBUTE);

        if (! $session instanceof ShopifySession || ! $session->getAccessToken()) {
            throw ShopifyAdminApiException::noSessionOnRequest();
        }

        return new self($session);
    }

    /**
     * Builds a client from the stored offline token, for work that happens
     * outside a merchant request (webhooks, jobs, artisan commands).
     */
    public static function forShop(string $shop): self
    {
        $domain = Utils::sanitizeShopDomain($shop);

        if (! $domain) {
            throw new ShopifyAdminApiException("'$shop' is not a valid myshopify domain.", 400);
        }

        $session = Utils::loadOfflineSession($domain);

        if (! $session || ! $session->getAccessToken()) {
            throw ShopifyAdminApiException::noSession($domain);
        }

        return new self($session);
    }

    public function session(): ShopifySession
    {
        return $this->session;
    }

    public function shop(): string
    {
        return $this->session->getShop();
    }

    /**
     * Runs a query or mutation and returns its `data` payload.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     *
     * @throws ShopifyAdminApiException
     */
    public function query(string $query, array $variables = [], ?int $tries = null): array
    {
        $body = $this->queryRaw($query, $variables, $tries);

        return $body['data'] ?? [];
    }

    /**
     * Same as query(), but returns the whole decoded body (`data` + `extensions`).
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     *
     * @throws ShopifyAdminApiException
     */
    public function queryRaw(string $query, array $variables = [], ?int $tries = null): array
    {
        $payload = ['query' => $query];

        if ($variables !== []) {
            $payload['variables'] = $variables;
        }

        $response = $this->send($payload, $tries);
        $body = $response->getDecodedBody();

        if (! is_array($body)) {
            throw new ShopifyAdminApiException(
                'Shopify returned a response that is not valid JSON.',
                502,
                [],
                $this->shop(),
            );
        }

        $this->lastExtensions = $body['extensions'] ?? null;

        $this->assertSuccessful($response->getStatusCode(), $body);

        return $body;
    }

    /**
     * Cost and throttle information from the last call, e.g.
     * ['requestedQueryCost' => 2, 'throttleStatus' => [...]].
     *
     * @return array<string, mixed>|null
     */
    public function lastQueryCost(): ?array
    {
        return $this->lastExtensions['cost'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(array $payload, ?int $tries): HttpResponse
    {
        try {
            return (new Graphql($this->shop(), $this->session->getAccessToken()))
                ->query($payload, tries: $tries);
        } catch (HttpRequestException $e) {
            throw new ShopifyAdminApiException(
                "Could not reach the Shopify Admin API: {$e->getMessage()}",
                504,
                [],
                $this->shop(),
            );
        } catch (ShopifyAdminApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ShopifyAdminApiException(
                "Shopify Admin API call failed: {$e->getMessage()}",
                502,
                [],
                $this->shop(),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function assertSuccessful(int $status, array $body): void
    {
        if ($status === 401 || $status === 403) {
            throw new ShopifyAdminApiException(
                'Shopify rejected the stored access token. The app needs to be reinstalled or reauthorized.',
                401,
                [],
                $this->shop(),
            );
        }

        if ($status === 429) {
            throw new ShopifyAdminApiException(
                'Shopify Admin API rate limit reached.',
                429,
                (array) ($body['errors'] ?? []),
                $this->shop(),
            );
        }

        if ($status !== 200) {
            throw new ShopifyAdminApiException(
                "Shopify Admin API responded with HTTP $status.",
                502,
                (array) ($body['errors'] ?? []),
                $this->shop(),
            );
        }

        if (! empty($body['errors'])) {
            $errors = (array) $body['errors'];

            throw new ShopifyAdminApiException(
                'Shopify Admin API returned GraphQL errors: '.$this->summarize($errors),
                502,
                $errors,
                $this->shop(),
            );
        }
    }

    /**
     * @param  array<int|string, mixed>  $errors
     */
    private function summarize(array $errors): string
    {
        $messages = [];

        foreach ($errors as $error) {
            $messages[] = is_array($error)
                ? ($error['message'] ?? json_encode($error))
                : (string) $error;
        }

        return implode('; ', $messages) ?: 'unknown error';
    }
}
