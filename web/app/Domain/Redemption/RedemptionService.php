<?php

namespace App\Domain\Redemption;

use App\Domain\Loyalty\BalanceCalculator;
use App\Domain\Loyalty\LedgerPosting;
use App\Domain\Loyalty\LedgerService;
use App\Domain\Loyalty\RedemptionLadder;
use App\Domain\Orders\OrderSnapshot;
use App\Domain\Rules\RuleSet;
use App\Domain\Rules\RulesVersionRepository;
use App\Domain\Shop\ShopCurrency;
use App\Models\LedgerEntry;
use App\Models\LoyaltyAccount;
use App\Models\Redemption;
use App\Support\Money\Pence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Quoting, applying and confirming a redemption (M6, M7, D5, D6, D8).
 *
 * The life of a redemption, and the reason it has one:
 *
 *   quoted     the ladder has been run against a basket. Nothing has moved.
 *   applied    the channel is showing the discount. Still nothing has moved.
 *   confirmed  the order was paid. THIS is where points leave the ledger.
 *   void       the quote expired or the basket changed. Nothing moved, ever.
 *
 * Points are consumed at **confirmation**, not at quote. A quote that is never
 * paid for must cost the member nothing, and a basket abandoned at checkout is
 * the common case rather than the exception. `points_consumed` stays 0 until an
 * order exists, which is exactly what the Sprint 1 schema comment says.
 *
 * The channel is behind RedemptionGateway, so this class does not know whether
 * the value will be applied by a discount function or by a POS tile.
 */
final class RedemptionService
{
    /** How long a quote stands before it has to be re-run against the basket. */
    public const QUOTE_MINUTES = 20;

    /**
     * The shop is denominated in a different currency from the programme (V13).
     *
     * Unlike every other refusal `hold()` can return, this one is not a rung of
     * the D8 ladder — it is a misconfiguration, and it is a refusal rather than
     * a warning because Shopify denominates a discount amount in the SHOP's
     * currency and silently ignores the one we send. On 3 Sep 2026 that turned
     * a £50 voucher into a €50 one with every other constraint correct.
     */
    public const CURRENCY_MISMATCH = 'currency_mismatch';

