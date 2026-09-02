<?php

namespace Tests\Feature;

use App\Domain\Loyalty\BirthdaySweep;
use App\Domain\Loyalty\ExpirySweep;
use App\Domain\Loyalty\LedgerPosting;
use App\Domain\Loyalty\LedgerService;
use App\Domain\Loyalty\MaturitySweep;
use App\Domain\Loyalty\ProgrammeSummary;
use App\Domain\Loyalty\RefundReversalService;
use App\Domain\Loyalty\SegmentSweep;
use App\Domain\Rules\RulesVersionRepository;
use App\Models\LedgerEntry;
use App\Models\LoyaltyAccount;
use App\Models\Redemption;
use App\Models\Reward;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The scheduled engine: maturity, expiry, birthday rewards and segmentation.
 *
 * Sprint 2's exit criterion is that these run with nobody running anything, so
 * what matters here is that each is idempotent, each is correct at its
 * boundary, and each can be pointed at a stated date rather than the clock.
 */
class LoyaltySweepsTest extends TestCase
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
            'enrolled_at' => now()->subYear(),
        ], $attributes));
    }

    // ---------------------------------------------------------------- maturity

    public function test_maturity_moves_pending_points_into_available(): void
    {
        $member = $this->member();

        $this->ledger->post($member, LedgerPosting::earn(
            points: 190,
            idempotencyKey: 'earn:due',
            occurredAt: now()->subDays(31),
            maturesAt: now()->subDay(),
            expiresAt: now()->addDays(181),
        ));

        $result = app(MaturitySweep::class)->run(self::SHOP);

        $this->assertSame(1, $result['matured']);
        $this->assertSame(190, $result['points']);

        $member->refresh();
        $this->assertSame(0, $member->points_pending);
        $this->assertSame(190, $member->points_available);
        $this->assertSame(500, $member->voucher_balance_pence);
    }

    public function test_maturity_leaves_points_that_are_not_due_alone(): void
    {
        $member = $this->member();

        $this->ledger->post($member, LedgerPosting::earn(
            points: 100,
            idempotencyKey: 'earn:notdue',
            occurredAt: now(),
            maturesAt: now()->addDays(30),
            expiresAt: now()->addDays(212),
        ));

        $this->assertSame(0, app(MaturitySweep::class)->run(self::SHOP)['matured']);

        $member->refresh();
        $this->assertSame(100, $member->points_pending);
        $this->assertSame(0, $member->points_available);
    }

    /**
     * D9b, the worked example. An 80 point earn with 20 already reversed
     * matures SIXTY, and pending ends at zero rather than at minus twenty.
     */
    public function test_maturity_nets_off_a_reversal_taken_before_it(): void
    {
        $member = $this->member();

        $earn = $this->ledger->post($member, LedgerPosting::earn(
            points: 80,
            idempotencyKey: 'earn:worked-example',
            occurredAt: now()->subDays(30),
            maturesAt: now(),
            expiresAt: now()->addDays(182),
            shopifyOrderId: 1042,
            qualifyingValuePence: 8000,
        ));

        // The refund arrived ten days in, before anything matured.
        $this->ledger->post($member, LedgerPosting::earnReversal(
            pendingPoints: 20,
            availablePoints: 0,
            earnEntryId: (int) $earn->id,
            idempotencyKey: 'reverse:refund:77001:'.$earn->id,
            occurredAt: now()->subDays(20),
            shopifyRefundId: 77001,
        ));

        $member->refresh();
        $this->assertSame(60, $member->points_pending, 'Before maturity: 80 earned, 20 taken back.');

        $result = app(MaturitySweep::class)->run(self::SHOP);

        $this->assertSame(60, $result['points'], 'The sweep matures the earn net of reversals, never the raw 80.');

        $maturity = LedgerEntry::query()->where('entry_type', 'maturity')->sole();
        $this->assertSame(-60, $maturity->pending_delta);
        $this->assertSame(60, $maturity->available_delta);
        $this->assertSame($earn->id, $maturity->parent_entry_id);

        $member->refresh();
        $this->assertSame(0, $member->points_pending, 'Pending ends at zero.');
        $this->assertNotSame(-20, $member->points_pending, 'And never at minus twenty.');
        $this->assertSame(60, $member->points_available);
    }

    /** An earn reversed away entirely is settled, not overdue for ever. */
    public function test_a_fully_reversed_earn_matures_nothing_and_is_reported_as_settled(): void
    {
        $member = $this->member();

        $earn = $this->ledger->post($member, LedgerPosting::earn(
            points: 80,
            idempotencyKey: 'earn:cancelled',
            occurredAt: now()->subDays(30),
            maturesAt: now(),
            expiresAt: now()->addDays(182),
            shopifyOrderId: 2001,
        ));

        $this->ledger->post($member, LedgerPosting::earnReversal(
            pendingPoints: 80,
            availablePoints: 0,
            earnEntryId: (int) $earn->id,
            idempotencyKey: 'reverse:order:2001:'.$earn->id,
            occurredAt: now()->subDay(),
        ));

        $result = app(MaturitySweep::class)->run(self::SHOP);

        $this->assertSame(0, $result['matured']);
        $this->assertSame(1, $result['settled']);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'maturity')->count());

        $member->refresh();
        $this->assertSame(0, $member->points_pending);
        $this->assertSame(0, $member->points_available);

        // And it must not sit in the console's overdue count for ever, because
        // no amount of running the sweep would ever clear it.
        $health = app(ProgrammeSummary::class)->overview(self::SHOP)['jobs'];
        $this->assertSame(0, $health['overdue_maturities']['entries']);
    }

    public function test_maturity_is_idempotent(): void
    {
        $member = $this->member();

        $this->ledger->post($member, LedgerPosting::earn(
            points: 100,
            idempotencyKey: 'earn:twice',
            occurredAt: now()->subDays(31),
            maturesAt: now()->subDay(),
            expiresAt: now()->addDays(181),
        ));

        app(MaturitySweep::class)->run(self::SHOP);
        $second = app(MaturitySweep::class)->run(self::SHOP);

        $this->assertSame(0, $second['matured'], 'The second run finds nothing to do.');
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'maturity')->count());

        $member->refresh();
        $this->assertSame(100, $member->points_available, 'Not two hundred.');
    }

    // ------------------------------------------------------------------ expiry

    public function test_expiry_retires_the_lot_that_expired_and_no_other(): void
    {
        $member = $this->member();

        $older = $this->ledger->post($member, LedgerPosting::openingBalance(
            points: 100,
            idempotencyKey: 'open:expired',
            occurredAt: now()->subMonths(7),
            expiresAt: now()->subDay(),
            reason: 'Migrated balance',
        ));

        $this->ledger->post($member, LedgerPosting::openingBalance(
            points: 150,
            idempotencyKey: 'open:live',
            occurredAt: now()->subMonth(),
            expiresAt: now()->addMonths(5),
            reason: 'Later balance',
        ));

        $result = app(ExpirySweep::class)->run(self::SHOP);

        $this->assertSame(1, $result['expired']);
        $this->assertSame(100, $result['points']);

        $expiry = LedgerEntry::query()->where('entry_type', 'expiry')->sole();
        $this->assertSame($older->id, $expiry->parent_entry_id, 'Expiry names the lot it retired.');
        $this->assertSame(-100, $expiry->available_delta);

        // And it consumed that lot, not whichever happened to be oldest by FIFO.
        $this->assertSame(
            100,
            (int) DB::table('loyalty_lot_allocations')
                ->where('consuming_entry_id', $expiry->id)
                ->where('lot_entry_id', $older->id)
                ->sum('points'),
        );

        $member->refresh();
        $this->assertSame(150, $member->points_available);
    }

    public function test_expiry_only_takes_what_a_lot_has_left(): void
    {
        $member = $this->member();

        $lot = $this->ledger->post($member, LedgerPosting::openingBalance(
            points: 260,
            idempotencyKey: 'open:partly-spent',
            occurredAt: now()->subMonths(7),
            expiresAt: now()->subDay(),
            reason: 'Migrated balance',
        ));

        $redemption = $this->redemption($member, 200);

        $this->ledger->post($member, LedgerPosting::redemption(
            points: 200,
            redemptionId: (int) $redemption->id,
            idempotencyKey: 'redeem:3001',
            occurredAt: now()->subMonth(),
            shopifyOrderId: 3001,
        ));

        $result = app(ExpirySweep::class)->run(self::SHOP);

        $this->assertSame(60, $result['points'], 'Only what was left, never the whole lot.');
        $this->assertSame($lot->id, LedgerEntry::query()->where('entry_type', 'expiry')->sole()->parent_entry_id);

        $member->refresh();
        $this->assertSame(0, $member->points_available);
    }

    public function test_expiry_is_idempotent_on_the_same_day(): void
    {
        $member = $this->member();

        $this->ledger->post($member, LedgerPosting::openingBalance(
            points: 100,
            idempotencyKey: 'open:once',
            occurredAt: now()->subMonths(7),
            expiresAt: now()->subDay(),
            reason: 'Migrated balance',
        ));

        app(ExpirySweep::class)->run(self::SHOP);
        $second = app(ExpirySweep::class)->run(self::SHOP);

        $this->assertSame(0, $second['expired']);
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'expiry')->count());

        $member->refresh();
        $this->assertSame(0, $member->points_available, 'Not minus one hundred.');
    }

    /**
     * The edge the dated idempotency key exists for: points released back into
     * a lot whose expiry date has already passed (D9a) expire on the NEXT
     * sweep rather than living on for ever.
     */
    public function test_points_restored_into_an_already_expired_lot_expire_on_the_next_sweep(): void
    {
        $member = $this->member();

        $this->ledger->post($member, LedgerPosting::openingBalance(
            points: 260,
            idempotencyKey: 'open:all-spent',
            occurredAt: now()->subMonths(7),
            expiresAt: now()->subDay(),
            reason: 'Migrated balance',
        ));

        $redemption = $this->redemption($member, 260, orderId: 4001, amountPence: 1000);

        $this->ledger->post($member, LedgerPosting::redemption(
            points: 260,
            redemptionId: (int) $redemption->id,
            idempotencyKey: 'redeem:4001',
            occurredAt: now()->subMonth(),
            shopifyOrderId: 4001,
        ));

        // Nothing left in the lot, so today's sweep finds nothing to expire.
        $this->assertSame(0, app(ExpirySweep::class)->run(self::SHOP)['expired']);

        // A refund hands half of it back, into a lot that expired yesterday.
        app(RefundReversalService::class)->apply(
            account: $member->refresh(),
            shopifyOrderId: 4001,
            orderEligiblePence: 8000,
            cumulativeRefundedPence: 4000,
            shopifyRefundId: 99001,
        );

        $member->refresh();
        $this->assertSame(130, $member->points_available, 'Half the redemption is back.');

        // Tomorrow's sweep takes them, because they were always past their date.
        $result = app(ExpirySweep::class)->run(self::SHOP, now()->addDay());

        $this->assertSame(130, $result['points']);

        $member->refresh();
        $this->assertSame(0, $member->points_available, 'Restored points do not outlive their lot.');
    }

    // ---------------------------------------------------------------- birthday

    /**
     * C4, confirmed 31 Aug 2026. The reward is issued on date of birth alone.
     */
    public function test_a_lapsed_member_receives_the_birthday_reward(): void
    {
        $today = Carbon::parse('2026-09-15 09:00:00');

        $lapsed = $this->member([
            'dob_month' => 9,
            'dob_day' => 15,
            'segment' => 'lapsed',
            'last_qualifying_spend_at' => $today->copy()->subYears(4),
        ]);

        $active = $this->member([
            'dob_month' => 9,
            'dob_day' => 15,
            'segment' => 'active',
            'last_qualifying_spend_at' => $today->copy()->subMonth(),
        ]);

        $unknown = $this->member(['dob_month' => 9, 'dob_day' => 15, 'segment' => 'unknown']);

        $result = app(BirthdaySweep::class)->run(self::SHOP, $today);

        $this->assertSame(3, $result['issued'], 'Every segment, because segment does not gate the reward.');

        foreach ([$lapsed, $active, $unknown] as $member) {
            $reward = Reward::query()
                ->where('loyalty_account_id', $member->id)
                ->where('reward_type', 'birthday')
                ->sole();

            $this->assertSame(1000, $reward->value_pence, 'The £10 birthday voucher.');
            $this->assertSame(2026, (int) $reward->birthday_year);
            $this->assertSame('issued', $reward->state);
        }
    }

    /** MD2: a day and month with no birth year is eligible on identical terms. */
    public function test_a_member_with_no_birth_year_receives_the_reward(): void
    {
        $member = $this->member([
            'dob_month' => 9,
            'dob_day' => 15,
            'date_of_birth' => null,
        ]);

        app(BirthdaySweep::class)->run(self::SHOP, Carbon::parse('2026-09-15'));

        $this->assertSame(
            1,
            Reward::query()->where('loyalty_account_id', $member->id)->count(),
        );
    }

    public function test_a_member_with_no_birthday_is_skipped_and_counted(): void
    {
        $this->member(['dob_month' => null, 'dob_day' => null]);
        $this->member(['dob_month' => 9, 'dob_day' => 15]);

        $result = app(BirthdaySweep::class)->run(self::SHOP, Carbon::parse('2026-09-15'));

        $this->assertSame(1, $result['issued']);
        $this->assertSame(1, $result['no_birthday'], 'Counted in the summary, not silently dropped.');
    }

    /** MD8: the unique index is the guard, so a re-run cannot double-issue. */
    public function test_running_the_birthday_job_twice_issues_once(): void
    {
        $member = $this->member(['dob_month' => 9, 'dob_day' => 15]);

        app(BirthdaySweep::class)->run(self::SHOP, Carbon::parse('2026-09-15'));
        $second = app(BirthdaySweep::class)->run(self::SHOP, Carbon::parse('2026-09-15'));

        $this->assertSame(0, $second['issued']);
        $this->assertSame(1, $second['already_held']);
        $this->assertSame(1, Reward::query()->where('loyalty_account_id', $member->id)->count());
    }

    public function test_a_member_receives_the_reward_again_the_following_year(): void
    {
        $member = $this->member(['dob_month' => 9, 'dob_day' => 15]);

        app(BirthdaySweep::class)->run(self::SHOP, Carbon::parse('2026-09-15'));
        app(BirthdaySweep::class)->run(self::SHOP, Carbon::parse('2027-09-15'));

        $this->assertSame(2, Reward::query()->where('loyalty_account_id', $member->id)->count());
        $this->assertSame(
            [2026, 2027],
            Reward::query()->where('loyalty_account_id', $member->id)
                ->orderBy('birthday_year')->pluck('birthday_year')->map(fn ($y) => (int) $y)->all(),
        );
    }

    /** A 29 February birthday is honoured on the 28th in a non-leap year. */
    public function test_a_leap_day_birthday_is_issued_on_the_twenty_eighth_in_a_common_year(): void
    {
        $member = $this->member(['dob_month' => 2, 'dob_day' => 29]);

        // 2027 is not a leap year.
        $result = app(BirthdaySweep::class)->run(self::SHOP, Carbon::parse('2027-02-28'));

        $this->assertSame(1, $result['issued']);
        $this->assertSame(2027, (int) Reward::query()->sole()->birthday_year);
    }

    public function test_a_leap_day_birthday_is_issued_on_the_day_itself_in_a_leap_year(): void
    {
        $this->member(['dob_month' => 2, 'dob_day' => 29]);

        // 2028 is a leap year, so the 28th belongs to the 28th only.
        $this->assertSame(0, app(BirthdaySweep::class)->run(self::SHOP, Carbon::parse('2028-02-28'))['issued']);
        $this->assertSame(1, app(BirthdaySweep::class)->run(self::SHOP, Carbon::parse('2028-02-29'))['issued']);
    }

    public function test_a_suspended_account_receives_nothing(): void
    {
        // status is deliberately not mass-assignable, so this goes the same way
        // a suspension does in the console rather than through create().
        $this->member(['dob_month' => 9, 'dob_day' => 15])
            ->forceFill(['status' => 'suspended'])->save();

        $this->assertSame(0, app(BirthdaySweep::class)->run(self::SHOP, Carbon::parse('2026-09-15'))['issued']);

        // Account status, not segment. A lapsed member still gets the reward
        // (C4); a suspended one is not a member in good standing.
        $this->assertSame(0, Reward::query()->count());
    }

    // ------------------------------------------------------------ segmentation

    public function test_a_member_who_spent_recently_is_active(): void
    {
        $member = $this->member();
        $this->earnAt($member, now()->subMonths(3));

        app(SegmentSweep::class)->run(self::SHOP);

        $member->refresh();
        $this->assertSame('active', $member->segment);
        $this->assertNotNull($member->last_qualifying_spend_at);
        $this->assertNotNull($member->segment_calculated_at);
    }

    public function test_a_member_who_has_not_spent_in_two_years_is_lapsed(): void
    {
        $member = $this->member();
        $this->earnAt($member, now()->subYears(2)->subDay());

        app(SegmentSweep::class)->run(self::SHOP);

        $this->assertSame('lapsed', $member->refresh()->segment);
    }

    /** The window is inclusive at its far edge: two years exactly is active. */
    public function test_the_two_year_boundary_falls_on_the_active_side(): void
    {
        // A fixed reference point rather than the clock. "Exactly two years" is
        // not a assertion you can make against a now() that moves between
        // building the fixture and running the sweep.
        $asOf = Carbon::parse('2026-08-31 12:00:00');

        $inside = $this->member();
        $this->earnAt($inside, $asOf->copy()->subYears(2)->addDay());

        $exactly = $this->member();
        $this->earnAt($exactly, $asOf->copy()->subYears(2));

        $outside = $this->member();
        $this->earnAt($outside, $asOf->copy()->subYears(2)->subDay());

        app(SegmentSweep::class)->run(self::SHOP, $asOf);

        $this->assertSame('active', $inside->refresh()->segment, 'One day inside.');
        $this->assertSame('active', $exactly->refresh()->segment, 'Exactly two years.');
        $this->assertSame('lapsed', $outside->refresh()->segment, 'One day outside.');
    }

    /** A member with no recorded spend is unknown, never lapsed. */
    public function test_a_member_with_no_history_is_unknown_rather_than_lapsed(): void
    {
        $member = $this->member();

        app(SegmentSweep::class)->run(self::SHOP);

        $member->refresh();
        $this->assertSame('unknown', $member->segment);
        $this->assertNull($member->last_qualifying_spend_at);
    }

    /** D7: a migrated member is segmented from the export, with no Shopify scope. */
    public function test_a_migrated_last_spend_date_segments_a_member_with_no_ledger_history(): void
    {
        $member = $this->member(['enrolment_channel' => 'migration']);

        $batchId = DB::table('migration_batches')->insertGetId([
            'shop_domain' => self::SHOP,
            'mode' => 'import',
            'source_filename' => 'legacy.csv',
            'source_checksum' => str_repeat('a', 64),
            'state' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('migration_records')->insert([
            'migration_batch_id' => $batchId,
            'row_number' => 1,
            'raw' => '{}',
            'parsed' => '{}',
            'match_method' => 'email',
            'decision' => 'create',
            'loyalty_account_id' => $member->id,
            'last_spend_at' => now()->subMonths(6),
            'created_at' => now(),
        ]);

        app(SegmentSweep::class)->run(self::SHOP);

        $member->refresh();
        $this->assertSame('active', $member->segment);
        $this->assertSame(
            now()->subMonths(6)->toDateString(),
            $member->last_qualifying_spend_at->toDateString(),
        );
    }

    public function test_segmentation_produces_the_same_answer_on_a_re_run(): void
    {
        $member = $this->member();
        $this->earnAt($member, now()->subMonths(3));

        $first = app(SegmentSweep::class)->run(self::SHOP);
        $second = app(SegmentSweep::class)->run(self::SHOP);

        $this->assertSame(1, $first['changed'], 'The first run moved the member off unknown.');
        $this->assertSame(0, $second['changed'], 'The second found nothing to move.');
        $this->assertSame($first['active'], $second['active']);
        $this->assertSame('active', $member->refresh()->segment);
    }

    public function test_a_merged_account_is_not_segmented(): void
    {
        $survivor = $this->member();
        $merged = $this->member();
        $merged->forceFill(['status' => 'merged', 'merged_into_account_id' => $survivor->id])->save();

        $result = app(SegmentSweep::class)->run(self::SHOP);

        $this->assertSame(1, $result['examined'], 'Only the surviving account.');
        $this->assertSame('unknown', $merged->refresh()->segment, 'Left exactly as it was.');
        $this->assertNull($merged->segment_calculated_at);
    }

    // ----------------------------------------------------------------- helpers

    private function earnAt(LoyaltyAccount $member, Carbon $when): LedgerEntry
    {
        return $this->ledger->post($member, LedgerPosting::earn(
            points: 50,
            idempotencyKey: 'earn:'.$member->id.':'.$when->timestamp,
            occurredAt: $when,
            maturesAt: $when->copy()->addDays(30),
            expiresAt: $when->copy()->addDays(212),
            shopifyOrderId: 9000 + (int) $member->id,
            qualifyingValuePence: 5000,
        ));
    }

    private function redemption(
        LoyaltyAccount $member,
        int $points,
        int $orderId = 3001,
        int $amountPence = 1000,
    ): Redemption {
        return Redemption::create([
            'shop_domain' => self::SHOP,
            'reference' => 'PC-'.$member->id.'-'.$orderId,
            'loyalty_account_id' => $member->id,
            'channel' => 'online',
            'state' => 'confirmed',
            'amount_pence' => $amountPence,
            'points_consumed' => $points,
            'discount_mechanism' => 'function',
            'shopify_order_id' => $orderId,
            'rules_version_id' => app(RulesVersionRepository::class)->current(self::SHOP)->versionId,
            'validation_snapshot' => [],
            'quoted_at' => now()->subMonth(),
            'quote_expires_at' => now()->subMonth()->addMinutes(20),
            'confirmed_at' => now()->subMonth(),
        ]);
    }
}
