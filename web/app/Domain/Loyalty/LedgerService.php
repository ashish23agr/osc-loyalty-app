<?php

namespace App\Domain\Loyalty;

use App\Domain\Rules\RulesVersionRepository;
use App\Models\LedgerEntry;
use App\Models\LoyaltyAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The only write path into the ledger.
 *
 * Everything that moves points goes through post(): webhook handlers, scheduled
 * jobs, console actions and the replay command alike. An earn posted by a
 * webhook and one posted by a replay therefore take an identical path, which is
 * what makes the replay trustworthy.
 *
 * Three guarantees:
 *
 *   1. Idempotent. Every posting carries a key that is unique per shop. A
 *      redelivered webhook returns the original entry instead of posting twice.
 *   2. Rule-versioned. Every entry records the rules in force when the event
 *      happened, not when it was processed, so a late webhook for an old order
 *      is evaluated against the older version.
 *   3. Cache-consistent. The cached balances are recomputed from the ledger in
 *      the same transaction, so they cannot be left disagreeing with it.
 */
final class LedgerService
{
    public function __construct(
        private readonly RulesVersionRepository $rules,
        private readonly BalanceCalculator $balances,
        private readonly LotAllocator $allocator,
    ) {}

    /**
     * Post a movement of points.
     *
     * Returns the existing entry unchanged when the idempotency key has already
     * been used, so callers can retry freely without checking first.
     */
    public function post(LoyaltyAccount $account, LedgerPosting $posting): LedgerEntry
    {
        if ($account->isMerged()) {
            throw new RuntimeException(
                'Account '.$account->id.' was merged into '.$account->merged_into_account_id
                .'. Post to the surviving account instead.'
            );
        }

        $rulesVersion = $this->rules->asAt($account->shop_domain, $posting->occurredAt);

        try {
            return DB::transaction(function () use ($account, $posting, $rulesVersion): LedgerEntry {
                $entry = LedgerEntry::create($posting->toAttributes() + [
                    'shop_domain' => $account->shop_domain,
                    'loyalty_account_id' => $account->id,
                    'rules_version_id' => $rulesVersion->versionId,
                ]);

                // Attribute the consumption to specific earnings, oldest first,
                // so expiry knows what is left in each lot. An expiry or an
                // earn reversal names the lot it must draw from; a redemption
                // does not and is pure FIFO.
                if ($posting->consumesAvailable()) {
                    $this->allocator->allocate(
                        $entry,
                        abs($posting->availableDelta),
                        $posting->targetLotEntryId,
                    );
                }

                // D9a: a restore is the opposite motion. Points go back to the
                // lots the redemption consumed, as negative allocation rows, so
                // they keep the expiry they always had.
                if ($posting->releasesLots()) {
                    $this->allocator->release(
                        $entry,
                        $posting->releasesEntryId,
                        $posting->availableDelta,
                    );
                }

                $this->balances->refreshCache($account);

                return $entry;
            });
        } catch (UniqueConstraintViolationException) {
            // The key is already used. That is the idempotency contract working,
            // not an error: hand back what was posted the first time.
            return $this->findByIdempotencyKey($account->shop_domain, $posting->idempotencyKey)
                ?? throw new RuntimeException(
                    'Idempotency key '.$posting->idempotencyKey.' collided but no entry was found.'
                );
        }
    }

    public function findByIdempotencyKey(string $shopDomain, string $key): ?LedgerEntry
    {
        return LedgerEntry::query()
            ->where('shop_domain', $shopDomain)
            ->where('idempotency_key', $key)
            ->first();
    }

    public function hasPosted(string $shopDomain, string $key): bool
    {
        return $this->findByIdempotencyKey($shopDomain, $key) !== null;
    }

    /**
     * Rebuild the cached balances for one account from the ledger.
     *
     * The Blueprint requirement that the ledger wins. Exposed as a service
     * method as well as a command so the console can offer it per member.
     */
    public function rebuild(LoyaltyAccount $account): Balances
    {
        return $this->balances->refreshCache($account);
    }

    /**
     * The full history for one member, oldest first.
     *
     * Ordered by occurrence then id: two entries can share a timestamp, and the
     * id then decides, so a running balance never depends on row order chance.
     */
    public function history(LoyaltyAccount $account): Builder
    {
        return LedgerEntry::query()
            ->where('loyalty_account_id', $account->id)
            ->orderBy('occurred_at')
            ->orderBy('id');
    }
}
