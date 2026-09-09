<?php

namespace App\Jobs;

use App\Domain\Redemption\MetafieldWriter;
use App\Models\LoyaltyAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Publish the member's standing position to their Shopify customer record (M4).
 *
 * The derived voucher balance lives in this app and nothing outside it can see
 * a balance without being told. M4 makes the customer metafield the way it is
 * told: it is the only route by which the discount function and the customer
 * account page can read what a member is entitled to.
 *
 * **It is a cache, not a source.** The ledger is the source, the account's
 * cached columns are reconciled against it by `loyalty:verify-ledger`, and this
 * is a third copy that exists only because Shopify cannot query us. A stale
 * value here is a display problem; a wrong value in the ledger is a real one.
 * Nothing may ever read this back and treat it as authoritative.
 *
 * **One JSON metafield rather than the five typed ones in plan section 6.3, and
 * that is a deliberate deviation.** `MetafieldWriter` writes JSON, which is what
 * V4 proved a discount function can read, and typed metafield *definitions* are
 * an install-time concern that does not exist yet. Adding a typed-write path to
 * the interface to publish `number_integer` would be building the definition
 * machinery ahead of any consumer. When V11 spikes the customer account page and
 * says what shape it needs, that is the moment to decide - not now.
 *
 * Dispatched from `BalanceCalculator::refreshCache()`, which is the single
 * chokepoint every ledger posting passes through, and only when the balance has
 * actually moved. That is what makes it "once per balance change, not per ledger
 * entry": a refund posts two entries and moves the voucher balance once.
 */
class PublishBalanceMetafieldJob implements ShouldQueue
{
    use Queueable;

    /** The app-reserved namespace is used, so only the key is ours to choose. */
    public const METAFIELD_KEY = 'member';

    public function __construct(public readonly int $accountId) {}

    public function handle(MetafieldWriter $metafields): void
    {
        $account = LoyaltyAccount::query()->find($this->accountId);

        if ($account === null) {
            return;
        }

        // A merged account is not a member any more; the survivor carries the
        // balance and publishes its own. Writing here would leave two customer
        // records both claiming the same entitlement.
        if ($account->isMerged()) {
            return;
        }

        // No Shopify customer, nowhere to publish. A till-enrolled member with
        // no online identity is a supported member (MD1), not a failure, so this
        // is quiet rather than logged.
        if ($account->shopify_customer_id === null) {
            return;
        }

        $written = $metafields->write(
            $account->shop_domain,
            'gid://shopify/Customer/'.$account->shopify_customer_id,
            self::METAFIELD_KEY,
            self::payloadFor($account),
        );

        if (! $written) {
            // Worth a line: the member's own view of their balance goes stale
            // and nothing else in the system notices, because every internal
            // reader uses the ledger.
            Log::warning('Could not publish the loyalty balance metafield', [
                'shop' => $account->shop_domain,
                'account' => $account->id,
                'customer' => $account->shopify_customer_id,
            ]);
        }
    }

    /**
     * What gets published.
     *
     * Kept static and pure so a test can assert the shape without a queue, and
     * so the eventual customer account page has one place to read the contract
     * from rather than inferring it from a live shop.
     *
     * @return array<string, mixed>
     */
    public static function payloadFor(LoyaltyAccount $account): array
    {
        return [
            // The figure the member is shown. Pence, like everywhere else, so no
            // reader has to guess whether 150 means pounds or pence.
            'voucher_balance_pence' => (int) $account->voucher_balance_pence,
            'points_available' => (int) $account->points_available,
            'member_status' => (string) $account->status,
            'segment' => $account->segment,
            'legacy_card_number' => $account->legacy_card_number,
            'member_since' => $account->enrolled_at?->toDateString(),
            // So a stale read is recognisable as stale rather than as current.
            'published_at' => now()->toIso8601String(),
        ];
    }
}
