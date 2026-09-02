<?php

namespace App\Domain\Identity;

use Illuminate\Http\Request;
use RuntimeException;
use Shopify\Utils;
use Throwable;

/**
 * Verifies an App Bridge session token and extracts the staff identity from it.
 *
 * Utils::decodeSessionToken checks the signature against the app secret, checks
 * exp and nbf, and rejects an alg:none downgrade. Validation V3 established that
 * it does NOT check two things that matter, so this class checks them:
 *
 *   - `aud` must equal this app client id. Without that check, a token minted
 *     for a different app but signed with a shared secret would be honoured.
 *   - `dest` names the shop. It is the only trustworthy source of the shop for
 *     an authenticated request; a `shop` query parameter is caller-supplied and
 *     must never be preferred to it.
 *
 * The same token shape is used by the POS and customer account surfaces, so this
 * verifier is reused there in sprint 3 with a different subject meaning.
 */
class SessionTokenVerifier
{
    /**
     * @throws RuntimeException when there is no usable token.
     */
    public function verify(Request $request): VerifiedStaffToken
    {
        $token = $this->bearerToken($request);

        if ($token === null) {
            throw new RuntimeException('No session token was supplied.');
        }

        try {
            $claims = Utils::decodeSessionToken($token);
        } catch (Throwable $e) {
            // Signature, expiry, not-before and algorithm failures all land
            // here. The reason is deliberately not echoed to the client.
            throw new RuntimeException('The session token is not valid.', previous: $e);
        }

        $this->assertAudience($claims);

        $shop = $this->shopFromDestination($claims);
        $staffUserId = $this->staffUserId($claims);

        return new VerifiedStaffToken(
            shopDomain: $shop,
            staffUserId: $staffUserId,
            sessionId: isset($claims['sid']) ? (string) $claims['sid'] : null,
            claims: $claims,
        );
    }

    /** Null rather than an exception, for callers that treat absence as anonymous. */
    public function tryVerify(Request $request): ?VerifiedStaffToken
    {
        try {
            return $this->verify($request);
        } catch (RuntimeException) {
            return null;
        }
    }

    private function bearerToken(Request $request): ?string
    {
        $header = (string) $request->header('Authorization');

        return preg_match('/^Bearer\s+(\S+)$/i', $header, $matches) === 1
            ? $matches[1]
            : null;
    }

    private function assertAudience(array $claims): void
    {
        $expected = (string) config('shopify.api_key');

        if ($expected === '') {
            throw new RuntimeException('The app client id is not configured, so no token can be trusted.');
        }

        if (! isset($claims['aud']) || ! hash_equals($expected, (string) $claims['aud'])) {
            throw new RuntimeException('The session token was issued for a different app.');
        }
    }

    private function shopFromDestination(array $claims): string
    {
        $host = isset($claims['dest'])
            ? parse_url((string) $claims['dest'], PHP_URL_HOST)
            : null;

        if (! $host) {
            throw new RuntimeException('The session token carries no destination shop.');
        }

        return Utils::sanitizeShopDomain($host);
    }

    /**
     * The subject is the staff member for an admin token.
     *
     * Shopify sends it as a numeric string. Anything non-numeric means this is
     * not the token shape we think it is, which is worth refusing rather than
     * coercing to zero and treating every such caller as the same person.
     */
    private function staffUserId(array $claims): int
    {
        $sub = $claims['sub'] ?? null;

        if (! is_numeric($sub)) {
            throw new RuntimeException('The session token carries no usable staff identity.');
        }

        return (int) $sub;
    }
}
