<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown by App\Services\ShopifyAdminApi when a call to the Shopify Admin
 * GraphQL API cannot be made or comes back with errors.
 *
 * Laravel calls render() automatically, so controllers can let it bubble up
 * and still return a clean JSON body.
 */
class ShopifyAdminApiException extends RuntimeException
{
    /**
     * @param  array<int|string, mixed>  $errors  GraphQL / transport errors as returned by Shopify
     */
    public function __construct(
        string $message,
        private readonly int $statusCode = 502,
        private readonly array $errors = [],
        private readonly ?string $shop = null,
    ) {
        parent::__construct($message);
    }

    public static function noSession(string $shop): self
    {
        return new self(
            "No Shopify session with an access token is stored for $shop. The app must complete OAuth first.",
            401,
            [],
            $shop,
        );
    }

    public static function noSessionOnRequest(): self
    {
        return new self(
            'No Shopify session on the request. Protect the route with the shopify.auth middleware.',
            401,
        );
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /** @return array<int|string, mixed> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function shop(): ?string
    {
        return $this->shop;
    }

    /** @return array<string, mixed> Structured payload for Log::error(). */
    public function context(): array
    {
        return [
            'shop' => $this->shop,
            'status' => $this->statusCode,
            'errors' => $this->errors,
        ];
    }

    public function render(Request $request): ?JsonResponse
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        return response()->json([
            'ok' => false,
            'message' => $this->getMessage(),
            'shop' => $this->shop,
            'errors' => $this->errors,
        ], $this->statusCode);
    }
}
