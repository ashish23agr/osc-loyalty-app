<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Loyalty\ProgrammeSummary;
use App\Http\Presenters\LedgerEntryPresenter;
use App\Http\Presenters\MemberPresenter;
use App\Models\LedgerEntry;
use App\Support\Members\MemberCardNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/admin/overview — the dashboard, in one call.
 *
 * Tiles, the enrolment trend, redemption for the month and the recent activity
 * strip, so the first screen after sign-in costs one request rather than six.
 * Job health is included because a queue that has quietly stopped is the failure
 * this programme is most exposed to: earning still posts, but nothing matures
 * and nothing expires, and the only visible symptom is a number that stops
 * moving.
 */
class OverviewController extends AdminController
{
    /** Enough to fill the activity strip, not enough to be a report. */
    private const RECENT_ACTIVITY = 10;

    public function __construct(private readonly ProgrammeSummary $summary) {}

    public function __invoke(Request $request): JsonResponse
    {
        $shop = $this->shop($request);

        return response()->json(
            $this->summary->overview($shop) + ['recent_activity' => $this->recentActivity($shop)]
        );
    }

    private function recentActivity(string $shop): array
    {
        $entries = LedgerEntry::query()
            ->where('shop_domain', $shop)
            ->with('account')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_ACTIVITY)
            ->get();

        return $entries->map(function (LedgerEntry $entry): array {
            $account = $entry->account;

            return LedgerEntryPresenter::withMember($entry, [
                'id' => (int) $entry->loyalty_account_id,
                'display_name' => $account === null ? null : MemberPresenter::displayName($account),
                'card_number' => MemberCardNumber::format((int) $entry->loyalty_account_id),
                'legacy_card_number' => $account?->legacy_card_number,
            ]);
        })->all();
    }
}