    /**
     * The shop currency could not be read at all, so agreement is unproven.
     *
     * Fails closed, and kept distinct from a known mismatch so the console can
     * say "try again" rather than "your programme is misconfigured".
     */
    public const CURRENCY_UNKNOWN = 'currency_unknown';

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly BalanceCalculator $balances,
        private readonly RulesVersionRepository $rules,
        private readonly ShopCurrency $shopCurrency,
    ) {}

    /**
     * What this basket may take, and why — without writing anything.
     *
     * The read-only half, so a tile can ask "what could I offer?" as often as it
     * likes. Nothing here creates a row.
     *
     * @return array{
     *     redeemable_pence:int, reason:?string, binding_rung:?string,
     *     ladder:array<string,int>, voucher_balance_pence:int, points_for_amount:int
     * }
     */
    public function quote(
        LoyaltyAccount $account,
        int $eligibleSubtotalPence,
        int $basketTotalPence,
    ): array {
        $rules = $this->rules->current($account->shop_domain);
        $balances = $this->balances->for($account);

        $result = RedemptionLadder::quote(
            voucherBalancePence: $balances->voucher->pence(),
            eligibleSubtotalPence: $eligibleSubtotalPence,
            basketTotalPence: $basketTotalPence,
            rules: $rules,
        );

        return $result + [
            'voucher_balance_pence' => $balances->voucher->pence(),
            'points_for_amount' => self::pointsFor($result['redeemable_pence'], $rules),
            // The step the till's redeem control moves in. Sent rather than
            // assumed, so changing the increment in Settings changes the
            // control without anyone redeploying the extension.
            'increment_pence' => $rules->voucherValuePence(),
        ];
    }

    /**
     * Hold a quote and make it available to the channel.
     *
     * @param  int|null  $requestedPence  What the member asked for (C1). Only ever downwards.
     */
    public function hold(
        LoyaltyAccount $account,
        RedemptionGateway $gateway,
        int $eligibleSubtotalPence,
        int $basketTotalPence,
        string $channel,
        ?int $requestedPence = null,
        ?int $shopifyLocationId = null,
        ?string $staffReference = null,
    ): array {
        if ($account->isMerged()) {
            throw new RuntimeException('Account '.$account->id.' was merged; quote the survivor instead.');
        }

        $quote = $this->quote($account, $eligibleSubtotalPence, $basketTotalPence);
        $rules = $this->rules->current($account->shop_domain);

        $amount = $quote['redeemable_pence'];

        // C1, still open: the member may ask for less than the ladder allows,
        // and the request is honoured downwards and in whole increments only.
        // Whatever control C1 turns out to specify, this is the seam it uses.
        if ($requestedPence !== null && $requestedPence < $amount) {
            $amount = Pence::of(max(0, $requestedPence))
                ->floorToMultipleOf($rules->voucherValuePence())
                ->toInt();
        }

        if ($amount <= 0) {
            return [
                'redemption' => null,
                'quote' => $quote,
                // A refusal that already had a reason keeps it; one that only
                // became nothing because the member asked for less gets its own.
                'reason' => $quote['reason'] ?? RedemptionLadder::BELOW_VOUCHER_INCREMENT,
            ];
        }

        // V13. Checked here rather than in either gateway because BOTH channels
        // come through this method, so one guard covers the code path and the
        // till at once — and checked after the refusals above, so a quote that
        // was never going to mint anything does not spend an Admin API call.
        //
        // Before the transaction on purpose: a refusal must leave no row. A
        // quote in `quoted` state with no offer behind it is the orphan the
        // expiry sweep then has to reason about, for no gain.
        $shopCurrency = $this->shopCurrency->for($account->shop_domain);
        $rulesCurrency = $rules->currency();

        if ($shopCurrency === null || strcasecmp($shopCurrency, $rulesCurrency) !== 0) {
            $unknown = $shopCurrency === null;

            Log::error('Refusing to hold a redemption: the shop and the programme disagree about currency', [
                'shop' => $account->shop_domain,
                'account' => $account->id,
                'shop_currency' => $shopCurrency ?? 'unreadable',
                'rules_currency' => $rulesCurrency,
                'amount_pence' => $amount,
                'channel' => $channel,
            ]);

            return [
                'redemption' => null,
                'quote' => $quote,
                'reason' => $unknown ? self::CURRENCY_UNKNOWN : self::CURRENCY_MISMATCH,
            ];
        }

        $redemption = DB::transaction(function () use (
            $account, $gateway, $amount, $quote, $rules, $channel,
            $shopifyLocationId, $staffReference
        ): Redemption {
            return Redemption::create([
                'shop_domain' => $account->shop_domain,
                'reference' => self::reference(),
                'loyalty_account_id' => $account->id,
                'channel' => $channel,
                'state' => 'quoted',
                'amount_pence' => $amount,
                // Nothing is consumed until an order is paid for.
                'points_consumed' => 0,
                'discount_mechanism' => $gateway->mechanism(),
                'shopify_location_id' => $shopifyLocationId,
                'staff_reference' => $staffReference,
                'rules_version_id' => $rules->versionId,
                // Every figure in the ladder as it stood, so a dispute months
                // later has an answer rather than a recomputation against rules
                // that have since changed.
                'validation_snapshot' => $quote,
                'quoted_at' => now(),
                'quote_expires_at' => now()->addMinutes(self::QUOTE_MINUTES),
            ]);
        });

        $gateway->publish($account, $redemption);

        return ['redemption' => $redemption, 'quote' => $quote, 'reason' => null];
    }

    /**
     * The order was paid. Spend the points.
     *
     * The only method here that moves anything. Idempotent on the redemption
     * reference, so a redelivered `orders/paid` confirms once — and FIFO across
     * the member's lots, through the Sprint 1 allocator, so expiry still knows
     * what each lot has left.
     */
    public function confirm(
        LoyaltyAccount $account,
        Redemption $redemption,
        int $shopifyOrderId,
        ?string $orderName = null,
        ?Carbon $occurredAt = null,
    ): array {
        $occurredAt ??= now();

        if ($redemption->state === 'confirmed') {
            return ['entry' => $this->entryFor($redemption), 'points' => (int) $redemption->points_consumed, 'replayed' => true];
        }

        if ($redemption->state === 'void') {
            throw new RuntimeException('Redemption '.$redemption->reference.' was voided and cannot be confirmed.');
        }

        $rules = $this->rules->asAt($account->shop_domain, $occurredAt);
        $points = self::pointsFor((int) $redemption->amount_pence, $rules);

        return DB::transaction(function () use (
            $account, $redemption, $points, $shopifyOrderId, $orderName, $occurredAt
        ): array {
            $entry = $this->ledger->post($account, LedgerPosting::redemption(
                points: $points,
                redemptionId: (int) $redemption->id,
                // The reference, not the order: an order can carry more than
                // one redemption over its life, and the reference is the thing
                // the discount message and the audit entry already share.
                idempotencyKey: 'redeem:'.$redemption->reference,
                occurredAt: $occurredAt,
                channel: $redemption->channel,
                shopifyOrderId: $shopifyOrderId,
                orderName: $orderName,
                shopifyLocationId: $redemption->shopify_location_id === null
                    ? null
                    : (int) $redemption->shopify_location_id,
                staffReference: $redemption->staff_reference,
            ));

            $redemption->forceFill([
                'state' => 'confirmed',
                'points_consumed' => $points,
                'shopify_order_id' => $shopifyOrderId,
                'order_name' => $orderName,
                'confirmed_at' => $occurredAt,
            ])->save();

            return ['entry' => $entry, 'points' => $points, 'replayed' => false];
        });
    }

    /**
     * Confirm every quote this paid order carries (M6, M7, D5, D6).
     *
     * Called from the `orders/paid` handler. The link between an order and the
     * quote that produced it is the **reference on the discount** — there is no
     * other: at quote time no order existed, and by the time the webhook arrives
     * the only trace is the discount title the customer can also read off their
     * receipt. That is D6's shared identifier doing the job it was asked to do.
     *
     * Safe to call on every paid order. An order with no reference confirms
     * nothing, and an order whose quote is already confirmed replays without
     * spending twice, because LedgerService is keyed on the reference.
     *
     * @return array{confirmed:int, points:int, replayed:int, skipped:list<array{reference:string, why:string}>}
     */
    public function confirmFromOrder(LoyaltyAccount $account, OrderSnapshot $order): array
    {
        $result = ['confirmed' => 0, 'points' => 0, 'replayed' => 0, 'skipped' => []];

        foreach ($order->redemptionReferences as $reference) {
            $redemption = Redemption::query()
                ->where('shop_domain', $account->shop_domain)
                ->where('reference', $reference)
                ->first();

            if ($redemption === null) {
                // A reference-shaped string on a discount this app did not
                // issue. Recorded rather than ignored: it is either a merchant
                // naming a manual discount that way, or something worth knowing.
                $result['skipped'][] = ['reference' => $reference, 'why' => 'unknown_reference'];

                continue;
            }

            // The quote belongs to somebody. A reference copied onto another
            // customer's order must not spend the first customer's points, and
            // this is the only place that could happen.
            if ((int) $redemption->loyalty_account_id !== (int) $account->id) {
                Log::warning('A redemption reference appeared on the order of a different member', [
                    'shop' => $account->shop_domain,
                    'reference' => $reference,
                    'redemption_account_id' => (int) $redemption->loyalty_account_id,
                    'order_account_id' => (int) $account->id,
                    'shopify_order_id' => $order->shopifyOrderId,
                ]);

                $result['skipped'][] = ['reference' => $reference, 'why' => 'belongs_to_another_member'];

                continue;
            }

            if ($redemption->state === 'void') {
                // Voided before the order landed. The discount is on the order
                // and the points are not being taken, which is a real
                // discrepancy and has to be visible rather than silent.
                Log::warning('A voided redemption was applied to a paid order', [
                    'shop' => $account->shop_domain,
                    'reference' => $reference,
                    'shopify_order_id' => $order->shopifyOrderId,
                ]);

                $result['skipped'][] = ['reference' => $reference, 'why' => 'voided'];

                continue;
            }

            $confirmed = $this->confirm(
                account: $account,
                redemption: $redemption,
                shopifyOrderId: $order->shopifyOrderId,
                orderName: $order->orderName,
                occurredAt: $order->processedAt === null ? null : Carbon::parse($order->processedAt),
            );

            if ($confirmed['replayed']) {
                $result['replayed']++;

                continue;
            }

            $result['confirmed']++;
            $result['points'] += $confirmed['points'];
        }

        return $result;
    }

    /** The quote expired, or the basket changed under it. Nothing was spent. */
    public function void(LoyaltyAccount $account, Redemption $redemption, RedemptionGateway $gateway): void
    {
        if ($redemption->state === 'confirmed') {
            throw new RuntimeException(
                'Redemption '.$redemption->reference.' is confirmed; reverse it through a refund instead.'
            );
        }

        $redemption->forceFill(['state' => 'void'])->save();

        $gateway->withdraw($account, $redemption);
    }

    /**
     * Points for an amount of voucher value.
     *
     * The inverse of the derivation in D1: a hundred points is five pounds, so
     * ten pounds is two hundred points. Exact by construction, because the
     * ladder only ever offers whole increments.
     */
    public static function pointsFor(int $amountPence, RuleSet $rules): int
    {
        if ($amountPence <= 0 || $rules->voucherValuePence() <= 0) {
            return 0;
        }

        return intdiv($amountPence, $rules->voucherValuePence()) * $rules->voucherThresholdPoints();
    }

    /**
     * A short, human-readable reference.
     *
     * The format lives in RedemptionReference because it is read as well as
     * written: `orders/paid` finds the quote a paid order belongs to by pulling
     * this string back out of the order's discount title, and a format that
     * lived in two places would eventually be generated in one shape and parsed
     * in another.
     */
    public static function reference(): string
    {
        return RedemptionReference::generate();
    }

    private function entryFor(Redemption $redemption): ?LedgerEntry
    {
        return LedgerEntry::query()
            ->where('entry_type', 'redemption')
            ->where('redemption_id', $redemption->id)
            ->first();
    }
}
