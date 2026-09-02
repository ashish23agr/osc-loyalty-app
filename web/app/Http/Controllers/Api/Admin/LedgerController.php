<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Loyalty\ProgrammeSummary;
use App\Http\Presenters\LedgerEntryPresenter;
use App\Http\Presenters\MemberPresenter;
use App\Http\Requests\Admin\LedgerQueryRequest;
use App\Models\LedgerEntry;
use App\Support\Members\MemberCardNumber;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/admin/ledger — the programme ledger behind the Loyalty screen.
 *
 * The engine's own view: every movement across the programme with the same type
 * and channel filters the member ledger offers, and the five summary tiles in
 * the meta so the screen costs one request rather than two.
 *
 * The sixteenth endpoint, where plan section 5.3 lists fifteen. The Loyalty
 * screen in the agreed UI (A5) needs a programme-wide ledger, and neither
 * /overview nor the member-scoped ledger is that: one is a dashboard of totals,
 * the other is scoped to a person. Recorded in PROGRESS.md rather than folded
 * into an existing endpoint, because pretending it was always there would make
 * the plan and the code disagree.
 *
 * There is no running balance column here, and deliberately: a running total
 * across members is not a balance of anything.
 */
class LedgerController extends AdminController
{
    public function __construct(private readonly ProgrammeSummary $summary) {}

    public function index(LedgerQueryRequest $request): JsonResponse
    {
        $shop = $this->shop($request);

        $query = $request->applyTo(
            LedgerEntry::query()->where('shop_domain', $shop)->with('account')
        );

        if ($term = trim((string) $request->input('q', ''))) {
            // Matched against the order reference rather than the member, which
            // is what an operator has in front of them when they are chasing a
            // sale that did not earn.
            $query->where('order_name', 'like', $term.'%');
        }

        $page = $query->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request), ['*'], 'page', (int) $request->input('page', 1));

        return $this->collection(
            $page,
            function (LedgerEntry $entry): array {
                $account = $entry->account;

                return LedgerEntryPresenter::withMember($entry, [
                    'id' => (int) $entry->loyalty_account_id,
                    'display_name' => $account === null ? null : MemberPresenter::displayName($account),
                    'card_number' => MemberCardNumber::format((int) $entry->loyalty_account_id),
                    'legacy_card_number' => $account?->legacy_card_number,
                    'email' => $account?->email,
                ]);
            },
            [
                'summary' => $this->summary->loyaltyTiles($shop),
                'filters' => [
                    'types' => LedgerQueryRequest::ENTRY_TYPES,
                    'channels' => LedgerQueryRequest::CHANNELS,
                ],
            ],
        );
    }
}
