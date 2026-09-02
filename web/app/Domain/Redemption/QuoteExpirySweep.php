<?php

namespace App\Domain\Redemption;

use App\Models\LoyaltyAccount;
use App\Models\Redemption;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Ending quotes nobody paid for.
 *
 * A quote says it stands for twenty minutes. Without this, the only things that
 * ended one were a confirmation or an explicit void, so `quote_expires_at` was a
 * promise nothing kept.
 *
 * **Why it matters, and it is not tidiness.** Holding a quote publishes value
 * the channel can apply — an app-owned metafield under the function, a live
 * single-use discount code under D5 as revised. If the member's points expire
 * overnight while that offer still stands, the channel would still apply the old
 * amount and confirming it would spend points the member no longer has — driving
 * the balance negative. Q3 permits a negative balance from a *clawback*; a
 * redemption creating one is a different thing and is not intended.
 *
 * Under the code path the offer is Shopify's to hold, not ours, which makes this
 * sweep the only thing that ends it early: a code carries its own `endsAt`, but
 * a quote voided before that still has a live code until this runs.
 *
 * **What an expired quote does, which was a choice.** It is voided and the
 * published entitlement is withdrawn. Nothing is re-quoted: both channels ask
 * for a fresh quote against the live basket anyway — the tile on opening the
 * member screen, the storefront on the next hold — so a re-quote here would be
 * guessing at a basket nobody is looking at. Voiding costs the member nothing,
 * because a quote never spent anything.
 */
final class QuoteExpirySweep
{
    public function __construct(
        private readonly RedemptionService $redemptions,
        private readonly DiscountCodeGateway $code,
        private readonly DiscountFunctionGateway $function,
        private readonly PosCartDiscountGateway $pos,
    ) {}

    /**
     * @return array{expired:int, accounts:int, withdrawn:int}
     */
    public function run(string $shopDomain, ?DateTimeInterface $asOf = null, int $chunk = 200): array
    {
        $asOf = $asOf === null ? now() : Carbon::parse($asOf);

        $expired = 0;
        $withdrawn = 0;
        $accounts = [];

        Redemption::query()
            ->where('shop_domain', $shopDomain)
            // Only quotes still waiting. A confirmed redemption is spent and a
            // voided one is already finished.
            ->whereIn('state', ['quoted', 'applied'])
            ->whereNotNull('quote_expires_at')
            ->where('quote_expires_at', '<=', $asOf)
            ->orderBy('id')
            ->chunkById($chunk, function ($quotes) use (&$expired, &$withdrawn, &$accounts): void {
                foreach ($quotes as $quote) {
                    $account = LoyaltyAccount::query()->find($quote->loyalty_account_id);

                    if ($account === null) {
                        continue;
                    }

                    $this->redemptions->void($account, $quote, $this->gatewayFor($quote));

                    $expired++;
                    $accounts[$quote->loyalty_account_id] = true;

                    // An online quote had something published; a till quote had
                    // nothing to publish, so nothing to take back.
                    if ($quote->channel === 'online') {
                        $withdrawn++;
                    }
                }
            });

        return [
            'expired' => $expired,
            'accounts' => count($accounts),
            'withdrawn' => $withdrawn,
        ];
    }

    /**
     * Withdraw through the mechanism that published, not the one in force now.
     *
     * **This dispatches on `discount_mechanism`, deliberately, and not on
     * channel.** The column records what actually published this quote, and the
     * online mechanism has already changed once — D5 moved from the discount
     * function to single-use codes on 2 Sep 2026. A sweep that chose by channel
     * would take the *current* online gateway to a quote published by the
     * previous one: it would clear a metafield that was never written and leave
     * a live discount code standing, or the reverse. Either way the member keeps
     * an offer nothing will take back, which is the exact failure this sweep
     * exists to prevent.
     *
     * An unrecognised or missing mechanism falls back to the channel, because a
     * best-effort withdrawal beats none — but that is a fallback, not the path.
     */
    private function gatewayFor(Redemption $quote): RedemptionGateway
    {
        return match ($quote->discount_mechanism) {
            'discount_code' => $this->code,
            'function' => $this->function,
            'pos_cart_discount' => $this->pos,
            default => $quote->channel === 'pos' ? $this->pos : $this->code,
        };
    }
}
