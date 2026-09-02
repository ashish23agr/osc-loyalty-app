<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row per Privilege Club member.
 *
 * The cached point totals here are disposable: `loyalty:rebuild-balances`
 * reconstructs all of them from the ledger, and if a cache and the ledger ever
 * disagree the ledger wins.
 *
 * Normalisation happens on save rather than at the call site, so every writer
 * (storefront enrolment, the till, the console, migration) produces the same
 * match key. The unique index on `email_normalised` is the enforcement of D10.
 */
class LoyaltyAccount extends Model
{
    protected $fillable = [
        'shop_domain', 'shopify_customer_id', 'email', 'first_name', 'last_name',
        'postcode', 'date_of_birth', 'dob_month', 'dob_day', 'gender',
        'legacy_card_number', 'enrolment_channel', 'enrolled_at', 'segment',
        'last_qualifying_spend_at', 'email_marketing_consent', 'consent_updated_at',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'enrolled_at' => 'datetime',
        'segment_calculated_at' => 'datetime',
        'last_qualifying_spend_at' => 'datetime',
        'caches_rebuilt_at' => 'datetime',
        'consent_updated_at' => 'datetime',
        'email_marketing_consent' => 'boolean',
        'points_pending' => 'integer',
        'points_available' => 'integer',
        'points_lifetime' => 'integer',
        'voucher_balance_pence' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $account): void {
            $account->email_normalised = self::normaliseEmail($account->email);
            $account->postcode_normalised = self::normalisePostcode($account->postcode);

            // A full date of birth fills the day and month; a member who gave
            // only a day and month keeps what was written directly. The
            // birthday job reads dob_month and dob_day and never
            // date_of_birth, so a member with no birth year is eligible on
            // exactly the same terms as one with a full date.
            if ($account->date_of_birth) {
                $account->dob_month = (int) $account->date_of_birth->format('n');
                $account->dob_day = (int) $account->date_of_birth->format('j');
            }
        });
    }

    public static function normaliseEmail(?string $email): ?string
    {
        $email = trim((string) $email);

        return $email === '' ? null : mb_strtolower($email);
    }

    /** Upper-cased with all whitespace removed, so "ox1 2jd" and "OX12JD" match. */
    public static function normalisePostcode(?string $postcode): ?string
    {
        $postcode = preg_replace('/\s+/', '', (string) $postcode);

        return $postcode === '' ? null : mb_strtoupper($postcode);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'loyalty_account_id');
    }

    public function rewards(): HasMany
    {
        return $this->hasMany(Reward::class, 'loyalty_account_id');
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(Redemption::class, 'loyalty_account_id');
    }

    public function isMerged(): bool
    {
        return $this->status === 'merged';
    }

    /**
     * A member with no email address on record.
     *
     * Client-confirmed 2026-08-06: these migrate and must stay searchable, so
     * they are a supported state rather than a data defect. Identity for them
     * rests on the card number, then postcode plus surname, and nothing may
     * assume an email is present.
     */
    public function hasNoEmail(): bool
    {
        return $this->email_normalised === null;
    }

    /** True once a birthday is known, whether or not the birth year is. */
    public function hasBirthday(): bool
    {
        return $this->dob_month !== null && $this->dob_day !== null;
    }
}
