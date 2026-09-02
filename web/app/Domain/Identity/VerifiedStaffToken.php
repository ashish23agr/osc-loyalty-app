<?php

namespace App\Domain\Identity;

/**
 * The trustworthy parts of a verified session token.
 *
 * Constructed only by SessionTokenVerifier, after the checks that the JWT
 * library does not perform have been made.
 */
final readonly class VerifiedStaffToken
{
    public function __construct(
        public string $shopDomain,
        public int $staffUserId,
        public ?string $sessionId,
        public array $claims,
    ) {}
}
