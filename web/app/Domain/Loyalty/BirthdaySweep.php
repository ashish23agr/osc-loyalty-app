<?php

namespace App\Domain\Loyalty;

use App\Domain\Events\LoyaltyEventName;
use App\Domain\Events\MemberEvents;
use App\Domain\Rules\RulesVersionRepository;
use App\Models\LoyaltyAccount;
use App\Models\Reward;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * The £10 birthday reward, issued once a year (M5, MD2, MD8, C4).
 *
 * C4, confirmed by the client on 31 Aug 2026: **lapsed members receive it.**
 * Eligibility is date of birth on record and nothing else - there is no
 * Active/Lapsed check anywhere in this class, and there must not be one.
 * Segment governs marketing and reporting; it does not gate the reward. A
 * lapsed member still has a birthday, and this is the mechanism most likely to
 * bring them back, so it is worth most on exactly the members a segment filter
 * would have excluded.
 *
 * Account STATUS is a different question and is checked: a suspended, closed or
 * merged account is not a member in good standing and receives nothing. Do not
 * let that read as a segment check.
 *
 * MD2: eligibility reads dob_month and dob_day and never date_of_birth, so a
 * member who gave only a day and month is eligible on identical terms.
 *
 * MD8: the client accepts possible duplicate vouchers in year one, so there is
 * no migration-year suppression. The only guard is uq_reward_birthday, which
 * makes a re-run harmless without the job keeping any bookkeeping of its own.
 */
final class BirthdaySweep
{
    public function __construct(
        private readonly RulesVersionRepository $rules,
        private readonly MemberEvents $events,
    ) {}

    /**
     * @return array{issued:int, already_held:int, no_birthday:int, day:string}
     */
    public function run(string $shopDomain, ?DateTimeInterface $asOf = null, int $chunk = 500): array
    {
        $rules = $this->rules->current($shopDomain);

        // The member's birthday is a date in OSC's day, not in UTC. Run at one
        // minute past midnight UTC in late June and the server is already on
        // tomorrow's date in London; taking the day from the reporting timezone
        // is what stops a member being a day early or a day late.
        $today = $asOf === null
            ? now($rules->reportingTimezone())
            : Carbon::parse($asOf)->setTimezone($rules->reportingTimezone());

        $issued = 0;
        $alreadyHeld = 0;

        foreach ($this->birthdaysOn($today) as [$month, $day]) {
            LoyaltyAccount::query()
                ->where('shop_domain', $shopDomain)
                ->where('status', 'active')
                ->where('dob_month', $month)
                ->where('dob_day', $day)
                ->orderBy('id')
                ->chunkById($chunk, function ($members) use ($rules, $today, &$issued, &$alreadyHeld): void {
                    foreach ($members as $member) {
                        try {
                            $reward = Reward::create([
                                'shop_domain' => $member->shop_domain,
                                'loyalty_account_id' => $member->id,
                                'reward_type' => 'birthday',
                                'value_pence' => $rules->birthdayRewardPence(),
                                'currency' => $rules->currency(),
                                'birthday_year' => (int) $today->year,
                                'state' => 'issued',
                                'issued_at' => now(),
                                'expires_at' => now()->addDays($rules->birthdayRewardExpiryDays()),
                                'issued_by_staff_id' => null,
                                'rules_version_id' => $rules->versionId,
                            ]);

                            $issued++;

                            // M5: Klaviyo owns the message. This says only that
                            // it happened, to whom, and what it is worth.
                            $this->events->emit($member, LoyaltyEventName::BIRTHDAY_REWARD_ISSUED, [
                                'reward_id' => (int) $reward->id,
                                'value_pence' => (int) $reward->value_pence,
                                'expires_at' => $reward->expires_at?->toIso8601String(),
                                'birthday_year' => (int) $reward->birthday_year,
                            ]);
                        } catch (UniqueConstraintViolationException) {
                            // uq_reward_birthday did its job. The member
                            // already has this year's reward, which is the
                            // whole point of enforcing it in the index rather
                            // than in a query this job would have to remember
                            // to run.
                            $alreadyHeld++;
                        }
                    }
                });
        }

        return [
            'issued' => $issued,
            'already_held' => $alreadyHeld,
            // Counted rather than silently ignored: M5 asks for it, and a
            // number that climbs is how OSC would notice enrolment forms
            // dropping the birthday field.
            'no_birthday' => (int) LoyaltyAccount::query()
                ->where('shop_domain', $shopDomain)
                ->where('status', 'active')
                ->where(fn ($q) => $q->whereNull('dob_month')->orWhereNull('dob_day'))
                ->count(),
            'day' => $today->format('Y-m-d'),
        ];
    }

    /**
     * The (month, day) pairs eligible on this date.
     *
     * Normally one. On 28 February in a non-leap year it is two: a member born
     * on 29 February has a birthday the calendar does not offer, and waiting
     * for the next leap year would mean issuing their reward once every four
     * years. They are given it on the 28th, which is the convention almost
     * everything else uses and the only option that keeps the annual promise.
     *
     * @return list<array{0:int, 1:int}>
     */
    private function birthdaysOn(Carbon $today): array
    {
        $days = [[(int) $today->month, (int) $today->day]];

        if ((int) $today->month === 2 && (int) $today->day === 28 && ! $today->isLeapYear()) {
            $days[] = [2, 29];
        }

        return $days;
    }
}
