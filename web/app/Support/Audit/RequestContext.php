<?php

namespace App\Support\Audit;

use App\Models\StaffRole;
use Illuminate\Support\Str;

/**
 * Who is acting, from where, on which request.
 *
 * Resolved once per request by the role middleware and read by the audit logger,
 * so no caller has to remember to pass an actor around. Jobs and commands leave
 * it as the system actor, which is why actor_type exists at all.
 *
 * C12: automated work IS audited, and it identifies itself. forJob() and
 * forWebhook() are the two ways in. Both land on actor_type 'system', which the
 * console already renders as "System" in the agreed UI, and both record WHAT ran
 * in the request id and on the entry rather than inventing a person. actor_name
 * stays null on purpose: an automated actor has no name, and putting one there
 * would put a fiction in the User column of the audit log.
 */
class RequestContext
{
    private ?StaffRole $staff = null;

    private ?int $staffUserId = null;

    private ?string $shopDomain = null;

    private string $channel = 'system';

    private string $actorType = 'system';

    private ?string $ipAddress = null;

    private string $requestId;

    /** The job name or webhook topic behind an automated actor, if any. */
    private ?string $automatedBy = null;

    public function __construct()
    {
        // Stamped on every audit entry and every log line, so an entry and the
        // lines that produced it can be joined after the fact.
        $this->requestId = (string) Str::uuid();
    }

    public function forStaff(
        StaffRole $staff,
        string $shopDomain,
        string $channel = 'admin',
        ?string $ipAddress = null,
    ): self {
        $this->staff = $staff;
        $this->staffUserId = (int) $staff->shopify_staff_id;
        $this->shopDomain = $shopDomain;
        $this->channel = $channel;
        $this->actorType = $channel === 'pos' ? 'pos' : 'staff';
        $this->ipAddress = $ipAddress;

        return $this;
    }

    public function forSystem(?string $shopDomain = null, string $channel = 'system'): self
    {
        $this->staff = null;
        $this->staffUserId = null;
        $this->shopDomain = $shopDomain ?? $this->shopDomain;
        $this->channel = $channel;
        $this->actorType = 'system';

        return $this;
    }

    /**
     * A scheduled sweep or an artisan command (C12).
     *
     * The job name is kept so the audit entry and the log lines for one run can
     * be joined, and so an entry can say which sweep wrote it without relying on
     * the payload alone.
     */
    public function forJob(string $shopDomain, string $jobName): self
    {
        $this->staff = null;
        $this->staffUserId = null;
        $this->shopDomain = $shopDomain;
        $this->channel = 'system';
        $this->actorType = 'system';
        $this->automatedBy = $jobName;

        return $this;
    }

    /**
     * A Shopify webhook handler (C12).
     *
     * The topic is the automated actor. A reversal posted by refunds/create and
     * one posted by a replay are the same effect from different causes, and the
     * audit log is where that difference has to survive.
     */
    public function forWebhook(string $shopDomain, string $topic): self
    {
        $this->staff = null;
        $this->staffUserId = null;
        $this->shopDomain = $shopDomain;
        $this->channel = 'system';
        $this->actorType = 'system';
        $this->automatedBy = $topic;

        return $this;
    }

    /** What automated thing is acting: a job name or a webhook topic. */
    public function automatedBy(): ?string
    {
        return $this->automatedBy;
    }

    public function forMigration(string $shopDomain, ?int $staffUserId = null): self
    {
        $this->shopDomain = $shopDomain;
        $this->staffUserId = $staffUserId;
        $this->channel = 'system';
        $this->actorType = 'migration';

        return $this;
    }

    public function staff(): ?StaffRole
    {
        return $this->staff;
    }

    public function staffUserId(): ?int
    {
        return $this->staffUserId;
    }

    public function staffName(): ?string
    {
        // Falls back to the identifier because resolving a staff id to a name
        // needs the protected read_users scope, which this app deliberately does
        // not request. An Administrator labels each person once instead.
        return $this->staff?->staff_name
            ?? ($this->staffUserId === null ? null : 'Staff '.$this->staffUserId);
    }

    public function shopDomain(): ?string
    {
        return $this->shopDomain;
    }

    public function channel(): string
    {
        return $this->channel;
    }

    public function actorType(): string
    {
        return $this->actorType;
    }

    public function ipAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function requestId(): string
    {
        return $this->requestId;
    }
}
