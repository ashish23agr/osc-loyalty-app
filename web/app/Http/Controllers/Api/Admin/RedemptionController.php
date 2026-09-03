<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Redemption\PosCartDiscountGateway;
use App\Domain\Redemption\RedemptionGateway;
use App\Domain\Redemption\RedemptionService;
use App\Models\Redemption;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Redemption for the POS tile and the storefront (M6, M7, D5, D6, D8).
 *
 * Three endpoints, and the split between them is the whole design:
 *
 *   POST /quote        what could be offered, writing nothing
 *   POST /             hold a quote, and make it available to the channel
 *   DELETE /{reference} give it back
 *
 * **Quote is separate because the tile must validate BEFORE it offers an
 * amount.** A tile that shows a redeem button and then fails is worse than one
 * that explains why it cannot, especially with a queue waiting, so the amount on
 * screen is always one the server has already agreed to.
 *
 * Confirmation is deliberately absent. Points are spent when the order is paid,
 * which arrives as `orders/paid` — not when a till assistant presses a button.
 */
class RedemptionController extends AdminController
{
    public function __construct(
        private readonly RedemptionService $redemptions,
        private readonly AuditLogger $audit,
    ) {}

    /** POST /api/admin/redemptions/quote — read-only. */
    public function quote(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'loyalty_account_id' => ['required', 'integer'],
            'eligible_subtotal_pence' => ['required', 'integer', 'min:0'],
            'basket_total_pence' => ['required', 'integer', 'min:0'],
        ]);

        $member = $this->member($request, (string) $validated['loyalty_account_id']);

        return response()->json($this->redemptions->quote(
            $member,
            $validated['eligible_subtotal_pence'],
            $validated['basket_total_pence'],
        ));
    }

    /** POST /api/admin/redemptions — hold a quote and publish it. */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'loyalty_account_id' => ['required', 'integer'],
            'eligible_subtotal_pence' => ['required', 'integer', 'min:0'],
            'basket_total_pence' => ['required', 'integer', 'min:0'],
            'channel' => ['required', 'in:online,pos'],
            // C1: what the member asked for. Only ever honoured downwards.
            'requested_pence' => ['nullable', 'integer', 'min:0'],
            'shopify_location_id' => ['nullable', 'integer'],
            // The pinned staff member at the till, which may differ from the
            // authenticated user the token identifies.
            'staff_member_id' => ['nullable', 'integer'],
            // V18: what the TILL is denominated in, which is the currency of its
            // location and not necessarily the shop's. Required for POS, because
            // a POS hold that cannot state it cannot be verified - see
            // RedemptionService::TILL_CURRENCY_UNKNOWN.
            'till_currency' => ['nullable', 'string', 'size:3'],
        ]);

        $member = $this->member($request, (string) $validated['loyalty_account_id']);
        $staff = $this->staff($request);

        $held = $this->redemptions->hold(
            account: $member,
            gateway: self::gatewayFor($validated['channel']),
            eligibleSubtotalPence: $validated['eligible_subtotal_pence'],
            basketTotalPence: $validated['basket_total_pence'],
            channel: $validated['channel'],
            requestedPence: $validated['requested_pence'] ?? null,
            shopifyLocationId: $validated['shopify_location_id'] ?? null,
            // Every POS action is attributed. The pinned staff member is who is
            // actually standing at the till; the token's user is who authorised
            // the app. Both matter, and they are not always the same person.
            staffReference: self::staffReference($staff, $validated['staff_member_id'] ?? null),
            tillCurrency: $validated['till_currency'] ?? null,
        );

        if ($held['redemption'] === null) {
            // A refusal is a 200 with a reason, not an error: the tile has
            // something to show, and nothing went wrong.
            return response()->json([
                'redemption' => null,
                'quote' => $held['quote'],
                'reason' => $held['reason'],
            ]);
        }

        $this->audit->log(
            action: AuditAction::REDEMPTION_QUOTED,
            subjectType: 'LoyaltyAccount',
            subjectId: (int) $member->id,
            after: [
                'reference' => $held['redemption']->reference,
                'amount_pence' => (int) $held['redemption']->amount_pence,
                'channel' => $validated['channel'],
                'state' => 'quoted',
                'shopify_location_id' => $validated['shopify_location_id'] ?? null,
                'staff_member_id' => $validated['staff_member_id'] ?? null,
            ],
            shopDomain: $member->shop_domain,
        );

        return response()->json([
            'redemption' => self::present($held['redemption']),
            'quote' => $held['quote'],
            'reason' => null,
        ], 201);
    }

    /** DELETE /api/admin/redemptions/{reference} — the basket changed. */
    public function destroy(Request $request, string $reference): JsonResponse
    {
        $shop = $this->shop($request);

        $redemption = Redemption::query()
            ->where('shop_domain', $shop)
            ->where('reference', $reference)
            ->first();

        if ($redemption === null) {
            throw ApiException::notFound('No redemption with that reference exists on this shop.');
        }

        if ($redemption->state === 'confirmed') {
            throw new ApiException(
                'redemption_already_confirmed',
                'That redemption is on a paid order. Reverse it with a refund instead.',
            );
        }

        $member = $this->member($request, (string) $redemption->loyalty_account_id);

        $this->redemptions->void(
            $member,
            $redemption,
            self::gatewayFor($redemption->channel),
        );

        $this->audit->log(
            action: AuditAction::REDEMPTION_VOIDED,
            subjectType: 'LoyaltyAccount',
            subjectId: (int) $member->id,
            after: ['reference' => $reference, 'state' => 'void'],
            shopDomain: $member->shop_domain,
        );

        return response()->json(['redemption' => self::present($redemption->refresh())]);
    }

    /**
     * Which mechanism applies this channel's discount.
     *
     * One method rather than a ternary at each call site, and that is not
     * tidiness: this choice was duplicated in `store()` and `destroy()`, so a
     * change to one of them would have held a quote through one mechanism and
     * tried to withdraw it through another — a published entitlement nothing
     * takes back. Online resolves through the container, so the mechanism is
     * decided in AppServiceProvider alone; the till is a fixed mechanism rather
     * than a configurable one.
     */
    private static function gatewayFor(string $channel): RedemptionGateway
    {
        return $channel === 'pos'
            ? app(PosCartDiscountGateway::class)
            : app(RedemptionGateway::class);
    }

    private static function present(Redemption $redemption): array
    {
        return [
            'reference' => $redemption->reference,
            'state' => $redemption->state,
            'amount_pence' => (int) $redemption->amount_pence,
            'points_consumed' => (int) $redemption->points_consumed,
            'channel' => $redemption->channel,
            'discount_mechanism' => $redemption->discount_mechanism,
            'quote_expires_at' => $redemption->quote_expires_at?->toIso8601String(),
            'staff_reference' => $redemption->staff_reference,
        ];
    }

    /**
     * Who did this.
     *
     * The pinned staff member where POS supplies one, and the authenticated
     * user otherwise, recorded together so a till action can be traced to the
     * person at the counter rather than only to whoever logged the iPad in.
     */
    private static function staffReference($staff, ?int $pinnedStaffMemberId): string
    {
        $user = $staff?->shopify_staff_id;

        if ($pinnedStaffMemberId === null) {
            return (string) $user;
        }

        return $pinnedStaffMemberId === (int) $user
            ? (string) $user
            : $pinnedStaffMemberId.' (via user '.$user.')';
    }
}
