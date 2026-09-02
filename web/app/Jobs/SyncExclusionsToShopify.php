<?php

namespace App\Jobs;

use App\Domain\Redemption\ExclusionSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pushing a saved qualification rule out to the discount function (V6a).
 *
 * Queued because it pages the whole catalogue and writes a metafield per
 * product, which is not work to do inside the request that saved the rule — an
 * Administrator pressing Save should not wait on the Admin API, and a rule that
 * saved but failed to sync must still be saved.
 *
 * Retried, because a partial sync leaves products on a stale flag. The sync is
 * idempotent, so a retry simply writes the same answers again.
 */
class SyncExclusionsToShopify implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 300];

    public function __construct(public readonly string $shopDomain) {}

    public function handle(ExclusionSync $sync): void
    {
        $sync->run($this->shopDomain);
    }
}
