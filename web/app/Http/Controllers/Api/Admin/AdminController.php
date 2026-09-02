<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyAccount;
use App\Models\StaffRole;
use App\Support\Api\ApiException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared plumbing for the admin console API.
 *
 * The shop and the acting staff member are read from the request attributes the
 * role middleware set, never from a parameter. That is the whole point of
 * establishing them once: an endpoint cannot accidentally trust a shop the
 * caller supplied.
 */
abstract class AdminController extends Controller
{
    protected const DEFAULT_PER_PAGE = 25;

    protected const MAX_PER_PAGE = 100;

    protected function shop(Request $request): string
    {
        return (string) $request->attributes->get('shopDomain');
    }

    protected function staff(Request $request): StaffRole
    {
        return $request->attributes->get('staffRole');
    }

    /**
     * A member on this shop, or a 404.
     *
     * Scoped to the shop rather than looked up by id alone, so a record
     * belonging to another shop is not found rather than forbidden — plan
     * section 5.2, and the difference matters because a 403 would confirm the
     * record exists.
     */
    protected function member(Request $request, int|string $id): LoyaltyAccount
    {
        $member = LoyaltyAccount::query()
            ->where('shop_domain', $this->shop($request))
            ->find((int) $id);

        return $member ?? throw ApiException::notFound('No member with that id exists on this shop.');
    }

    /** A member that may be written to. A merged account is a tombstone. */
    protected function writableMember(Request $request, int|string $id): LoyaltyAccount
    {
        $member = $this->member($request, $id);

        if ($member->isMerged()) {
            throw ApiException::accountMerged((int) $member->id, $member->merged_into_account_id);
        }

        return $member;
    }

    protected function perPage(Request $request): int
    {
        return min(
            max((int) $request->integer('per_page', self::DEFAULT_PER_PAGE), 1),
            self::MAX_PER_PAGE,
        );
    }

    /**
     * The collection envelope from plan section 5.2.
     *
     * @param  callable(mixed): array  $map
     */
    protected function collection(LengthAwarePaginator $page, callable $map, array $meta = []): JsonResponse
    {
        return response()->json([
            'data' => array_map($map, $page->items()),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ] + $meta,
        ]);
    }
}
