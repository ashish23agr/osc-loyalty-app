<?php

namespace App\Support\Audit;

use App\Models\AuditEntry;
use App\Support\Data\Canonical;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Writes the audit log.
 *
 * There is no HTTP endpoint that creates an entry and no way to edit one: the
 * model refuses updates and deletes, and in production the application database
 * user should not hold those grants either. Entries are only ever created in
 * process, and callers are expected to write them inside the same transaction as
 * the effect they describe, so the two cannot diverge.
 *
 * The reason is enforced here rather than at each call site, because "who did
 * this and why" is the whole point of the log and a missing reason is not
 * something to discover during a dispute.
 */
class AuditLogger
{
    public function __construct(private readonly RequestContext $context) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function log(
        string $action,
        string $subjectType,
        ?int $subjectId = null,
        ?string $reason = null,
        ?array $before = null,
        ?array $after = null,
        ?string $shopDomain = null,
    ): AuditEntry {
        if (! AuditAction::isKnown($action)) {
            throw new InvalidArgumentException('Unknown audit action: '.$action);
        }

        $reason = $reason === null ? null : trim($reason);

        if (AuditAction::requiresReason($action) && ($reason === null || $reason === '')) {
            throw new InvalidArgumentException('The action '.$action.' requires a reason.');
        }

        $shop = $shopDomain ?? $this->context->shopDomain();

        if ($shop === null) {
            throw new InvalidArgumentException('An audit entry needs a shop domain.');
        }

        return AuditEntry::create([
            'shop_domain' => $shop,
            'actor_type' => $this->context->actorType(),
            'actor_staff_id' => $this->context->staffUserId(),
            'actor_name' => $this->context->staffName(),
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'reason' => $reason,
            'before_state' => $before,
            'after_state' => $after,
            'channel' => $this->context->channel(),
            'ip_address' => $this->context->ipAddress(),
            'request_id' => $this->context->requestId(),
        ]);
    }

    /**
     * Log an action against a model, recording what changed.
     *
     * Snapshots are taken from the model dirty state, so only the fields that
     * actually moved are stored. A full row copy would bury the change in noise
     * and grow the log for no benefit.
     */
    public function logModelChange(
        string $action,
        Model $model,
        ?string $reason = null,
        ?array $only = null,
    ): AuditEntry {
        $changed = $model->getDirty();

        if ($only !== null) {
            $changed = array_intersect_key($changed, array_flip($only));
        }

        // MySQL's JSON columns do not preserve key order: a payload written as
        // {mode, include_tags, ...} comes back as {mode, exclude_tags, ...}, so
        // Eloquent's dirty check sees a cast array as changed when nothing in
        // it moved. Left alone, every rules save would record `qualification`
        // as having changed, and an audit log that cries wolf on a field nobody
        // touched is worse than one that omits it.
        //
        // SQLite stores the text verbatim and never shows this, which is why it
        // survived Sprint 1.
        $changed = array_filter(
            $changed,
            fn (string $attribute): bool => ! Canonical::equals(
                $model->getOriginal($attribute),
                $model->getAttribute($attribute),
            ),
            ARRAY_FILTER_USE_KEY,
        );

        $before = [];
        $after = [];

        foreach (array_keys($changed) as $attribute) {
            $before[$attribute] = $model->getOriginal($attribute);
            $after[$attribute] = $model->getAttribute($attribute);
        }

        return $this->log(
            action: $action,
            subjectType: class_basename($model),
            subjectId: $model->getKey(),
            reason: $reason,
            before: $before === [] ? null : $before,
            after: $after === [] ? null : $after,
            shopDomain: $model->getAttribute('shop_domain'),
        );
    }

    public function context(): RequestContext
    {
        return $this->context;
    }
}
