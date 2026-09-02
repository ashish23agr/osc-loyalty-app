<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Rules\RuleSchema;
use App\Domain\Rules\RulesVersionRepository;
use App\Http\Requests\Admin\SaveRulesRequest;
use App\Jobs\SyncExclusionsToShopify;
use App\Models\RulesVersion;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The rules engine, read by everyone and written by an Administrator.
 *
 * A save is a new version, never an edit. That is what lets any historical
 * calculation be replayed against the rules in force at the time, and it is why
 * the settings screen can promise that a change is not retrospective and mean
 * it: the old version still exists and every entry already posted still points
 * at it.
 */
class RulesController extends AdminController
{
    public function __construct(
        private readonly RulesVersionRepository $rules,
        private readonly AuditLogger $audit,
    ) {}

    /** GET /api/admin/rules — what is in force, and everything before it. */
    public function show(Request $request): JsonResponse
    {
        $shop = $this->shop($request);
        $current = $this->rules->currentModel($shop);

        return response()->json([
            'current' => self::full($current),
            'versions' => array_map(
                fn (RulesVersion $version): array => self::summary($version),
                $this->rules->history($shop),
            ),
            // The floor the console needs to know before it enables the form.
            // Authorisation is still the middleware on the save route.
            'editable_by' => 'administrator',
        ]);
    }

    /**
     * GET /api/admin/rules/versions/{version} — a historical rule set.
     *
     * Returned with the difference from the version before it, because the
     * question being asked is almost always "what changed", and answering it in
     * the console would mean shipping the diff logic twice.
     */
    public function version(Request $request, string $version): JsonResponse
    {
        $shop = $this->shop($request);
        $found = $this->rules->findVersion($shop, (int) $version);

        if ($found === null) {
            throw ApiException::notFound('No rule version '.$version.' exists on this shop.');
        }

        $previous = $this->rules->findVersion($shop, (int) $version - 1);

        return response()->json(self::full($found) + [
            'previous_version' => $previous?->version,
            'diff' => $previous === null
                ? []
                : $this->rules->diff($previous->payload, $found->payload),
        ]);
    }

    /**
     * POST /api/admin/rules — save a new version.
     *
     * A full snapshot only. RuleSchema rejects a partial payload rather than
     * merging it, because merging would carry forward a value the person saving
     * never saw and could not have agreed to.
     */
    public function store(SaveRulesRequest $request): JsonResponse
    {
        $shop = $this->shop($request);
        $staff = $this->staff($request);

        // Validated up front so a malformed payload is a 422 before anything is
        // written, and so the errors come back keyed by rule name.
        $payload = RuleSchema::validate($request->payload());

        $before = $this->rules->currentModel($shop);

        $saved = DB::transaction(function () use ($shop, $payload, $staff, $request, $before): RulesVersion {
            $version = $this->rules->save(
                shopDomain: $shop,
                payload: $payload,
                staffId: (int) $staff->shopify_staff_id,
                staffName: $staff->staff_name,
                changeSummary: $request->input('change_summary'),
            );

            // Only what moved. A full snapshot on both sides would bury a
            // one-field change in twenty unchanged ones, and the audit screen
            // shows a before-to-after column that has to be readable.
            $changes = $this->rules->diff($before->payload, $version->payload);

            $this->audit->log(
                action: AuditAction::RULES_SAVED,
                subjectType: 'RulesVersion',
                subjectId: (int) $version->id,
                reason: $request->input('change_summary'),
                before: array_map(fn (array $change) => $change['from'], $changes) ?: null,
                after: (array_map(fn (array $change) => $change['to'], $changes) ?: [])
                    + ['version' => (int) $version->version],
                shopDomain: $shop,
            );

            return $version;
        });

        $changed = $this->rules->diff($before->payload, $saved->payload);

        // V6a: the discount function cannot read a rule version, so a change to
        // what qualifies has to be pushed out to the metafields it can read.
        // Only when qualification actually moved - re-syncing a whole catalogue
        // because someone changed the expiry period would be a lot of API calls
        // to write the same answers back.
        //
        // Dispatched after the transaction commits, so a sync cannot read a rule
        // version that is about to be rolled back.
        if (array_key_exists('qualification', $changed)) {
            SyncExclusionsToShopify::dispatch($shop);
        }

        return response()->json(self::full($saved) + [
            'previous_version' => $before->version,
            'diff' => $changed,
        ], 201);
    }

    private static function full(RulesVersion $version): array
    {
        return self::summary($version) + ['payload' => $version->payload];
    }

    private static function summary(RulesVersion $version): array
    {
        return [
            'version_id' => (int) $version->id,
            'version' => (int) $version->version,
            'effective_from' => $version->effective_from?->toIso8601String(),
            'change_summary' => $version->change_summary,
            'created_by_staff_id' => $version->created_by_staff_id === null
                ? null
                : (int) $version->created_by_staff_id,
            'created_by_name' => $version->created_by_name,
            'created_at' => $version->created_at?->toIso8601String(),
        ];
    }
}
