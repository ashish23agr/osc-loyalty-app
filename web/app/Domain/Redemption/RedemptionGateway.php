<?php

namespace App\Domain\Redemption;

use App\Models\LoyaltyAccount;
use App\Models\Redemption;

/**
 * How a redemption becomes money off a sale (D5, D6).
 *
 * Two mechanisms, deliberately behind one interface. Online it is a discount
 * function reading an app-owned customer metafield (D5); at the till it is
 * `applyCartDiscount` called by the POS tile (D6). The redemption service knows
 * neither, which is what kept the single-use-code alternative available when D5
 * was still a spike, and what will keep it available if the function approach
 * ever has to be swapped out.
 *
 * The three verbs are the life of a quote:
 *
 *   publish   make the value available to the channel that will apply it
 *   withdraw  take it back — the quote expired, or the customer changed the cart
 *   confirm   the order was paid; this is the only one that moves points
 *
 * Points move in the ledger, never here. A gateway makes value visible; it does
 * not spend anything.
 */
interface RedemptionGateway
{
    /** Matches loyalty_redemptions.discount_mechanism. */
    public function mechanism(): string;

    /**
     * Make this quote's value available to the channel.
     *
     * Idempotent: publishing the same quote twice must leave the channel in the
     * same state, because a retried request is normal and a doubled discount is
     * not.
     */
    public function publish(LoyaltyAccount $account, Redemption $redemption): void;

    /**
     * Take the value back, so it cannot be applied.
     *
     * Must be safe to call on a quote that was never published, and on one that
     * has already been withdrawn.
     */
    public function withdraw(LoyaltyAccount $account, ?Redemption $redemption = null): void;
}
