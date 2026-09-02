<?php

namespace Tests\Feature;

use App\Domain\Loyalty\LedgerPosting;
use App\Domain\Loyalty\LedgerService;
use App\Domain\Rules\RulesVersionRepository;
use App\Models\LoyaltyAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Blueprint says cached balances are disposable and the ledger wins. These
 * two commands are what turn that from a principle into an operational fact:
 * verify notices drift and fails loudly, rebuild corrects it.
 */
class LoyaltyBalanceCommandsTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'loyalty-system.myshopify.com';

    private function memberWithPoints(int $available): LoyaltyAccount
    {
        app(RulesVersionRepository::class)->seedDefaults(self::SHOP);

        $member = LoyaltyAccount::create([
            'shop_domain' => self::SHOP,
            'email' => 'member'.uniqid().'@example.com',
            'enrolment_channel' => 'online',
            'enrolled_at' => now(),
        ]);

        app(LedgerService::class)->post($member, LedgerPosting::openingBalance(
            points: $available,
            idempotencyKey: 'open:'.$member->id,
            occurredAt: now(),
            expiresAt: now()->addDays(182),
        ));

        return $member->refresh();
    }

    public function test_verify_passes_when_the_cache_matches_the_ledger(): void
    {
        $this->memberWithPoints(190);

        $this->artisan('loyalty:verify-ledger')
            ->expectsOutputToContain('Every cached balance matches the ledger.')
            ->assertExitCode(0);
    }

    public function test_verify_fails_loudly_when_a_cache_has_drifted(): void
    {
        $member = $this->memberWithPoints(190);

        // Corrupt the cache directly, the way a bad deploy or a manual database
        // edit would. The ledger is untouched.
        $member->forceFill(['points_available' => 9999, 'voucher_balance_pence' => 49500])->save();

        $this->artisan('loyalty:verify-ledger')
            ->expectsOutputToContain('disagree with the ledger')
            ->assertExitCode(1);
    }

    public function test_rebuild_restores_the_cache_from_the_ledger(): void
    {
        $member = $this->memberWithPoints(190);

        $member->forceFill([
            'points_pending' => 500,
            'points_available' => 9999,
            'points_lifetime' => 1,
            'voucher_balance_pence' => 49500,
        ])->save();

        $this->artisan('loyalty:rebuild-balances')->assertExitCode(0);

        $member->refresh();

        $this->assertSame(0, $member->points_pending);
        $this->assertSame(190, $member->points_available);
        $this->assertSame(190, $member->points_lifetime);
        // 190 points is one increment of five pounds, with ninety retained.
        $this->assertSame(500, $member->voucher_balance_pence);
        $this->assertNotNull($member->caches_rebuilt_at);

        // And verify now agrees.
        $this->artisan('loyalty:verify-ledger')->assertExitCode(0);
    }

    public function test_rebuild_can_target_a_single_member(): void
    {
        $one = $this->memberWithPoints(100);
        $two = $this->memberWithPoints(200);

        $one->forceFill(['points_available' => 1])->save();
        $two->forceFill(['points_available' => 2])->save();

        $this->artisan('loyalty:rebuild-balances', ['--member' => $one->id])->assertExitCode(0);

        $this->assertSame(100, $one->refresh()->points_available);
        $this->assertSame(2, $two->refresh()->points_available, 'Only the named member should be rebuilt.');
    }

    public function test_rebuild_of_a_ledger_with_many_entries_reproduces_the_balance_exactly(): void
    {
        $member = $this->memberWithPoints(0 + 1);
        $ledger = app(LedgerService::class);

        $expected = 1;

        // A long, mixed history: credits and debits, in and out of both buckets.
        for ($i = 0; $i < 60; $i++) {
            $points = ($i % 7) + 1;
            $sign = $i % 3 === 0 ? -1 : 1;

            $ledger->post($member, LedgerPosting::adjustment(
                points: $sign * $points,
                bucket: 'available',
                reason: 'Case '.$i,
                idempotencyKey: 'adj:'.$i,
                occurredAt: now()->addSeconds($i),
            ));

            $expected += $sign * $points;
        }

        $member->refresh();
        $this->assertSame($expected, $member->points_available);

        // Zero the caches entirely, then rebuild from the ledger alone.
        $member->forceFill([
            'points_pending' => 0,
            'points_available' => 0,
            'points_lifetime' => 0,
            'voucher_balance_pence' => 0,
        ])->save();

        $this->artisan('loyalty:rebuild-balances', ['--member' => $member->id])->assertExitCode(0);

        $this->assertSame($expected, $member->refresh()->points_available);
    }
}
