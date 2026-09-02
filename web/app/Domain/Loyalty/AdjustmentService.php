<?php

namespace App\Domain\Loyalty;

use App\Domain\Identity\StaffRoleResolver;
use App\Domain\Rules\RulesVersionRepository;
use App\Models\LedgerEntry;
use App\Models\LoyaltyAccount;
use App\Models\StaffRole;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A member of staff moving points by hand.
 *
 * The adjust-points modal asks three things of the server that no other write
 * does, so they live together here rather than in a controller:
 *
 *   1. It previews. "New balance will be 390 points, 15 pounds available" has to
 *      be shown before anything is saved, so the projection is a pure function
 *      of the current balance and the rule version and can be asked for without
 *      writing anything.
 *   2. It is limited per person. An Agent may move only so many points at once,
 *      with a personal override beating the rule default.
 *   3. It demands a reason. The category and notes are composed into one reason
 *      string that is written to the ledger entry and the audit entry alike, so
 *      the member record and the audit log cannot tell different stories.
 *
 * Every write goes through LedgerService, which is the only path into the
 * ledger, and the audit entry is written in the same transaction as the posting.
 */
final class AdjustmentService
{
    /** The categories offered by the modal. A closed list, like the actions. */
    public const REASON_CATEGORIES = [
        'goodwill' => 'Goodwill gesture',
        'correction_missed_earning' => 'Correction — missed earning',
        'correction_duplicate_earning' => 'Correction — duplicate earning',
        'migration_correction' => 'Migration correction',
        'other' => 'Other',
    ];

    /** Long enough that "fixed" is not an acceptable answer six months later. */
    public const MINIMUM_NOTE_LENGTH = 10;

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly BalanceCalculator $balances,
        private readonly RulesVersionRepository $rules,
        private readonly StaffRoleResolver $staffRoles,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * What the balance would become. Writes nothing.
     *
     * Derived from the ledger rather than the cached columns, so a preview is
     * still right on an account whose cache has drifted.
     */
    public function project(LoyaltyAccount $account, int $signedPoints, string $bucket): array
    {
        $rules = $this->rules->current($account->shop_domain);
        $before = $this->balances->for($account);

        $pendingAfter = $before->pending + ($bucket === 'pending' ? $signedPoints : 0);
        $availableAfter = $before->available + ($bucket === 'available' ? $signedPoints : 0);

        $voucherAfter = BalanceCalculator::derive($availableAfter, $rules);

        return [
            'bucket' => $bucket,
            'points_delta' => $signedPoints,
            'points_pending_before' => $before->pending,
            'points_pending_after' => $pendingAfter,
            'points_available_before' => $before->available,
            'points_available_after' => $availableAfter,
            'voucher_balance_pence_before' => $before->voucher->pence(),
            'voucher_balance_pence_after' => $voucherAfter->pence(),
            'voucher_increments_after' => $voucherAfter->increments,
            'remainder_points_after' => $voucherAfter->remainderPoints,
            'points_to_next_increment_after' => $voucherAfter->pointsToNextIncrement,
        ];
    }

    /**
     * The most this person may move in one go, and whether this move clears it.
     *
     * Null means unrestricted, which is Manager and above per Blueprint
     * section 12.
     */
    public function limit(StaffRole $staff, int $signedPoints): array
    {
        $limit = $this->staffRoles->adjustmentLimit(
            $staff,
            $this->rules->current($staff->shop_domain)->agentAdjustmentLimitPoints(),
        );

        return [
            'points' => $limit,
            'requested_points' => abs($signedPoints),
            // The limit is on the size of the movement, not its direction: a
            // deduction of a thousand points is as consequential as a credit.
            'exceeded' => $limit !== null && abs($signedPoints) > $limit,
        ];
    }

    /**
     * Post the adjustment and audit it.
     *
     * @return array{entry: LedgerEntry, balances: Balances, projection: array, limit: array, replayed: bool}
     */
    public function apply(
        LoyaltyAccount $account,
        StaffRole $staff,
        int $signedPoints,
        string $bucket,
        string $reasonCategory,
        string $notes,
        ?string $idempotencyKey = null,
    ): array {
        if ($account->isMerged()) {
            throw ApiException::accountMerged((int) $account->id, $account->merged_into_account_id);
        }

        $limit = $this->limit($staff, $signedPoints);

        if ($limit['exceeded']) {
            throw ApiException::adjustmentLimitExceeded(abs($signedPoints), (int) $limit['points']);
        }

        $key = $idempotencyKey ?? 'adj:'.$account->id.':'.Str::uuid();

        // Answered before any work, so a client retrying after a dropped
        // response gets the original adjustment back rather than a second one
        // and a second audit entry describing it.
        $existing = $this->ledger->findByIdempotencyKey($account->shop_domain, $key);

        if ($existing !== null) {
            return [
                'entry' => $existing,
                'balances' => $this->balances->for($account->refresh()),
                'projection' => $this->project($account, 0, $bucket),
                'limit' => $limit,
                'replayed' => true,
            ];
        }

        $projection = $this->project($account, $signedPoints, $bucket);
        $reason = $this->composeReason($reasonCategory, $notes);
        $rules = $this->rules->current($account->shop_domain);

        return DB::transaction(function () use (
            $account, $staff, $signedPoints, $bucket, $reasonCategory, $notes,
            $reason, $key, $rules, $projection, $limit
        ): array {
            $entry = $this->ledger->post($account, LedgerPosting::adjustment(
                points: $signedPoints,
                bucket: $bucket,
                reason: $reason,
                idempotencyKey: $key,
                occurredAt: now(),
                staffId: (int) $staff->shopify_staff_id,
                // C10, built to the recommendation: adjusted points expire like
                // earned ones, carrying an expiry from the rule version in
                // force. LedgerPosting applies it only to a positive credit
                // into the available bucket.
                expiresAt: now()->addDays($rules->pointsExpiryDays()),
            ));

            $balances = $this->balances->for($account->refresh());

            // AuditLogger refuses points.adjusted without a reason, so the
            // mandatory-reason rule is enforced centrally rather than trusted to
            // this call site.
            $this->audit->log(
                action: AuditAction::POINTS_ADJUSTED,
                subjectType: 'LoyaltyAccount',
                subjectId: (int) $account->id,
                reason: $reason,
                before: [
                    'points_pending' => $projection['points_pending_before'],
                    'points_available' => $projection['points_available_before'],
                    'voucher_balance_pence' => $projection['voucher_balance_pence_before'],
                ],
                after: [
                    'points_pending' => $balances->pending,
                    'points_available' => $balances->available,
                    'voucher_balance_pence' => $balances->voucher->pence(),
                    'points_delta' => $signedPoints,
                    'bucket' => $bucket,
                    'reason_category' => $reasonCategory,
                    'notes' => $notes,
                    'ledger_entry_id' => (int) $entry->id,
                ],
                shopDomain: $account->shop_domain,
            );

            return [
                'entry' => $entry,
                'balances' => $balances,
                'projection' => $projection,
                'limit' => $limit,
                'replayed' => false,
            ];
        });
    }

    /**
     * One reason string, category first.
     *
     * The audit log is searched by people reading it, so the category leads and
     * the notes follow. The structured pair is kept in the audit after-state as
     * well, for filtering.
     */
    public function composeReason(string $category, string $notes): string
    {
        $label = self::REASON_CATEGORIES[$category] ?? $category;

        return $label.' — '.trim($notes);
    }
}
