<?php

namespace Tests\Feature\Admin;

use App\Domain\Identity\StaffRoleResolver;
use App\Domain\Loyalty\LedgerPosting;
use App\Domain\Loyalty\LedgerService;
use App\Domain\Rules\RulesVersionRepository;
use App\Models\LedgerEntry;
use App\Models\LoyaltyAccount;
use App\Models\StaffRole;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shared setup for the admin console API tests.
 *
 * Every test in this namespace calls the real routes with a real session token
 * through the real middleware, because the thing being tested is the endpoint
 * contract the React app and the POS tile will branch on — status codes, error
 * codes and payload shape — and a controller called directly would test none of
 * it.
 */
abstract class AdminApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected const SHOP = 'loyalty-system.myshopify.com';

    /** The Administrator that configures the shop, so the C6 bootstrap cannot
     *  fire for whichever staff member a test is actually about. */
    protected const OWNER = 100000001;

    protected function setUp(): void
    {
        parent::setUp();

        app(RulesVersionRepository::class)->seedDefaults(self::SHOP);
        app(StaffRoleResolver::class)->resolve(self::SHOP, self::OWNER);
    }

    protected function tokenFor(int $staffUserId, array $overrides = []): string
    {
        $now = time();

        return JWT::encode(array_merge([
            'iss' => 'https://'.self::SHOP.'/admin',
            'dest' => 'https://'.self::SHOP,
            'aud' => config('shopify.api_key'),
            'sub' => (string) $staffUserId,
            'exp' => $now + 60,
            'nbf' => $now - 10,
            'iat' => $now - 10,
            'jti' => bin2hex(random_bytes(8)),
        ], $overrides), config('shopify.api_secret'), 'HS256');
    }

    /** @return array<string, string> */
    protected function asStaff(int $staffUserId, array $headers = []): array
    {
        return ['Authorization' => 'Bearer '.$this->tokenFor($staffUserId)] + $headers;
    }

    /** A staff member holding exactly this role, and their auth headers. */
    protected function staffWith(string $role, int $staffUserId, ?int $adjustmentLimit = null): StaffRole
    {
        return app(StaffRoleResolver::class)->assign(
            shopDomain: self::SHOP,
            staffUserId: $staffUserId,
            role: $role,
            name: ucfirst($role).' '.$staffUserId,
            adjustmentLimitPoints: $adjustmentLimit,
        );
    }

    /** Auth headers for a freshly created staff member of the given role. */
    protected function headersFor(string $role, int $staffUserId, ?int $adjustmentLimit = null): array
    {
        $this->staffWith($role, $staffUserId, $adjustmentLimit);

        return $this->asStaff($staffUserId);
    }

    protected function member(array $attributes = []): LoyaltyAccount
    {
        return LoyaltyAccount::create(array_merge([
            'shop_domain' => self::SHOP,
            'email' => 'member'.uniqid().'@example.com',
            'first_name' => 'Test',
            'last_name' => 'Member',
            'enrolment_channel' => 'online',
            'enrolled_at' => now(),
        ], $attributes));
    }

    /**
     * Give a member available points the honest way: earn, then mature.
     *
     * Nothing in these tests writes a balance directly, because a balance that
     * was set rather than posted would not exercise the path every real balance
     * takes and would hide a cache that had stopped agreeing with the ledger.
     */
    protected function withAvailablePoints(LoyaltyAccount $member, int $points, string $key = 'seed'): LedgerEntry
    {
        $ledger = app(LedgerService::class);

        $earn = $ledger->post($member, LedgerPosting::earn(
            points: $points,
            idempotencyKey: 'earn:'.$key.':'.$member->id,
            occurredAt: now()->subDays(31),
            maturesAt: now()->subDay(),
            expiresAt: now()->addDays(151),
            shopifyOrderId: 900000 + (int) $member->id,
            orderName: '#'.(900000 + (int) $member->id),
            qualifyingValuePence: $points * 100,
        ));

        $maturity = $ledger->post($member, LedgerPosting::maturity(
            points: $points,
            parentEntryId: (int) $earn->id,
            idempotencyKey: 'mature:'.$key.':'.$earn->id,
            occurredAt: now()->subDay(),
        ));

        $member->refresh();

        return $maturity;
    }
}
