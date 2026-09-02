<?php

namespace Tests\Feature;

use App\Domain\Loyalty\ExpiryOutlook;
use App\Domain\Loyalty\LedgerPosting;
use App\Domain\Loyalty\LedgerService;
use App\Domain\Loyalty\RefundReversalService;
use App\Domain\Rules\RulesVersionRepository;
use App\Models\LedgerEntry;
use App\Models\LotAllocation;
use App\Models\LoyaltyAccount;
use App\Models\Redemption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * D9 and its four sub-decisions, end to end against the real ledger.
 *
 * The scenario throughout is the worked example the client signed off: an
 * eighty pound eligible order earning eighty points, against which a ten pound
 * voucher - two hundred points - was redeemed from a two hundred and sixty
 * point migrated opening balance.
 */
class RefundReversalTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'loyalty-system.myshopify.com';

    private const ORDER = 1042;

    private LedgerService $ledger;

    private RefundReversalService $refunds;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = app(LedgerService::class);
        $this->refunds = app(RefundReversalService::class);
        app(RulesVersionRepository::class)->seedDefaults(self::SHOP);
    }

    private function member(): LoyaltyAccount
    {
        return LoyaltyAccount::create([
            'shop_domain' => self::SHOP,
            'email' => 'member'.uniqid().'@example.com',
            'enrolment_channel' => 'migration',
            'enrolled_at' => now()->subYear(),
        ]);
    }

    /**
     * The worked example, staged: 260 opening points, an 80 point earn still
     * pending, and 200 points spent on a ten pound voucher.
     *
     * @return array{0:LoyaltyAccount, 1:LedgerEntry, 2:Redemption}
     */
    private function orderWithRedemption(int $openingPoints = 260, int $redeemedPoints = 200): array
    {
        $member = $this->member();

        $this->ledger->post($member, LedgerPosting::openingBalance(
            points: $openingPoints,
            idempotencyKey: 'open:'.$member->id,
            occurredAt: now()->subMonths(2),
            expiresAt: now()->addMonths(4),
            reason: 'Migrated balance',
        ));

        $earn = $this->ledger->post($member, LedgerPosting::earn(
            points: 80,
            idempotencyKey: 'earn:order:'.self::ORDER.':'.$member->id,
            occurredAt: now(),
            maturesAt: now()->addDays(30),
            expiresAt: now()->addDays(212),
            shopifyOrderId: self::ORDER,
            orderName: '#'.self::ORDER,
            qualifyingValuePence: 8000,
        ));

        $redemption = Redemption::create([
            'shop_domain' => self::SHOP,
            'reference' => 'PC-'.$member->id,
            'loyalty_account_id' => $member->id,
            'channel' => 'online',
            'state' => 'confirmed',
            'amount_pence' => 1000,
            'points_consumed' => $redeemedPoints,
            'discount_mechanism' => 'function',
            'shopify_order_id' => self::ORDER,
            'order_name' => '#'.self::ORDER,
            'rules_version_id' => app(RulesVersionRepository::class)->current(self::SHOP)->versionId,
            'validation_snapshot' => ['eligible_subtotal_pence' => 9000],
            'quoted_at' => now(),
            'quote_expires_at' => now()->addMinutes(20),
            'confirmed_at' => now(),
        ]);

        $this->ledger->post($member, LedgerPosting::redemption(
            points: $redeemedPoints,
            redemptionId: (int) $redemption->id,
            idempotencyKey: 'redeem:'.self::ORDER.':'.$member->id,
            occurredAt: now(),
            shopifyOrderId: self::ORDER,
            orderName: '#'.self::ORDER,
        ));

        return [$member->refresh(), $earn, $redemption->refresh()];
    }

    public function test_the_worked_example_before_the_refund(): void
    {
        [$member] = $this->orderWithRedemption();

        $this->assertSame(80, $member->points_pending);
        $this->assertSame(60, $member->points_available, '260 opening less 200 redeemed.');
        // 60 points is below the hundred point threshold, so no voucher value.
        $this->assertSame(0, $member->voucher_balance_pence);
    }

    /** The worked example: a £20 refund of an £80 order. */
    public function test_a_partial_refund_reverses_and_restores_proportionally(): void
    {
        [$member, $earn, $redemption] = $this->orderWithRedemption();

        $result = $this->refunds->apply(
            account: $member,
            shopifyOrderId: self::ORDER,
            orderEligiblePence: 8000,
            cumulativeRefundedPence: 2000,
            shopifyRefundId: 77001,
        );

        $this->assertSame(20, $result['reversed'], '25% of 80 earned.');
        $this->assertSame(50, $result['restored'], '25% of 200 redeemed.');
        $this->assertFalse($result['full_refund']);

        $member->refresh();
        $this->assertSame(60, $member->points_pending, '80 earned less 20 reversed.');
        $this->assertSame(110, $member->points_available, '60 remaining plus 50 restored.');
        $this->assertSame(500, $member->voucher_balance_pence, '110 points is one five pound increment.');

        $reversal = LedgerEntry::query()->where('entry_type', 'earn_reversal')->sole();
        $this->assertSame(-20, $reversal->pending_delta, 'Pending first: these points never matured.');
        $this->assertSame(0, $reversal->available_delta);
        $this->assertSame($earn->id, $reversal->parent_entry_id);
        $this->assertSame(77001, (int) $reversal->shopify_refund_id);

        $restore = LedgerEntry::query()->where('entry_type', 'redemption_restore')->sole();
        $this->assertSame(50, $restore->available_delta);
        $this->assertSame((int) $redemption->id, (int) $restore->redemption_id);
    }

    /**
     * D9a. The restored points go back to the lot the redemption drew from,
     * as a negative allocation, so they keep the expiry they always had.
     */
    public function test_restored_points_return_to_their_original_lot(): void
    {
        [$member] = $this->orderWithRedemption();

        $opening = LedgerEntry::query()->where('entry_type', 'opening_balance')->sole();
        $redemptionEntry = LedgerEntry::query()->where('entry_type', 'redemption')->sole();

        $this->refunds->apply(
            account: $member,
            shopifyOrderId: self::ORDER,
            orderEligiblePence: 8000,
            cumulativeRefundedPence: 2000,
            shopifyRefundId: 77001,
        );

        $restore = LedgerEntry::query()->where('entry_type', 'redemption_restore')->sole();

        $release = LotAllocation::query()
            ->where('consuming_entry_id', $restore->id)
            ->sole();

        $this->assertSame($opening->id, (int) $release->lot_entry_id, 'The points went back where they came from.');
        $this->assertSame(-50, (int) $release->points, 'A release is a negative allocation row.');

        // The invariant: lot remaining = lot total less the net of allocations.
        $netAllocated = (int) LotAllocation::query()->where('lot_entry_id', $opening->id)->sum('points');
        $this->assertSame(150, $netAllocated, '200 drawn, 50 given back.');
        $this->assertSame(110, 260 - $netAllocated, 'The lot has 110 points left to give.');

        // No new lot was created, which is the whole point: a fresh lot would
        // carry a fresh expiry and let a redeem-refund cycle extend point life.
        $this->assertSame(
            1,
            LedgerEntry::query()->whereIn('entry_type', ['earn', 'opening_balance'])
                ->where('entry_type', 'opening_balance')->count(),
            'A restore must never create a lot of its own.',
        );
        $this->assertSame($redemptionEntry->id, $restore->parent_entry_id);
    }

    /** D9a: the restored points expire when they always would have, not later. */
    public function test_restored_points_keep_their_original_expiry(): void
    {
        [$member] = $this->orderWithRedemption();

        $opening = LedgerEntry::query()->where('entry_type', 'opening_balance')->sole();

        $this->refunds->apply(
            account: $member,
            shopifyOrderId: self::ORDER,
            orderEligiblePence: 8000,
            cumulativeRefundedPence: 2000,
            shopifyRefundId: 77001,
        );

        $restore = LedgerEntry::query()->where('entry_type', 'redemption_restore')->sole();

        $this->assertNull(
            $restore->expires_at,
            'A restore carries no expiry of its own; the lot it returns to still holds the date.',
        );

        $outlook = app(ExpiryOutlook::class)
            ->forAccount((int) $member->id, now(), now()->addMonths(5));

        $this->assertSame(110, $outlook['points'], 'The restored points expire with their original lot.');
        $this->assertSame(
            $opening->expires_at->toDateString(),
            Carbon::parse($outlook['next_expires_at'])->toDateString(),
        );
    }

    /** D9c, as three instalments rather than one. */
    public function test_three_partial_refunds_equal_one_refund_of_the_same_total(): void
    {
        [$member] = $this->orderWithRedemption();

        foreach ([[700, 88001], [1400, 88002], [2100, 88003]] as [$cumulative, $refundId]) {
            $this->refunds->apply(
                account: $member,
                shopifyOrderId: self::ORDER,
                orderEligiblePence: 8000,
                cumulativeRefundedPence: $cumulative,
                shopifyRefundId: $refundId,
            );
        }

        $reversed = -(int) LedgerEntry::query()->where('entry_type', 'earn_reversal')
            ->sum(DB::raw('pending_delta + available_delta'));
        $restored = (int) LedgerEntry::query()->where('entry_type', 'redemption_restore')->sum('available_delta');

        $this->assertSame(21, $reversed);
        $this->assertSame(
            52,
            $restored,
            'Per-refund flooring would have restored 51 and the member would be a point down.',
        );

        $member->refresh();
        $this->assertSame(59, $member->points_pending, '80 less 21.');
        $this->assertSame(112, $member->points_available, '60 remaining plus 52 restored.');
    }

    /** D9c: a full refund nets to exactly zero remaining, however it is reached. */
    public function test_a_full_refund_after_a_partial_nets_to_zero(): void
    {
        [$member] = $this->orderWithRedemption();

        $this->refunds->apply($member, self::ORDER, 8000, 2100, 88001);
        $result = $this->refunds->apply($member, self::ORDER, 8000, 8000, 88002);

        $this->assertTrue($result['full_refund']);
        $this->assertSame(80, $result['cumulative_reversed'], 'Every earned point reversed.');
        $this->assertSame(200, $result['cumulative_restored'], 'Every redeemed point restored.');

        $member->refresh();
        $this->assertSame(0, $member->points_pending, 'The whole earn is gone.');
        $this->assertSame(260, $member->points_available, 'The opening balance is back to where it started.');

        // And the lot is whole again: every allocation released.
        $opening = LedgerEntry::query()->where('entry_type', 'opening_balance')->sole();
        $this->assertSame(
            0,
            (int) LotAllocation::query()->where('lot_entry_id', $opening->id)->sum('points'),
            'A full restore returns the lot to untouched.',
        );
    }

    /** D9d: a partial refund records the value and leaves the state alone. */
    public function test_a_partial_refund_does_not_flip_the_redemption_state(): void
    {
        [$member, , $redemption] = $this->orderWithRedemption();

        $this->refunds->apply($member, self::ORDER, 8000, 2000, 77001);

        $redemption->refresh();

        $this->assertSame('confirmed', $redemption->state, 'Only a full reversal flips the state.');
        $this->assertSame(50, $redemption->points_restored);
        $this->assertSame(250, $redemption->amount_reversed_pence, '25% of the £10 voucher.');
        $this->assertNull($redemption->reversed_at);
        $this->assertTrue($redemption->isPartiallyReversed());
        $this->assertFalse($redemption->isFullyReversed());
        $this->assertSame(150, $redemption->pointsOutstanding());
    }

    /** D9d: and a full one does. */
    public function test_a_full_refund_flips_the_redemption_state(): void
    {
        [$member, , $redemption] = $this->orderWithRedemption();

        $this->refunds->apply($member, self::ORDER, 8000, 8000, 77002);

        $redemption->refresh();

        $this->assertSame('reversed', $redemption->state);
        $this->assertSame(200, $redemption->points_restored);
        $this->assertSame(1000, $redemption->amount_reversed_pence);
        $this->assertNotNull($redemption->reversed_at);
        $this->assertTrue($redemption->isFullyReversed());
        $this->assertSame(0, $redemption->pointsOutstanding());
    }

    /**
     * The same refund delivered twice must change nothing.
     *
     * Two independent guards catch it: the cumulative arithmetic computes an
     * increment of zero, and the idempotency key on each posting would refuse a
     * second row anyway.
     */
    public function test_the_same_refund_delivered_twice_posts_nothing_the_second_time(): void
    {
        [$member] = $this->orderWithRedemption();

        $this->refunds->apply($member, self::ORDER, 8000, 2000, 77001);

        $entriesAfterFirst = LedgerEntry::query()->count();
        $allocationsAfterFirst = LotAllocation::query()->count();

        $second = $this->refunds->apply($member, self::ORDER, 8000, 2000, 77001);

        $this->assertSame(0, $second['reversed']);
        $this->assertSame(0, $second['restored']);
        $this->assertSame($entriesAfterFirst, LedgerEntry::query()->count(), 'No second ledger row.');
        $this->assertSame($allocationsAfterFirst, LotAllocation::query()->count(), 'No second release row.');

        $member->refresh();
        $this->assertSame(60, $member->points_pending);
        $this->assertSame(110, $member->points_available);
    }

    /**
     * Q3: a clawback after the points were spent may take the balance negative.
     *
     * The ledger stays true and the customer's voucher value floors at zero.
     */
    public function test_a_reversal_after_maturity_may_drive_the_balance_negative(): void
    {
        $member = $this->member();

        $earn = $this->ledger->post($member, LedgerPosting::earn(
            points: 80,
            idempotencyKey: 'earn:matured',
            occurredAt: now()->subDays(40),
            maturesAt: now()->subDays(10),
            expiresAt: now()->addDays(172),
            shopifyOrderId: self::ORDER,
            qualifyingValuePence: 8000,
        ));

        $this->ledger->post($member, LedgerPosting::maturity(
            points: 80,
            parentEntryId: (int) $earn->id,
            idempotencyKey: 'mature:'.$earn->id,
            occurredAt: now()->subDays(10),
        ));

        // Spent, so there is nothing pending to take back.
        $this->ledger->post($member, LedgerPosting::adjustment(
            points: -80,
            bucket: 'available',
            reason: 'Spent elsewhere for the purposes of this test',
            idempotencyKey: 'adj:spend',
            occurredAt: now()->subDays(5),
        ));

        $this->refunds->apply($member, self::ORDER, 8000, 8000, 77003);

        $member->refresh();

        $this->assertSame(0, $member->points_pending);
        $this->assertSame(-80, $member->points_available, 'The ledger records the truth.');
        $this->assertSame(0, $member->voucher_balance_pence, 'The customer never sees a negative reward.');

        $reversal = LedgerEntry::query()->where('entry_type', 'earn_reversal')->sole();
        $this->assertSame(0, $reversal->pending_delta);
        $this->assertSame(-80, $reversal->available_delta, 'Nothing pending was left, so it came from available.');
    }

    /**
     * D9a needs loyalty_lot_allocations.points to be SIGNED, and this suite
     * cannot see whether it is.
     *
     * The column began as unsignedInteger. On MySQL that rejects a release with
     * "Out of range value for column 'points'" before the CHECK constraint is
     * ever consulted; SQLite does not enforce unsigned at all, so every other
     * test in this file passes on either column type and only production would
     * have found out.
     *
     * Two guards, so neither connection is trusted alone: on MySQL the live
     * column type, and everywhere the migration source that sets it. The second
     * is what keeps a SQLite-only CI run honest.
     */
    public function test_the_allocation_points_column_is_signed(): void
    {
        // up() only. down() legitimately puts the unsigned back, and reading
        // the whole file would fail on a migration that is perfectly correct.
        $upMethods = collect(glob(database_path('migrations/*.php')))
            ->map(function (string $path): string {
                $source = (string) file_get_contents($path);
                $down = strpos($source, 'public function down(');

                return $down === false ? $source : substr($source, 0, $down);
            })
            ->filter(fn (string $up): bool => str_contains($up, "table('loyalty_lot_allocations'"));

        $this->assertTrue(
            $upMethods->contains(fn (string $up): bool => str_contains($up, "\$table->integer('points')->change()")),
            'No migration makes loyalty_lot_allocations.points signed. A release is a '
            .'negative allocation row, and MySQL rejects it as out of range before the '
            .'CHECK constraint is ever consulted.',
        );
        $this->assertFalse(
            $upMethods->contains(fn (string $up): bool => str_contains($up, "unsignedInteger('points')->change()")),
            'A migration has put the unsigned column type back, which breaks D9a on MySQL.',
        );

        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $column = collect(Schema::getColumns('loyalty_lot_allocations'))->firstWhere('name', 'points');

        $this->assertNotNull($column, 'loyalty_lot_allocations.points is missing.');
        $this->assertStringNotContainsString(
            'unsigned',
            strtolower((string) $column['type']),
            'A release is a negative allocation, so this column must be signed.',
        );
    }

    public function test_an_order_with_no_redemption_reverses_earning_only(): void
    {
        $member = $this->member();

        $this->ledger->post($member, LedgerPosting::earn(
            points: 80,
            idempotencyKey: 'earn:plain',
            occurredAt: now(),
            maturesAt: now()->addDays(30),
            expiresAt: now()->addDays(212),
            shopifyOrderId: self::ORDER,
            qualifyingValuePence: 8000,
        ));

        $result = $this->refunds->apply($member, self::ORDER, 8000, 2000, 77004);

        $this->assertSame(20, $result['reversed']);
        $this->assertSame(0, $result['restored']);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'redemption_restore')->count());
    }
}
