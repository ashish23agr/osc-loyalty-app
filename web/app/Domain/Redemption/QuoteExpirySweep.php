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
 * **Why it matters, and it is not tidiness.** Holding a quote publishes an
 * entitlement the discount function can read. If the member's points expire
 * overnight while that entitlement is still readable, the function would still
 * offer the old amount and confirming it would spend points the member no longer
 * has — driving the balance negative. Q3 permits a negative balance from a
 * *clawback*; a redemption creating one is a different thing and is not
 * intended.
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
        private readonly DiscountFunctionGateway $online,
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

    private function gatewayFor(Redemption $quote): RedemptionGateway
    {
        return $quote->channel === 'pos' ? $this->pos : $this->online;
    }
}
