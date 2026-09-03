<?php

namespace Tests\Feature;

use App\Domain\Loyalty\LedgerPosting;
use App\Domain\Loyalty\LedgerService;
use App\Domain\Orders\OrderSource;
use App\Domain\Redemption\DiscountFunctionGateway;
use App\Domain\Redemption\MetafieldWriter;
use App\Domain\Redemption\PosCartDiscountGateway;
use App\Domain\Redemption\QuoteExpirySweep;
use App\Domain\Redemption\RedemptionService;
use App\Domain\Rules\RulesVersionRepository;
use App\Models\LedgerEntry;
use App\Models\LoyaltyAccount;
use App\Models\Redemption;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeMetafieldWriter;
use Tests\Support\FakeOrderSource;
use Tests\TestCase;

/**
 * Quotes that were never paid for (M6, M7).
 *
 * A quote says it stands for twenty minutes, and until this existed nothing
 * made that true. The cost was not untidiness: an online quote publishes an
 * entitlement the discount function reads, and an entitlement outliving the
 * points behind it is the one way a redemption could spend points nobody has.
 */
class QuoteExpiryTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'loyalty-system.myshopify.com';

    private FakeMetafieldWriter $metafields;

    protected function setUp(): void
    {
        parent::setUp();

        app(RulesVersionRepository::class)->seedDefaults(self::SHOP);

        $this->metafields = new FakeMetafieldWriter;
        $this->app->instance(MetafieldWriter::class, $this->metafields);
        $this->app->instance(OrderSource::class, new FakeOrderSource);
    }

    private function member(int $customerId = 5001, int $points = 1000): LoyaltyAccount
    {
        $member = LoyaltyAccount::create([
            'shop_domain' => self::SHOP,
            'shopify_customer_id' => $customerId,
            'email' => 'member'.$customerId.'@example.com',
            'enrolment_channel' => 'online',
            'enrolled_at' => now()->subYear(),
        ]);

        app(LedgerService::class)->post($member, LedgerPosting::openingBalance(
            points: $points,
            idempotencyKey: 'open:'.$member->id,
            occurredAt: now()->subMonths(2),
            expiresAt: now()->addMonths(4),
            reason: 'Migrated balance',
        ));

        return $member->refresh();
    }

    private function hold(LoyaltyAccount $member, string $channel = 'online'): Redemption
    {
        return app(RedemptionService::class)->hold(
            account: $member,
            gateway: $channel === 'pos'
                ? app(PosCartDiscountGateway::class)
                : app(DiscountFunctionGateway::class),
            eligibleSubtotalPence: 6000,
            basketTotalPence: 10000,
            channel: $channel,
            // V18: a POS hold is refused unless the till states its currency.
            // Only meaningful on the POS branch; harmless on the online one.
            tillCurrency: 'GBP',
        )['redemption'];
    }

    // ------------------------------------------------------------------ expiry

    public function test_a_quote_past_its_expiry_is_voided(): void
    {
        $member = $this->member();
        $quote = $this->hold($member);

        $counts = app(QuoteExpirySweep::class)->run(self::SHOP, now()->addMinutes(21));

        $this->assertSame(1, $counts['expired']);
        $this->assertSame('void', $quote->refresh()->state);
    }

    /** The point of the whole sweep: the entitlement stops being readable. */
    public function test_the_published_entitlement_is_withdrawn(): void
    {
        $member = $this->member();
        $this->hold($member);

        $this->assertNotNull(
            $this->metafields->readFor(self::SHOP, 5001),
            'The quote published something in the first place.',
        );

        app(QuoteExpirySweep::class)->run(self::SHOP, now()->addMinutes(21));

        $this->assertNull(
            $this->metafields->readFor(self::SHOP, 5001),
            'The discount function can no longer read an entitlement.',
        );
    }

    public function test_a_quote_still_inside_its_window_is_left_alone(): void
    {
        $member = $this->member();
        $quote = $this->hold($member);

        $counts = app(QuoteExpirySweep::class)->run(self::SHOP, now()->addMinutes(5));

        $this->assertSame(0, $counts['expired']);
        $this->assertSame('quoted', $quote->refresh()->state);
        $this->assertNotNull($this->metafields->readFor(self::SHOP, 5001));
    }

    /** Voiding a quote costs the member nothing, because it never spent anything. */
    public function test_expiry_takes_no_points(): void
    {
        $member = $this->member();
        $this->hold($member);

        app(QuoteExpirySweep::class)->run(self::SHOP, now()->addMinutes(21));

        $member->refresh();
        $this->assertSame(1000, $member->points_available);
        $this->assertSame(5000, $member->voucher_balance_pence);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'redemption')->count());
    }

    public function test_a_confirmed_redemption_is_never_touched(): void
    {
        $member = $this->member();
        $quote = $this->hold($member);

        app(RedemptionService::class)->confirm($member, $quote, shopifyOrderId: 1042);

        $counts = app(QuoteExpirySweep::class)->run(self::SHOP, now()->addMinutes(21));

        $this->assertSame(0, $counts['expired']);
        $this->assertSame('confirmed', $quote->refresh()->state);
    }

    /** A till quote published nothing, so there is nothing to take back. */
    public function test_a_till_quote_expires_without_a_withdrawal(): void
    {
        $member = $this->member();
        $quote = $this->hold($member, channel: 'pos');

        $counts = app(QuoteExpirySweep::class)->run(self::SHOP, now()->addMinutes(21));

        $this->assertSame(1, $counts['expired']);
        $this->assertSame(0, $counts['withdrawn']);
        $this->assertSame('void', $quote->refresh()->state);
    }

    public function test_the_sweep_is_idempotent(): void
    {
        $member = $this->member();
        $this->hold($member);

        app(QuoteExpirySweep::class)->run(self::SHOP, now()->addMinutes(21));
        $second = app(QuoteExpirySweep::class)->run(self::SHOP, now()->addMinutes(30));

        $this->assertSame(0, $second['expired'], 'Nothing left to expire.');
    }

    /**
     * The case the sweep exists for.
     *
     * A member's points expire while a quote is still published. Without the
     * sweep the entitlement stays readable and confirming it would spend points
     * that are gone, driving the balance negative — which is not what Q3
     * permits, because that is a redemption rather than a clawback.
     */
    public function test_an_entitlement_cannot_outlive_the_points_behind_it(): void
    {
        $member = $this->member();
        $this->hold($member);

        // The points expire overnight.
        $lot = LedgerEntry::query()->where('entry_type', 'opening_balance')->sole();

        app(LedgerService::class)->post($member, LedgerPosting::expiry(
            points: 1000,
            parentEntryId: (int) $lot->id,
            idempotencyKey: 'expire:'.$lot->id,
            occurredAt: now()->addHours(2),
        ));

        $this->assertSame(0, $member->refresh()->points_available);

        // The sweep runs before anyone could check out on the stale entitlement.
        app(QuoteExpirySweep::class)->run(self::SHOP, now()->addMinutes(21));

        $this->assertNull(
            $this->metafields->readFor(self::SHOP, 5001),
            'Nothing is left for the function to offer against points that are gone.',
        );
        $this->assertSame(0, $member->refresh()->points_available, 'And the balance never went negative.');
    }

    // ------------------------------------------------------------------ command

    public function test_the_command_runs_and_reports(): void
    {
        $member = $this->member();
        $this->hold($member);

        $this->artisan('loyalty:expire-quotes', ['--as-of' => now()->addMinutes(21)->toDateTimeString()])
            ->assertSuccessful();

        $this->assertSame('void', Redemption::query()->sole()->state);
    }

    public function test_it_is_on_the_schedule(): void
    {
        $commands = [];

        foreach (app(Schedule::class)->events() as $event) {
            $commands[(string) $event->command] = $event->expression;
        }

        $expression = collect($commands)
            ->first(fn ($e, $c) => str_contains((string) $c, 'loyalty:expire-quotes'));

        $this->assertSame('*/5 * * * *', $expression, 'A short-lived quote needs a frequent sweep.');
    }
}
