<?php

namespace Tests\Feature;

use App\Domain\Loyalty\LedgerPosting;
use App\Domain\Loyalty\LedgerService;
use App\Domain\Rules\RuleSet;
use App\Domain\Rules\RulesVersionRepository;
use App\Models\LedgerEntry;
use App\Models\LotAllocation;
use App\Models\LoyaltyAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The ledger is the only source of truth for every balance, so these are the
 * tests that matter most in the whole suite: append-only enforcement,
 * idempotency, rule versioning, and the guarantee that the cached balance can
 * never disagree with the entries it is built from.
 */
class LedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'loyalty-system.myshopify.com';

    private LedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = app(LedgerService::class);
        app(RulesVersionRepository::class)->seedDefaults(self::SHOP);
    }

    private function member(array $attributes = []): LoyaltyAccount
    {
        return LoyaltyAccount::create(array_merge([
            'shop_domain' => self::SHOP,
            'email' => 'member'.uniqid().'@example.com',
            'enrolment_channel' => 'online',
            'enrolled_at' => now(),
        ], $attributes));
    }

    public function test_an_earn_lands_in_pending_and_not_in_available(): void
    {
        $member = $this->member();

        $this->ledger->post($member, LedgerPosting::earn(
            points: 150,
            idempotencyKey: 'earn:1001',
            occurredAt: now(),
            maturesAt: now()->addDays(30),
            expiresAt: now()->addDays(30)->addDays(182),
            shopifyOrderId: 1001,
            qualifyingValuePence: 15000,
        ));

        $member->refresh();

        $this->assertSame(150, $member->points_pending);
        $this->assertSame(0, $member->points_available);
        // Nothing is redeemable until the points mature.
        $this->assertSame(0, $member->voucher_balance_pence);
    }

    public function test_maturity_moves_points_between_buckets_without_creating_any(): void
    {
        $member = $this->member();

        $earn = $this->ledger->post($member, LedgerPosting::earn(
            points: 190,
            idempotencyKey: 'earn:1002',
            occurredAt: now()->subDays(31),
            maturesAt: now()->subDay(),
            expiresAt: now()->addDays(181),
        ));

        $this->ledger->post($member, LedgerPosting::maturity(
            points: 190,
            parentEntryId: $earn->id,
            idempotencyKey: 'mature:'.$earn->id,
            occurredAt: now(),
        ));

        $member->refresh();

        $this->assertSame(0, $member->points_pending);
        $this->assertSame(190, $member->points_available);
        // The Blueprint worked example: 190 points is five pounds, 90 retained.
        $this->assertSame(500, $member->voucher_balance_pence);
        $this->assertSame(190, $member->points_lifetime);
    }

    public function test_the_same_idempotency_key_cannot_post_twice(): void
    {
        $member = $this->member();

        $posting = fn (): LedgerPosting => LedgerPosting::earn(
            points: 100,
            idempotencyKey: 'earn:order:5555',
            occurredAt: now(),
            maturesAt: now()->addDays(30),
            expiresAt: now()->addDays(212),
            shopifyOrderId: 5555,
        );

        $first = $this->ledger->post($member, $posting());
        $second = $this->ledger->post($member, $posting());

        $this->assertSame($first->id, $second->id, 'A redelivered webhook must return the original entry.');
        $this->assertSame(1, LedgerEntry::query()->where('idempotency_key', 'earn:order:5555')->count());

        $member->refresh();
        $this->assertSame(100, $member->points_pending, 'Points must not be counted twice.');
    }

    public function test_a_ledger_entry_cannot_be_updated_or_deleted(): void
    {
        $member = $this->member();

        $entry = $this->ledger->post($member, LedgerPosting::adjustment(
            points: 50,
            bucket: 'available',
            reason: 'Goodwill after a delayed order',
            idempotencyKey: 'adj:1',
            occurredAt: now(),
        ));

        try {
            $entry->update(['available_delta' => 999]);
            $this->fail('The ledger accepted an update.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        try {
            $entry->delete();
            $this->fail('The ledger accepted a delete.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        $this->assertSame(50, $entry->fresh()->available_delta);
    }

    public function test_a_correction_is_a_new_row_and_the_balance_follows(): void
    {
        $member = $this->member();

        $this->ledger->post($member, LedgerPosting::adjustment(
            points: 200,
            bucket: 'available',
            reason: 'Opening goodwill',
            idempotencyKey: 'adj:a',
            occurredAt: now(),
        ));

        $this->ledger->post($member, LedgerPosting::adjustment(
            points: -60,
            bucket: 'available',
            reason: 'Correcting an error on the previous adjustment',
            idempotencyKey: 'adj:b',
            occurredAt: now(),
        ));

        $member->refresh();

        $this->assertSame(140, $member->points_available);
        $this->assertSame(2, LedgerEntry::query()->where('loyalty_account_id', $member->id)->count());
        // Lifetime counts credits only, so the correction does not reduce it.
        $this->assertSame(200, $member->points_lifetime);
    }

    public function test_a_clawback_may_take_available_below_zero_and_the_voucher_floors_at_zero(): void
    {
        $member = $this->member();

        $this->ledger->post($member, LedgerPosting::adjustment(
            points: 20,
            bucket: 'available',
            reason: 'Small credit',
            idempotencyKey: 'adj:c',
            occurredAt: now(),
        ));

        $this->ledger->post($member, LedgerPosting::adjustment(
            points: -70,
            bucket: 'available',
            reason: 'Clawback after a refund',
            idempotencyKey: 'adj:d',
            occurredAt: now(),
        ));

        $member->refresh();

        // Q3, recommended behaviour: the ledger stays truthful...
        $this->assertSame(-50, $member->points_available);
        // ...and the customer never sees a negative reward.
        $this->assertSame(0, $member->voucher_balance_pence);
    }

    public function test_consuming_points_allocates_against_the_oldest_earning_first(): void
    {
        $member = $this->member();

        $older = $this->ledger->post($member, LedgerPosting::openingBalance(
            points: 100,
            idempotencyKey: 'open:1',
            occurredAt: now()->subDays(10),
            expiresAt: now()->addDays(172),
        ));

        $newer = $this->ledger->post($member, LedgerPosting::openingBalance(
            points: 300,
            idempotencyKey: 'open:2',
            occurredAt: now()->subDays(5),
            expiresAt: now()->addDays(177),
        ));

        $consumer = $this->ledger->post($member, LedgerPosting::adjustment(
            points: -250,
            bucket: 'available',
            reason: 'Manual redemption correction',
            idempotencyKey: 'adj:consume',
            occurredAt: now(),
        ));

        $allocations = LotAllocation::query()
            ->where('consuming_entry_id', $consumer->id)
            ->orderBy('lot_entry_id')
            ->get();

        $this->assertCount(2, $allocations);
        $this->assertSame($older->id, $allocations[0]->lot_entry_id);
        $this->assertSame(100, $allocations[0]->points, 'The oldest lot is drained first.');
        $this->assertSame($newer->id, $allocations[1]->lot_entry_id);
        $this->assertSame(150, $allocations[1]->points);

        $member->refresh();
        $this->assertSame(150, $member->points_available);
    }

    public function test_pending_points_are_not_available_to_consume(): void
    {
        $member = $this->member();

        // An earn that has not matured yet.
        $this->ledger->post($member, LedgerPosting::earn(
            points: 500,
            idempotencyKey: 'earn:pending',
            occurredAt: now(),
            maturesAt: now()->addDays(30),
            expiresAt: now()->addDays(212),
        ));

        $consumer = $this->ledger->post($member, LedgerPosting::adjustment(
            points: -100,
            bucket: 'available',
            reason: 'Attempting to consume points that have not matured',
            idempotencyKey: 'adj:early',
            occurredAt: now(),
        ));

        // The entry is posted, because the ledger records what happened, but
        // nothing could be attributed to a lot: the points are still pending.
        $this->assertSame(
            0,
            LotAllocation::query()->where('consuming_entry_id', $consumer->id)->sum('points'),
        );

        $member->refresh();
        $this->assertSame(500, $member->points_pending);
        $this->assertSame(-100, $member->points_available);
    }

    public function test_every_entry_records_the_rule_version_in_force_when_it_happened(): void
    {
        $rules = app(RulesVersionRepository::class);
        $member = $this->member();

        $originalVersion = $rules->currentModel(self::SHOP);

        $historic = $this->ledger->post($member, LedgerPosting::adjustment(
            points: 10,
            bucket: 'available',
            reason: 'Before the rule change',
            idempotencyKey: 'adj:before',
            occurredAt: now()->subDays(2),
        ));

        // A new version, saved now.
        $rules->save(
            self::SHOP,
            RuleSet::defaults() + [],
            staffId: 42,
            staffName: 'Test Administrator',
            changeSummary: 'Doubling the voucher value',
        );
        $newVersion = $rules->currentModel(self::SHOP);

        $current = $this->ledger->post($member, LedgerPosting::adjustment(
            points: 10,
            bucket: 'available',
            reason: 'After the rule change',
            idempotencyKey: 'adj:after',
            occurredAt: now(),
        ));

        $this->assertNotSame($originalVersion->id, $newVersion->id);
        $this->assertSame(
            $originalVersion->id,
            $historic->rules_version_id,
            'An entry dated before the change must use the older version.',
        );
        $this->assertSame(
            $newVersion->id,
            $current->rules_version_id,
            'An entry dated after the change must use the newer version.',
        );
    }

    public function test_posting_to_a_merged_account_is_refused(): void
    {
        $survivor = $this->member();
        $merged = $this->member();

        $merged->forceFill([
            'status' => 'merged',
            'merged_into_account_id' => $survivor->id,
        ])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('merged into');

        $this->ledger->post($merged, LedgerPosting::adjustment(
            points: 10,
            bucket: 'available',
            reason: 'Should not be possible',
            idempotencyKey: 'adj:merged',
            occurredAt: now(),
        ));
    }

    public function test_an_adjustment_without_a_reason_is_refused_before_it_reaches_the_database(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a reason');

        LedgerPosting::adjustment(
            points: 10,
            bucket: 'available',
            reason: '   ',
            idempotencyKey: 'adj:noreason',
            occurredAt: now(),
        );
    }
}
