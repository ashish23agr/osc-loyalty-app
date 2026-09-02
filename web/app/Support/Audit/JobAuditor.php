<?php

namespace App\Support\Audit;

use Throwable;

/**
 * One audit entry per scheduled run, and only one (C12).
 *
 * The grain is the whole point. A sweep that matured four thousand members'
 * points has already written four thousand ledger entries, each naming the
 * member, the earning and the amount. Copying that into the audit log would
 * double the storage to say nothing new, and would bury the entries a dispute
 * actually needs — who changed a rule, who adjusted a balance, who merged two
 * members — under automated noise.
 *
 * So the audit log answers a different question about automated work: **did the
 * engine run, when, against what date, for how long, and how much did it
 * move.** Per-member detail is a ledger query away, joined by time.
 *
 * A run that throws is recorded too. A sweep that fails silently is the failure
 * this programme is most exposed to, because points simply stop moving and
 * nothing on any screen says why.
 */
final class JobAuditor
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RequestContext $context,
    ) {}

    /**
     * Run a sweep for one shop and audit the outcome exactly once.
     *
     * @param  callable():array<string, mixed>  $work  Returns the counts to record.
     * @return array<string, mixed> The counts, plus duration_ms.
     */
    public function record(string $shopDomain, string $jobName, ?string $asOf, callable $work): array
    {
        $this->context->forJob($shopDomain, $jobName);

        $startedAt = microtime(true);

        try {
            $counts = $work();
        } catch (Throwable $e) {
            $this->audit->log(
                action: AuditAction::JOB_FAILED,
                subjectType: 'ScheduledJob',
                subjectId: null,
                after: [
                    'job' => $jobName,
                    'as_of' => $asOf,
                    'duration_ms' => self::elapsed($startedAt),
                    'error' => mb_substr($e->getMessage(), 0, 400),
                ],
                shopDomain: $shopDomain,
            );

            throw $e;
        }

        $entry = [
            'job' => $jobName,
            'as_of' => $asOf,
            'duration_ms' => self::elapsed($startedAt),
        ] + $counts;

        $this->audit->log(
            action: AuditAction::JOB_COMPLETED,
            subjectType: 'ScheduledJob',
            subjectId: null,
            after: $entry,
            shopDomain: $shopDomain,
        );

        return $entry;
    }

    private static function elapsed(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
