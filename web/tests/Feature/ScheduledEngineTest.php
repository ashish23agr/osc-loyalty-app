<?php

namespace Tests\Feature;

use App\Domain\Loyalty\LedgerPosting;
use App\Domain\Loyalty\LedgerService;
use App\Domain\Rules\RulesVersionRepository;
use App\Models\LedgerEntry;
use App\Models\LoyaltyAccount;
use App\Models\Reward;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint 2's exit criterion is that the engine moves "with nobody running
 * anything". A sweep that works but is not scheduled meets none of it, so the
 * schedule itself is under test.
 *
 * This is the check that would have caught the thing PROGRESS.md warned about:
 * "Nothing runs them today."
 */
class ScheduledEngineTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'loyalty-system.myshopify.com';

    /** @return array<string, Event> */
    private function scheduled(): array
    {
        $events = [];

        foreach (app(Schedule::class)->events() as $event) {
            $events[$event->command ?? $event->description ?? ''] = $event;
        }

        return $events;
    }

    public function test_every_sweep_is_on_the_schedule(): void
    {
        $commands = array_keys($this->scheduled());

        foreach ([
            'loyalty:mature-points',
            'loyalty:expire-points',
            'loyalty:issue-birthday-rewards',
            'loyalty:recalculate-segments',
            'loyalty:verify-ledger',
        ] as $command) {
            $this->assertTrue(
                collect($commands)->contains(fn (string $c): bool => str_contains($c, $command)),
                $command.' is not scheduled, so nothing would ever run it.',
            );
        }
    }

    public function test_the_sweeps_run_at_the_intended_cadence(): void
    {
        $expressions = [];

        foreach (app(Schedule::class)->events() as $event) {
            $expressions[$event->command] = $event->expression;
        }

        $find = fn (string $needle): string => collect($expressions)
            ->first(fn ($e, $c) => str_contains((string) $c, $needle)) ?? '';

        $this->assertSame('0 * * * *', $find('loyalty:mature-points'), 'Maturity is hourly.');
        $this->assertSame('10 2 * * *', $find('loyalty:expire-points'), 'Expiry is daily.');
        $this->assertSame('20 0 * * *', $find('loyalty:issue-birthday-rewards'));
        $this->assertSame('0 3 * * *', $find('loyalty:recalculate-segments'));
        $this->assertSame('0 4 * * *', $find('loyalty:verify-ledger'));
    }

    /**
     * Expiry must run after maturity on a day both are due, or points that
     * mature and expire on the same date would survive a day too long.
     */
    public function test_expiry_is_scheduled_after_the_daily_maturity_run(): void
    {
        $expressions = [];

        foreach (app(Schedule::class)->events() as $event) {
            $expressions[$event->command] = $event->expression;
        }

        $expiry = collect($expressions)->first(fn ($e, $c) => str_contains((string) $c, 'loyalty:expire-points'));
        [$minute, $hour] = explode(' ', (string) $expiry);

        // Maturity is hourly, so it has already run at the top of the hour that
        // expiry starts within.
        $this->assertGreaterThan(0, (int) $minute, 'Expiry must not collide with the hourly maturity run.');
        $this->assertSame(2, (int) $hour);
    }

    public function test_every_sweep_refuses_to_overlap_itself(): void
    {
        foreach (app(Schedule::class)->events() as $event) {
            if (! str_contains((string) $event->command, 'loyalty:')) {
                continue;
            }

            $this->assertNotNull(
                $event->mutexName(),
                $event->command.' may overlap a previous run that has not finished.',
            );
            $this->assertTrue(
                $event->withoutOverlapping,
                $event->command.' does not declare withoutOverlapping().',
            );
        }
    }

    // The commands themselves, end to end through artisan.

    public function test_the_maturity_command_matures_points(): void
    {
        app(RulesVersionRepository::class)->seedDefaults(self::SHOP);
        $member = $this->member();

        app(LedgerService::class)->post($member, LedgerPosting::earn(
            points: 100,
            idempotencyKey: 'earn:cmd',
            occurredAt: now()->subDays(31),
            maturesAt: now()->subDay(),
            expiresAt: now()->addDays(181),
        ));

        $this->artisan('loyalty:mature-points')->assertSuccessful();

        $this->assertSame(100, $member->refresh()->points_available);
    }

    public function test_the_expiry_command_expires_points(): void
    {
        app(RulesVersionRepository::class)->seedDefaults(self::SHOP);
        $member = $this->member();

        app(LedgerService::class)->post($member, LedgerPosting::openingBalance(
            points: 100,
            idempotencyKey: 'open:cmd',
            occurredAt: now()->subMonths(7),
            expiresAt: now()->subDay(),
            reason: 'Migrated balance',
        ));

        $this->artisan('loyalty:expire-points')->assertSuccessful();

        $this->assertSame(0, $member->refresh()->points_available);
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'expiry')->count());
    }

    public function test_the_birthday_command_issues_rewards(): void
    {
        app(RulesVersionRepository::class)->seedDefaults(self::SHOP);
        $member = $this->member(['dob_month' => 3, 'dob_day' => 4]);

        $this->artisan('loyalty:issue-birthday-rewards', ['--as-of' => '2027-03-04'])
            ->assertSuccessful();

        $this->assertSame(1, Reward::query()->where('loyalty_account_id', $member->id)->count());
    }

    public function test_the_segmentation_command_recalculates(): void
    {
        app(RulesVersionRepository::class)->seedDefaults(self::SHOP);
        $member = $this->member();

        $this->artisan('loyalty:recalculate-segments')->assertSuccessful();

        $this->assertSame('unknown', $member->refresh()->segment);
        $this->assertNotNull($member->segment_calculated_at);
    }

    /** A shop with no members is not an error, just nothing to do. */
    public function test_the_sweeps_are_quiet_when_there_is_nothing_to_sweep(): void
    {
        $this->artisan('loyalty:mature-points')->assertSuccessful();
        $this->artisan('loyalty:expire-points')->assertSuccessful();
        $this->artisan('loyalty:recalculate-segments')->assertSuccessful();
        $this->artisan('loyalty:issue-birthday-rewards')->assertSuccessful();
    }

    /** --shop scopes a sweep to one tenant and leaves the others alone. */
    public function test_a_sweep_can_be_scoped_to_one_shop(): void
    {
        app(RulesVersionRepository::class)->seedDefaults(self::SHOP);
        app(RulesVersionRepository::class)->seedDefaults('other.myshopify.com');

        $mine = $this->member();
        $theirs = LoyaltyAccount::create([
            'shop_domain' => 'other.myshopify.com',
            'email' => 'other'.uniqid().'@example.com',
            'enrolment_channel' => 'online',
            'enrolled_at' => now(),
        ]);

        $this->artisan('loyalty:recalculate-segments', ['--shop' => self::SHOP])->assertSuccessful();

        $this->assertNotNull($mine->refresh()->segment_calculated_at);
        $this->assertNull($theirs->refresh()->segment_calculated_at, 'The other shop was left alone.');
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
}
