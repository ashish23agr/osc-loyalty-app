<?php

namespace App\Domain\Rules;

use App\Models\RulesVersion;
use App\Support\Data\Canonical;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Reads and writes rule versions.
 *
 * Saving never updates a row: it writes a new version. That is what allows any
 * historical calculation to be replayed against the rules that were in force at
 * the time, and it is why every ledger entry, redemption and reward records the
 * version it was evaluated under.
 */
final class RulesVersionRepository
{
    /** @var array<string, RuleSet> */
    private array $currentCache = [];

    /**
     * The version in force now, seeding the launch values on first use.
     *
     * Seeding here rather than in a migration keeps the schema free of business
     * values, and means a newly installed shop is immediately usable.
     */
    public function current(string $shopDomain): RuleSet
    {
        return $this->currentCache[$shopDomain] ??= $this->currentModel($shopDomain)->ruleSet();
    }

    public function currentModel(string $shopDomain): RulesVersion
    {
        $version = RulesVersion::query()
            ->where('shop_domain', $shopDomain)
            ->orderByDesc('version')
            ->first();

        return $version ?? $this->seedDefaults($shopDomain);
    }

    /**
     * The version in force at a given moment.
     *
     * A transaction is always evaluated against the rules that applied when it
     * happened, so a late webhook for an order placed before a rule change uses
     * the older version. If nothing was in force yet (an event dated before the
     * first save) the earliest version is used, because refusing to calculate
     * would strand the entry entirely.
     */
    public function asAt(string $shopDomain, DateTimeInterface $at): RuleSet
    {
        $version = RulesVersion::query()
            ->where('shop_domain', $shopDomain)
            ->where('effective_from', '<=', $at)
            ->orderByDesc('effective_from')
            ->orderByDesc('version')
            ->first();

        if ($version) {
            return $version->ruleSet();
        }

        $earliest = RulesVersion::query()
            ->where('shop_domain', $shopDomain)
            ->orderBy('version')
            ->first();

        return $earliest?->ruleSet() ?? $this->seedDefaults($shopDomain)->ruleSet();
    }

    /** @return list<RulesVersion> Newest first. */
    public function history(string $shopDomain, int $limit = 50): array
    {
        return RulesVersion::query()
            ->where('shop_domain', $shopDomain)
            ->orderByDesc('version')
            ->limit($limit)
            ->get()
            ->all();
    }

    public function findVersion(string $shopDomain, int $version): ?RulesVersion
    {
        return RulesVersion::query()
            ->where('shop_domain', $shopDomain)
            ->where('version', $version)
            ->first();
    }

    /**
     * Saves a validated payload as the next version.
     *
     * The version number is allocated inside a transaction with a lock on the
     * shop rows, so two administrators saving at once cannot collide on the
     * unique (shop_domain, version) index.
     */
    public function save(
        string $shopDomain,
        array $payload,
        ?int $staffId = null,
        ?string $staffName = null,
        ?string $changeSummary = null,
    ): RulesVersion {
        $payload = RuleSchema::validate($payload);

        // Guarantee the launch snapshot is version 1 with its backdated
        // effective_from before allocating the next number. Without this, the
        // first save on a fresh shop would become version 1 dated now, and any
        // event predating it would resolve to those new values instead of the
        // launch ones - a rule change applying retrospectively, which is exactly
        // what the versioning exists to prevent.
        $this->currentModel($shopDomain);

        unset($this->currentCache[$shopDomain]);

        return DB::transaction(function () use ($shopDomain, $payload, $staffId, $staffName, $changeSummary) {
            $next = (int) RulesVersion::query()
                ->where('shop_domain', $shopDomain)
                ->lockForUpdate()
                ->max('version') + 1;

            return RulesVersion::create([
                'shop_domain' => $shopDomain,
                'version' => $next,
                'payload' => $payload,
                // Changes apply from the moment they are saved, never
                // retrospectively.
                'effective_from' => now(),
                'change_summary' => $changeSummary,
                'created_by_staff_id' => $staffId,
                'created_by_name' => $staffName,
            ]);
        });
    }

    /**
     * Ensure the launch snapshot exists as version 1.
     *
     * Idempotent: seeding is triggered lazily by the first read on a shop and
     * again by save(), and a reinstall must not fail on the unique
     * (shop_domain, version) index.
     */
    public function seedDefaults(string $shopDomain): RulesVersion
    {
        unset($this->currentCache[$shopDomain]);

        return RulesVersion::firstOrCreate(
            ['shop_domain' => $shopDomain, 'version' => 1],
            [
                'payload' => RuleSet::defaults(),
                // Backdated so an order that arrives for a date before
                // installation still resolves to a rule version rather than
                // nothing.
                'effective_from' => now()->subYears(10),
                'change_summary' => 'Launch values from Blueprint section 06.',
            ],
        );
    }

    /** Field-level differences between two payloads, for the console diff. */
    public function diff(array $before, array $after): array
    {
        $changes = [];

        foreach ($after as $key => $value) {
            $was = $before[$key] ?? null;

            // Compared canonically: MySQL's JSON columns hand map keys back in
            // their own order, and a plain !== would report `qualification` as
            // changed on every save of a rule set nobody edited.
            if (! Canonical::equals($was, $value)) {
                $changes[$key] = ['from' => $was, 'to' => $value];
            }
        }

        return $changes;
    }
}
