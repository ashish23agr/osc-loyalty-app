<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Loyalty\AdjustmentService;
use App\Http\Presenters\LedgerEntryPresenter;
use App\Http\Requests\Admin\AdjustPointsRequest;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/admin/members/{id}/adjustments — move points by hand.
 *
 * The one endpoint in Sprint 1 that both moves points and is initiated by a
 * person, so it carries all four controls at once: a role floor, a per-person
 * limit, a mandatory reason and an audit entry written in the same transaction.
 *
 * It answers two questions with one shape. With preview=true it writes nothing
 * and returns the projection, which is what fills in "New balance will be 390
 * points, 15 pounds available" while the modal is still open. Without it, the
 * adjustment is posted and the same projection block is returned alongside the
 * balances that now actually hold, so the confirmation and the preview cannot
 * disagree about what was about to happen.
 */
class AdjustmentController extends AdminController
{
    public function __construct(private readonly AdjustmentService $adjustments) {}

    public function store(AdjustPointsRequest $request, string $id): JsonResponse
    {
        $member = $this->writableMember($request, $id);
        $staff = $this->staff($request);

        $signedPoints = $request->signedPoints();
        $bucket = $request->bucket();

        if ($request->isPreview()) {
            return response()->json([
                'preview' => true,
                'member_id' => (int) $member->id,
                'projection' => $this->adjustments->project($member, $signedPoints, $bucket),
                // Reported rather than refused, so the modal can disable Save
                // and say why instead of letting someone type a reason for an
                // adjustment that was never going to be accepted.
                'limit' => $this->adjustments->limit($staff, $signedPoints),
                'reason_categories' => AdjustmentService::REASON_CATEGORIES,
                'minimum_note_length' => AdjustmentService::MINIMUM_NOTE_LENGTH,
            ]);
        }

        $result = $this->adjustments->apply(
            account: $member,
            staff: $staff,
            signedPoints: $signedPoints,
            bucket: $bucket,
            reasonCategory: (string) $request->input('reason_category'),
            notes: (string) $request->input('notes'),
            idempotencyKey: $request->header('Idempotency-Key'),
        );

        return response()->json([
            'preview' => false,
            'member_id' => (int) $member->id,
            'entry' => LedgerEntryPresenter::row($result['entry']),
            'reference' => LedgerEntryPresenter::reference($result['entry']),
            'balances' => $result['balances']->toArray() + [
                'voucher_increments' => $result['balances']->voucher->increments,
                'remainder_points' => $result['balances']->voucher->remainderPoints,
                'points_to_next_increment' => $result['balances']->voucher->pointsToNextIncrement,
            ],
            'projection' => $result['projection'],
            'limit' => $result['limit'],
            // True when this was a retry of a request that had already been
            // applied. The console shows the original rather than reporting a
            // second adjustment nobody made.
            'idempotent_replay' => $result['replayed'],
        ], $result['replayed'] ? 200 : 201);
    }
}
