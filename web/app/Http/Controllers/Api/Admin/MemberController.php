<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Events\LoyaltyEventName;
use App\Domain\Events\MemberEvents;
use App\Domain\Loyalty\LedgerService;
use App\Domain\Loyalty\RunningBalance;
use App\Domain\Members\MemberSearch;
use App\Http\Presenters\LedgerEntryPresenter;
use App\Http\Presenters\MemberPresenter;
use App\Http\Requests\Admin\EnrolMemberRequest;
use App\Http\Requests\Admin\LedgerQueryRequest;
use App\Http\Requests\Admin\MemberSearchRequest;
use App\Http\Requests\Admin\UpdateMemberRequest;
use App\Models\LedgerEntry;
use App\Models\LoyaltyAccount;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Members: search, enrol, profile, correction, and the movement history.
 *
 * Every read is scoped to the shop from the session token, so a member
 * belonging to another shop is not found rather than forbidden. Every write is
 * audited in the same transaction as its effect.
 */
class MemberController extends AdminController
{
    public function __construct(
        private readonly MemberPresenter $members,
        private readonly LedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * GET /api/admin/members — the customers list.
     *
     * One search box across five identifiers (MD1), plus the segment, channel
     * and status filters the screen offers. The filter vocabularies are returned
     * in the meta so the console builds its dropdowns from the server rather
     * than from a copy that drifts.
     */
    public function index(MemberSearchRequest $request): JsonResponse
    {
        $query = LoyaltyAccount::query()->where('shop_domain', $this->shop($request));

        if ($term = trim((string) $request->input('q', ''))) {
            MemberSearch::apply($query, $term, (string) $request->input('field', 'all'));
        }

        $query->when($request->filled('segment'), fn ($q) => $q->where('segment', $request->input('segment')))
            ->when($request->filled('channel'), fn ($q) => $q->where('enrolment_channel', $request->input('channel')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')));

        if ($request->filled('has_email')) {
            $request->boolean('has_email')
                ? $query->whereNotNull('email_normalised')
                : $query->whereNull('email_normalised');
        }

        $sort = (string) $request->input('sort', 'enrolled_at');
        $direction = (string) $request->input('direction', 'desc');

        $page = $query->orderBy($sort, $direction)
            // A tiebreak, so page two never repeats or skips a row that shares a
            // sort value with the last row of page one.
            ->orderByDesc('id')
            ->paginate($this->perPage($request), ['*'], 'page', (int) $request->input('page', 1));

        return $this->collection(
            $page,
            fn (LoyaltyAccount $account): array => $this->members->row($account),
            [
                'query' => $request->input('q'),
                'field' => $request->input('field', 'all'),
                'filters' => [
                    'fields' => MemberSearch::FIELDS,
                    'segments' => ['active', 'lapsed', 'unknown'],
                    'channels' => ['online', 'pos', 'admin', 'migration'],
                    'statuses' => ['active', 'suspended', 'merged', 'closed'],
                ],
            ],
        );
    }

    /**
     * POST /api/admin/members — enrol from the console.
     *
     * The email is optional (MD1) and the birthday may be a day and month with
     * no year (MD2). A duplicate email is refused with a code and the existing
     * account id, because the answer is always to open that member rather than
     * to create a second one (D10).
     */
    public function store(EnrolMemberRequest $request): JsonResponse
    {
        $shop = $this->shop($request);
        $attributes = $request->validated();

        $normalised = LoyaltyAccount::normaliseEmail($attributes['email'] ?? null);

        if ($normalised !== null) {
            $existing = LoyaltyAccount::query()
                ->where('shop_domain', $shop)
                ->where('email_normalised', $normalised)
                ->first();

            if ($existing !== null) {
                throw ApiException::duplicateEmail($normalised, (int) $existing->id);
            }
        }

        $member = DB::transaction(function () use ($shop, $attributes): LoyaltyAccount {
            $member = LoyaltyAccount::create($attributes + [
                'shop_domain' => $shop,
                // Stamped whenever a consent answer was actually given, so a
                // member who was never asked is distinguishable from one who
                // said no.
                'consent_updated_at' => array_key_exists('email_marketing_consent', $attributes)
                    ? now()
                    : null,
                // Fixed, not taken from the request: this endpoint is the
                // console, and an enrolment channel that could be claimed would
                // make the channel split in R2 meaningless.
                'enrolment_channel' => 'admin',
                'enrolled_at' => now(),
            ]);

            $this->audit->log(
                action: AuditAction::ACCOUNT_ENROLLED,
                subjectType: 'LoyaltyAccount',
                subjectId: (int) $member->id,
                after: [
                    'email' => $member->email,
                    'first_name' => $member->first_name,
                    'last_name' => $member->last_name,
                    'postcode' => $member->postcode,
                    'legacy_card_number' => $member->legacy_card_number,
                    'enrolment_channel' => $member->enrolment_channel,
                ],
                shopDomain: $shop,
            );

            return $member;
        });

        // M11, Sprint 4: the welcome flow. Emitted through the null driver
        // today, so the call site is in place and Sprint 4 only binds a real
        // one. Outside the transaction on purpose - a failure to tell Klaviyo
        // must never roll back an enrolment.
        app(MemberEvents::class)->emit($member, LoyaltyEventName::MEMBER_ENROLLED, [
            'enrolment_channel' => $member->enrolment_channel,
            'has_email' => ! $member->hasNoEmail(),
        ]);

        return response()->json($this->members->profile($member->refresh()), 201);
    }

    /** GET /api/admin/members/{id} — the whole profile screen bar the ledger. */
    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json($this->members->profile($this->member($request, $id)));
    }

    /**
     * PATCH /api/admin/members/{id} — correct profile fields.
     *
     * The email address is the identifier and is not editable: correcting one
     * is a merge, which replays both ledgers, not an overwrite that would
     * silently reassign every past movement to a different person.
     */
    public function update(UpdateMemberRequest $request, string $id): JsonResponse
    {
        $member = $this->writableMember($request, $id);

        if ($request->filled('email')) {
            $submitted = LoyaltyAccount::normaliseEmail($request->input('email'));

            if ($submitted !== $member->email_normalised) {
                throw ApiException::emailImmutable((int) $member->id);
            }
        }

        DB::transaction(function () use ($member, $request): void {
            $member->fill($request->changes());

            if ($member->isDirty('email_marketing_consent')) {
                $member->consent_updated_at = now();
            }

            // Logged before the save, because getDirty() carries the before and
            // after values and is cleared once the row is written. The
            // transaction makes the pair atomic.
            $this->audit->logModelChange(
                action: AuditAction::ACCOUNT_UPDATED,
                model: $member,
            );

            $member->save();
        });

        return response()->json($this->members->profile($member->refresh()));
    }

    /**
     * GET /api/admin/members/{id}/ledger — every movement, newest first.
     *
     * Filtered by type, channel and date, with the balance after each entry.
     * That balance is the account balance at that moment, not a total of the
     * rows on screen, so narrowing the filter never rewrites history.
     */
    public function ledger(LedgerQueryRequest $request, string $id): JsonResponse
    {
        $member = $this->member($request, $id);

        $query = $request->applyTo(
            LedgerEntry::query()->where('loyalty_account_id', $member->id)
        );

        $page = $query->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request), ['*'], 'page', (int) $request->input('page', 1));

        $balances = RunningBalance::forPage((int) $member->id, array_values($page->items()));

        return $this->collection(
            $page,
            fn (LedgerEntry $entry): array => LedgerEntryPresenter::row(
                $entry,
                $balances[(int) $entry->id] ?? [],
            ),
            [
                'member' => [
                    'id' => (int) $member->id,
                    'display_name' => MemberPresenter::displayName($member),
                    'points_available' => (int) $member->points_available,
                    'points_pending' => (int) $member->points_pending,
                    'voucher_balance_pence' => (int) $member->voucher_balance_pence,
                ],
                'filters' => [
                    'types' => LedgerQueryRequest::ENTRY_TYPES,
                    'channels' => LedgerQueryRequest::CHANNELS,
                ],
            ],
        );
    }

