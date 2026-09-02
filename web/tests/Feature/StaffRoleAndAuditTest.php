<?php

namespace Tests\Feature;

use App\Domain\Identity\LastAdministratorException;
use App\Domain\Identity\SessionTokenVerifier;
use App\Domain\Identity\StaffRoleResolver;
use App\Models\AuditEntry;
use App\Models\StaffRole;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\RequestContext;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Roles govern who may move money, and the audit log records who did. Both are
 * part of the specification rather than settings discovered later, so the rules
 * in Blueprint section 12 are asserted here directly.
 */
class StaffRoleAndAuditTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'loyalty-system.myshopify.com';

    private const OWNER = 112233445566;

    private function token(array $overrides = [], ?string $secret = null): string
    {
        $now = time();

        return JWT::encode(array_merge([
            'iss' => 'https://'.self::SHOP.'/admin',
            'dest' => 'https://'.self::SHOP,
            'aud' => config('shopify.api_key'),
            'sub' => (string) self::OWNER,
            'exp' => $now + 60,
            'nbf' => $now - 10,
            'iat' => $now - 10,
            'jti' => bin2hex(random_bytes(8)),
            'sid' => 'test-session',
        ], $overrides), $secret ?? config('shopify.api_secret'), 'HS256');
    }

    private function requestWithToken(string $token): Request
    {
        $request = Request::create('/api/admin/members', 'GET');
        $request->headers->set('Authorization', 'Bearer '.$token);

        return $request;
    }

    // --- Session token verification (V3) ------------------------------------

    public function test_a_valid_token_yields_the_shop_and_the_staff_identity(): void
    {
        $verified = app(SessionTokenVerifier::class)->verify($this->requestWithToken($this->token()));

        $this->assertSame(self::SHOP, $verified->shopDomain);
        $this->assertSame(self::OWNER, $verified->staffUserId);
        $this->assertSame('test-session', $verified->sessionId);
    }

    public function test_a_token_for_a_different_app_is_refused(): void
    {
        // The JWT library does not check the audience, so this class must.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('issued for a different app');

        app(SessionTokenVerifier::class)->verify(
            $this->requestWithToken($this->token(['aud' => 'some-other-app-client-id'])),
        );
    }

    public function test_a_token_signed_with_the_wrong_secret_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        app(SessionTokenVerifier::class)->verify(
            $this->requestWithToken($this->token(secret: 'ffffffffffffffffffffffffffffffff')),
        );
    }

    public function test_an_expired_token_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        app(SessionTokenVerifier::class)->verify($this->requestWithToken($this->token([
            'exp' => time() - 120,
            'nbf' => time() - 300,
            'iat' => time() - 300,
        ])));
    }

    public function test_the_shop_comes_from_the_token_and_not_from_a_query_parameter(): void
    {
        $request = Request::create('/api/admin/members?shop=attacker.myshopify.com', 'GET');
        $request->headers->set('Authorization', 'Bearer '.$this->token());

        $verified = app(SessionTokenVerifier::class)->verify($request);

        $this->assertSame(self::SHOP, $verified->shopDomain);
    }

    public function test_a_token_with_no_usable_subject_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no usable staff identity');

        app(SessionTokenVerifier::class)->verify($this->requestWithToken($this->token(['sub' => 'not-a-number'])));
    }

    // --- The C6 bootstrap ---------------------------------------------------

    public function test_the_first_staff_member_on_an_unconfigured_shop_becomes_administrator(): void
    {
        $role = app(StaffRoleResolver::class)->resolve(self::SHOP, self::OWNER);

        $this->assertNotNull($role);
        $this->assertSame('administrator', $role->role);

        // The one role nobody assigned, so it is recorded loudly.
        $this->assertDatabaseHas('loyalty_audit_log', [
            'shop_domain' => self::SHOP,
            'action' => AuditAction::STAFF_BOOTSTRAPPED,
        ]);
    }

    public function test_the_bootstrap_happens_only_once_per_shop(): void
    {
        $resolver = app(StaffRoleResolver::class);

        $first = $resolver->resolve(self::SHOP, self::OWNER);
        $second = $resolver->resolve(self::SHOP, 999888777);

        $this->assertSame('administrator', $first->role);
        $this->assertNull(
            $second,
            'A second staff member must not be granted anything automatically.',
        );
        $this->assertSame(1, StaffRole::query()->where('shop_domain', self::SHOP)->count());
    }

    // --- Role hierarchy -----------------------------------------------------

    public static function roleFloorProvider(): array
    {
        return [
            // held role, floor, expected
            ['viewer', 'viewer', true],
            ['viewer', 'agent', false],
            ['viewer', 'manager', false],
            ['viewer', 'administrator', false],
            ['agent', 'viewer', true],
            ['agent', 'agent', true],
            ['agent', 'manager', false],
            ['manager', 'agent', true],
            ['manager', 'manager', true],
            ['manager', 'administrator', false],
            ['administrator', 'viewer', true],
            ['administrator', 'administrator', true],
        ];
    }

    #[DataProvider('roleFloorProvider')]
    public function test_a_higher_role_always_satisfies_a_lower_floor(string $held, string $floor, bool $expected): void
    {
        $staff = new StaffRole(['role' => $held]);

        $this->assertSame($expected, $staff->satisfies($floor), $held.' vs '.$floor);
    }

    public function test_manager_and_above_are_unrestricted_on_adjustments(): void
    {
        $resolver = app(StaffRoleResolver::class);

        $agent = new StaffRole(['role' => 'agent', 'adjustment_limit_points' => null]);
        $trustedAgent = new StaffRole(['role' => 'agent', 'adjustment_limit_points' => 5000]);
        $manager = new StaffRole(['role' => 'manager']);

        $this->assertSame(500, $resolver->adjustmentLimit($agent, 500), 'The rule default applies.');
        $this->assertSame(5000, $resolver->adjustmentLimit($trustedAgent, 500), 'A personal override wins.');
        $this->assertNull($resolver->adjustmentLimit($manager, 500), 'Manager and above are unrestricted.');
    }

    // --- Role assignment ----------------------------------------------------

    public function test_assigning_a_role_writes_an_audit_entry(): void
    {
        $resolver = app(StaffRoleResolver::class);
        $resolver->resolve(self::SHOP, self::OWNER);

        $staff = $resolver->assign(self::SHOP, 777, 'agent', name: 'Till Assistant');

        $this->assertSame('agent', $staff->role);
        $this->assertSame('Till Assistant', $staff->staff_name);

        $entry = AuditEntry::query()->where('action', AuditAction::STAFF_ROLE_ASSIGNED)->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertSame('StaffRole', $entry->subject_type);
        $this->assertSame('agent', $entry->after_state['role']);
    }

    public function test_the_last_administrator_cannot_be_demoted(): void
    {
        $resolver = app(StaffRoleResolver::class);
        $resolver->resolve(self::SHOP, self::OWNER);

        $this->expectException(LastAdministratorException::class);

        $resolver->assign(self::SHOP, self::OWNER, 'viewer');
    }

    public function test_an_administrator_can_be_demoted_once_another_exists(): void
    {
        $resolver = app(StaffRoleResolver::class);
        $resolver->resolve(self::SHOP, self::OWNER);

        $resolver->assign(self::SHOP, 555, 'administrator', name: 'Second Administrator');
        $demoted = $resolver->assign(self::SHOP, self::OWNER, 'viewer');

        $this->assertSame('viewer', $demoted->role);
    }

    public function test_an_unknown_role_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(StaffRoleResolver::class)->assign(self::SHOP, 1, 'superuser');
    }

    // --- Audit logging ------------------------------------------------------

    public function test_an_action_that_requires_a_reason_is_refused_without_one(): void
    {
        app(RequestContext::class)->forSystem(self::SHOP);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a reason');

        app(AuditLogger::class)->log(
            action: AuditAction::POINTS_ADJUSTED,
            subjectType: 'LoyaltyAccount',
            subjectId: 1,
        );
    }

    public function test_a_blank_reason_does_not_satisfy_the_requirement(): void
    {
        app(RequestContext::class)->forSystem(self::SHOP);

        $this->expectException(InvalidArgumentException::class);

        app(AuditLogger::class)->log(
            action: AuditAction::ACCOUNT_MERGED,
            subjectType: 'LoyaltyAccount',
            subjectId: 1,
            reason: '    ',
        );
    }

    public function test_an_unknown_action_is_refused(): void
    {
        app(RequestContext::class)->forSystem(self::SHOP);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown audit action');

        app(AuditLogger::class)->log(
            action: 'points.quietly_removed',
            subjectType: 'LoyaltyAccount',
            subjectId: 1,
        );
    }

    public function test_an_entry_records_the_actor_and_the_request_id(): void
    {
        $resolver = app(StaffRoleResolver::class);
        $staff = $resolver->resolve(self::SHOP, self::OWNER);

        $context = app(RequestContext::class)->forStaff($staff, self::SHOP, 'admin', '203.0.113.7');

        $entry = app(AuditLogger::class)->log(
            action: AuditAction::POINTS_ADJUSTED,
            subjectType: 'LoyaltyAccount',
            subjectId: 42,
            reason: 'Goodwill after a delayed order',
            before: ['points_available' => 100],
            after: ['points_available' => 150],
        );

        $this->assertSame('staff', $entry->actor_type);
        $this->assertSame(self::OWNER, (int) $entry->actor_staff_id);
        $this->assertSame('admin', $entry->channel);
        $this->assertSame('203.0.113.7', $entry->ip_address);
        $this->assertSame($context->requestId(), $entry->request_id);
        $this->assertSame(['points_available' => 100], $entry->before_state);
        $this->assertSame(['points_available' => 150], $entry->after_state);
    }

    public function test_an_audit_entry_cannot_be_edited_or_removed(): void
    {
        app(RequestContext::class)->forSystem(self::SHOP);

        $entry = app(AuditLogger::class)->log(
            action: AuditAction::BALANCE_REBUILT,
            subjectType: 'LoyaltyAccount',
            subjectId: 7,
        );

        try {
            $entry->update(['reason' => 'rewritten history']);
            $this->fail('The audit log accepted an update.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        try {
            $entry->delete();
            $this->fail('The audit log accepted a delete.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        $this->assertNull($entry->fresh()->reason);
    }

    public function test_a_system_actor_needs_no_staff_identity(): void
    {
        app(RequestContext::class)->forSystem(self::SHOP);

        $entry = app(AuditLogger::class)->log(
            action: AuditAction::BALANCE_REBUILT,
            subjectType: 'LoyaltyAccount',
            subjectId: 7,
        );

        $this->assertSame('system', $entry->actor_type);
        $this->assertNull($entry->actor_staff_id);
    }

    public function test_the_staff_name_falls_back_to_the_identifier_because_read_users_is_protected(): void
    {
        $staff = app(StaffRoleResolver::class)->resolve(self::SHOP, self::OWNER);
        $context = app(RequestContext::class)->forStaff($staff, self::SHOP);

        // No name has been entered by an Administrator yet.
        $this->assertNull($staff->staff_name);
        $this->assertSame('Staff '.self::OWNER, $context->staffName());
    }
}
