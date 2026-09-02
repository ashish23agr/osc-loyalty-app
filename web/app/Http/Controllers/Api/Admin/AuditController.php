<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Presenters\AuditEntryPresenter;
use App\Http\Requests\Admin\AuditQueryRequest;
use App\Models\AuditEntry;
use App\Models\StaffRole;
use App\Support\Audit\AuditAction;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/admin/audit — who did what, when, and why.
 *
 * Read-only, and there is no companion write endpoint anywhere in the
 * application: entries are only ever created in process, inside the same
 * transaction as the effect they describe, and the model refuses updates and
 * deletes. Manager floor, because the log names people and quotes their reasons.
 *
 * The filter vocabularies come back in the meta — every action, and every actor
 * who has actually appeared on this shop — so the screen builds its dropdowns
 * from what exists rather than from a hard-coded list that will not include the
 * next action added.
 */
class AuditController extends AdminController
{
    public function index(AuditQueryRequest $request): JsonResponse
    {
        $shop = $this->shop($request);
        $actions = $request->actions();

        $query = AuditEntry::query()
            ->where('shop_domain', $shop)
            ->when($actions !== [], fn ($q) => $q->whereIn('action', $actions))
            ->when($request->filled('actor_staff_id'), fn ($q) => $q->where('actor_staff_id', $request->integer('actor_staff_id')))
            ->when($request->filled('actor_type'), fn ($q) => $q->where('actor_type', $request->input('actor_type')))
            ->when($request->filled('subject_type'), fn ($q) => $q->where('subject_type', $request->input('subject_type')))
            ->when($request->filled('subject_id'), fn ($q) => $q->where('subject_id', $request->integer('subject_id')))
            ->when($request->filled('channel'), fn ($q) => $q->where('channel', $request->input('channel')))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')->startOfDay()))
            ->when(
                $request->filled('to'),
                fn ($q) => $q->where('created_at', '<', $request->date('to')->addDay()->startOfDay()),
            );

        if ($term = trim((string) $request->input('q', ''))) {
            $escaped = addcslashes($term, '%_'.chr(92));

            $query->where(function ($inner) use ($escaped): void {
                $inner->where('actor_name', 'like', '%'.$escaped.'%')
                    ->orWhere('reason', 'like', '%'.$escaped.'%');
            });
        }

        $page = $query->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request), ['*'], 'page', (int) $request->input('page', 1));

        $roles = $this->rolesFor($shop);

        return $this->collection(
            $page,
            fn (AuditEntry $entry): array => AuditEntryPresenter::row($entry, $roles),
            [
                'filters' => [
                    'actions' => AuditAction::all(),
                    'actor_types' => ['staff', 'system', 'pos', 'migration'],
                    'channels' => ['admin', 'pos', 'account', 'system'],
                    'actors' => $this->actorsFor($shop),
                ],
            ],
        );
    }

    /** @return array<int, string> shopify_staff_id => role */
    private function rolesFor(string $shop): array
    {
        return StaffRole::query()
            ->where('shop_domain', $shop)
            ->pluck('role', 'shopify_staff_id')
            ->mapWithKeys(fn (string $role, $id): array => [(int) $id => $role])
            ->all();
    }

    /**
     * The people who actually appear in this shop log.
     *
     * Read from the entries rather than from staff_roles, so someone whose role
     * was later revoked is still selectable — otherwise their actions become
     * unfilterable the moment they leave, which is exactly when they matter.
     */
    private function actorsFor(string $shop): array
    {
        return AuditEntry::query()
            ->where('shop_domain', $shop)
            ->whereNotNull('actor_staff_id')
            ->groupBy('actor_staff_id', 'actor_name')
            ->orderBy('actor_name')
            ->get(['actor_staff_id', 'actor_name'])
            ->map(fn (AuditEntry $entry): array => [
                'staff_id' => (int) $entry->actor_staff_id,
                'name' => $entry->actor_name,
            ])
            ->all();
    }
}
