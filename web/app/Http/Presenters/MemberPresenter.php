<?php

namespace App\Http\Presenters;

use App\Domain\Loyalty\BalanceCalculator;
use App\Domain\Loyalty\ExpiryOutlook;
use App\Domain\Rules\RulesVersionRepository;
use App\Models\LedgerEntry;
use App\Models\LoyaltyAccount;
use App\Models\Reward;
use App\Support\Members\MemberCardNumber;
use Illuminate\Support\Carbon;

/**
 * A member as the console reads them.
 *
 * Two shapes, because the customers list and the customer profile want very
 * different amounts of work per row. row() is a list line and touches no table
 * but loyalty_accounts, so a page of fifty costs one query. profile() answers
 * the whole profile screen in one call: header, balances, the voucher figures,
 * lifetime spend and the vouchers tab, leaving only the paginated ledger to
 * fetch separately.
 *
 * The voucher figures are derived here rather than read from a column, per D1:
 * a member reward value is a function of their available points and the rule
 * version, so there is nothing issued and nothing to reconcile.
 */
final class MemberPresenter
{
    public function __construct(
        private readonly RulesVersionRepository $rules,
        private readonly ExpiryOutlook $expiry,
    ) {}

    /** One line in the customers list. */
    public function row(LoyaltyAccount $account): array
    {
        $voucher = BalanceCalculator::derive(
            (int) $account->points_available,
            $this->rules->current($account->shop_domain),
        );

        return [
            'id' => (int) $account->id,
            'card_number' => MemberCardNumber::format((int) $account->id),
            'legacy_card_number' => $account->legacy_card_number,
            'shopify_customer_id' => $account->shopify_customer_id === null
                ? null
                : (int) $account->shopify_customer_id,
            'email' => $account->email,
            // Explicit rather than inferred from a null email, because a member
            // with no email is a supported state (MD1) and the list must say so.
            'has_no_email' => $account->hasNoEmail(),
            'first_name' => $account->first_name,
            'last_name' => $account->last_name,
            'display_name' => self::displayName($account),
            'postcode' => $account->postcode,
            'segment' => $account->segment,
            'status' => $account->status,
            'enrolment_channel' => $account->enrolment_channel,
            'enrolled_at' => $account->enrolled_at?->toIso8601String(),
            'points_available' => (int) $account->points_available,
            'points_pending' => (int) $account->points_pending,
            'voucher_balance_pence' => $voucher->pence(),
        ];
    }

    /** Everything the customer profile screen needs, less the paged ledger. */
    public function profile(LoyaltyAccount $account): array
    {
        $rules = $this->rules->current($account->shop_domain);
        $voucher = BalanceCalculator::derive((int) $account->points_available, $rules);

        $spend = LedgerEntry::query()
            ->where('loyalty_account_id', $account->id)
            ->where('entry_type', 'earn')
            ->selectRaw('coalesce(sum(qualifying_value_pence), 0) as spend, max(occurred_at) as last_purchase')
            ->first();

        // The soonest date any pending points become spendable. Read from the
        // earn rows rather than a column, so it is right even if the maturity
        // job is behind.
        $clearsAt = LedgerEntry::query()
            ->where('loyalty_account_id', $account->id)
            ->where('entry_type', 'earn')
            ->where('matures_at', '>', now())
            ->min('matures_at');

        $window = $this->expiry->forAccount(
            (int) $account->id,
            now(),
            now()->addDays($rules->expiryWarningDays()),
        );

        return $this->row($account) + [
            'shop_domain' => $account->shop_domain,
            'date_of_birth' => $account->date_of_birth?->toDateString(),
            'dob_month' => $account->dob_month === null ? null : (int) $account->dob_month,
            'dob_day' => $account->dob_day === null ? null : (int) $account->dob_day,
            'has_birthday' => $account->hasBirthday(),
            'gender' => $account->gender,
            'email_marketing_consent' => $account->email_marketing_consent,
            'consent_updated_at' => $account->consent_updated_at?->toIso8601String(),
            'segment_calculated_at' => $account->segment_calculated_at?->toIso8601String(),
            'last_qualifying_spend_at' => $account->last_qualifying_spend_at?->toIso8601String(),
            'is_merged' => $account->isMerged(),
            'merged_into_account_id' => $account->merged_into_account_id === null
                ? null
                : (int) $account->merged_into_account_id,
            'balances' => [
                'points_pending' => (int) $account->points_pending,
                'points_available' => (int) $account->points_available,
                'points_lifetime' => (int) $account->points_lifetime,
                'voucher_balance_pence' => $voucher->pence(),
                'voucher_increments' => $voucher->increments,
                // The profile header reads "300 points used, 40 carried
                // forward, 60 to the next five pounds". All three come from one
                // derivation, so they cannot disagree with each other.
                'points_committed_to_voucher' => $voucher->increments * $rules->voucherThresholdPoints(),
                'remainder_points' => $voucher->remainderPoints,
                'points_to_next_increment' => $voucher->pointsToNextIncrement,
                'caches_rebuilt_at' => $account->caches_rebuilt_at?->toIso8601String(),
            ],
            'lifetime_spend_pence' => (int) ($spend->spend ?? 0),
            'last_purchase_at' => $spend === null || $spend->last_purchase === null
                ? null
                : self::iso((string) $spend->last_purchase),
            'pending_clears_at' => $clearsAt === null ? null : self::iso((string) $clearsAt),
            'expiring_soon' => [
                'points' => $window['points'],
                'next_expires_at' => $window['next_expires_at'] === null
                    ? null
                    : self::iso($window['next_expires_at']),
                'window_days' => $rules->expiryWarningDays(),
            ],
            'rewards' => $this->rewards($account),
            'rules' => [
                'version' => $rules->version,
                'voucher_threshold_points' => $rules->voucherThresholdPoints(),
                'voucher_value_pence' => $rules->voucherValuePence(),
                'pending_period_days' => $rules->pendingPeriodDays(),
                'expiry_warning_days' => $rules->expiryWarningDays(),
                'max_redemption_per_order_pence' => $rules->maxRedemptionPerOrderPence(),
            ],
        ];
    }

    /**
     * The vouchers tab.
     *
     * Only genuinely issued objects appear here: the birthday reward and any
     * goodwill voucher. The points-derived value is in balances, because per D1
     * it is not an issued object and has no expiry of its own.
     */
    private function rewards(LoyaltyAccount $account): array
    {
        return Reward::query()
            ->where('loyalty_account_id', $account->id)
            ->orderByDesc('issued_at')
            ->get()
            ->map(fn (Reward $reward): array => [
                'id' => (int) $reward->id,
                'reward_type' => $reward->reward_type,
                'value_pence' => (int) $reward->value_pence,
                'currency' => $reward->currency,
                'state' => $reward->state,
                'birthday_year' => $reward->birthday_year,
                'issued_at' => $reward->issued_at?->toIso8601String(),
                'expires_at' => $reward->expires_at?->toIso8601String(),
                'redeemed_at' => $reward->redeemed_at?->toIso8601String(),
                'cancelled_at' => $reward->cancelled_at?->toIso8601String(),
                'cancelled_reason' => $reward->cancelled_reason,
            ])
            ->all();
    }

    public static function displayName(LoyaltyAccount $account): ?string
    {
        $name = trim(($account->first_name ?? '').' '.($account->last_name ?? ''));

        return $name === '' ? null : $name;
    }

    /** A raw aggregate comes back as a string on both engines. */
    private static function iso(string $value): string
    {
        return Carbon::parse($value)->toIso8601String();
    }
}