    /**
     * POST /api/admin/members/{id}/rebuild-cache — recompute from the ledger.
     *
     * The ledger wins any disagreement, so this is the console equivalent of
     * loyalty:rebuild-balances for one member. Returns before and after, because
     * an operator running it needs to see whether anything actually moved.
     *
     * Permitted on a merged account: it writes no ledger entry, it re-sums one,
     * and a tombstone with a wrong cached balance is still worth correcting.
     */
    public function rebuildCache(Request $request, string $id): JsonResponse
    {
        $member = $this->member($request, $id);

        $before = [
            'points_pending' => (int) $member->points_pending,
            'points_available' => (int) $member->points_available,
            'points_lifetime' => (int) $member->points_lifetime,
            'voucher_balance_pence' => (int) $member->voucher_balance_pence,
        ];

        $after = DB::transaction(function () use ($member, $before): array {
            $after = $this->ledger->rebuild($member)->toCacheAttributes();

            $this->audit->log(
                action: AuditAction::BALANCE_REBUILT,
                subjectType: 'LoyaltyAccount',
                subjectId: (int) $member->id,
                before: $before,
                after: $after,
                shopDomain: $member->shop_domain,
            );

            return $after;
        });

        return response()->json([
            'member_id' => (int) $member->id,
            'before' => $before,
            'after' => $after,
            'changed' => $before !== $after,
        ]);
    }
}
