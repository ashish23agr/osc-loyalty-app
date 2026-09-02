<?php

namespace App\Domain\Loyalty;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * A described movement of points, before it becomes a ledger row.
 *
 * Named constructors rather than one wide signature, because the bucket
 * arithmetic is the part that must not be got wrong at a call site: an earn is
 * pending, a maturity is a transfer, an expiry is a withdrawal. Callers state
 * intent and the constructor sets the deltas.
 */
final class LedgerPosting
{
    private function __construct(
        public readonly string $entryType,
        public readonly int $pendingDelta,
        public readonly int $availableDelta,
        public readonly string $idempotencyKey,
        public readonly string $channel,
        public readonly DateTimeInterface $occurredAt,
        public readonly ?string $reason = null,
        public readonly ?int $shopifyOrderId = null,
        public readonly ?string $orderName = null,
        public readonly ?int $shopifyRefundId = null,
        public readonly ?int $shopifyLocationId = null,
        public readonly ?string $staffReference = null,
        public readonly ?int $redemptionId = null,
        public readonly ?int $rewardId = null,
        public readonly ?int $parentEntryId = null,
        public readonly ?int $qualifyingValuePence = null,
        public readonly ?DateTimeInterface $maturesAt = null,
        public readonly ?DateTimeInterface $expiresAt = null,
        // Not a column. Tells LedgerService which lot this consumption must
        // draw from before falling back to FIFO: an expiry consumes the lot
        // that is expiring, an earn reversal takes back the points its own earn
        // created. A redemption leaves it null and is pure FIFO.
        public readonly ?int $targetLotEntryId = null,
        // Not a column either. Set on a redemption_restore, it makes the
        // posting a RELEASE against the named consumption's allocations (D9a)
        // rather than a consumption of its own.
        public readonly ?int $releasesEntryId = null,
    ) {
        if ($this->pendingDelta === 0 && $this->availableDelta === 0) {
            throw new InvalidArgumentException('A ledger entry must move points in at least one bucket.');
        }
    }

    /**
     * Points earned on a paid order. Pending until they mature.
     *
     * D2: expiresAt is derived from maturesAt by the caller, so a member gets
     * the full expiry period of usable life.
     */
    public static function earn(
        int $points,
        string $idempotencyKey,
        DateTimeInterface $occurredAt,
        DateTimeInterface $maturesAt,
        DateTimeInterface $expiresAt,
        string $channel = 'online',
        ?int $shopifyOrderId = null,
        ?string $orderName = null,
        ?int $qualifyingValuePence = null,
        ?int $shopifyLocationId = null,
    ): self {
        self::assertPositive($points, 'An earn');

        return new self(
            entryType: 'earn',
            pendingDelta: $points,
            availableDelta: 0,
            idempotencyKey: $idempotencyKey,
            channel: $channel,
            occurredAt: $occurredAt,
            shopifyOrderId: $shopifyOrderId,
            orderName: $orderName,
            shopifyLocationId: $shopifyLocationId,
            qualifyingValuePence: $qualifyingValuePence,
            maturesAt: $maturesAt,
            expiresAt: $expiresAt,
        );
    }

    /** Pending points becoming available. A transfer, not a credit. */
    public static function maturity(
        int $points,
        int $parentEntryId,
        string $idempotencyKey,
        DateTimeInterface $occurredAt,
        ?DateTimeInterface $expiresAt = null,
    ): self {
        self::assertPositive($points, 'A maturity');

        return new self(
            entryType: 'maturity',
            pendingDelta: -$points,
            availableDelta: $points,
            idempotencyKey: $idempotencyKey,
            channel: 'system',
            occurredAt: $occurredAt,
            parentEntryId: $parentEntryId,
            expiresAt: $expiresAt,
        );
    }

    /** Available points reaching their expiry date. */
    public static function expiry(
        int $points,
        int $parentEntryId,
        string $idempotencyKey,
        DateTimeInterface $occurredAt,
    ): self {
        self::assertPositive($points, 'An expiry');

        return new self(
            entryType: 'expiry',
            pendingDelta: 0,
            availableDelta: -$points,
            idempotencyKey: $idempotencyKey,
            channel: 'system',
            occurredAt: $occurredAt,
            parentEntryId: $parentEntryId,
            // An expiry consumes the lot that is expiring and no other. Left to
            // FIFO it would retire someone else's oldest points and leave the
            // expired lot looking unspent for ever.
            targetLotEntryId: $parentEntryId,
        );
    }

    /**
     * Points spent on a redemption. Available only - pending points are not
     * redeemable, which is what the pending period is for.
     *
     * FIFO by design: no target lot, so the oldest points go first and a member
     * never loses points to expiry while newer ones are spent around them.
     */
    public static function redemption(
        int $points,
        int $redemptionId,
        string $idempotencyKey,
        DateTimeInterface $occurredAt,
        string $channel = 'online',
        ?int $shopifyOrderId = null,
        ?string $orderName = null,
        ?int $shopifyLocationId = null,
        ?string $staffReference = null,
    ): self {
        self::assertPositive($points, 'A redemption');

        return new self(
            entryType: 'redemption',
            pendingDelta: 0,
            availableDelta: -$points,
            idempotencyKey: $idempotencyKey,
            channel: $channel,
            occurredAt: $occurredAt,
            shopifyOrderId: $shopifyOrderId,
            orderName: $orderName,
            shopifyLocationId: $shopifyLocationId,
            staffReference: $staffReference,
            redemptionId: $redemptionId,
        );
    }

    /**
     * Redeemed points handed back after a refund (D9, D9a).
     *
     * The points return to the lots the redemption drew from, keeping their
     * original expiry, so a redeem-and-refund cycle cannot extend the life of a
     * point. $redemptionEntryId is the redemption ledger row being unwound; it
     * is both the parent for the member's history and the consumption whose
     * allocations are released.
     */
    public static function redemptionRestore(
        int $points,
        int $redemptionId,
        int $redemptionEntryId,
        string $idempotencyKey,
        DateTimeInterface $occurredAt,
        ?int $shopifyRefundId = null,
        ?int $shopifyOrderId = null,
        ?string $orderName = null,
        ?string $reason = null,
        string $channel = 'system',
    ): self {
        self::assertPositive($points, 'A redemption restore');

        return new self(
            entryType: 'redemption_restore',
            pendingDelta: 0,
            availableDelta: $points,
            idempotencyKey: $idempotencyKey,
            channel: $channel,
            occurredAt: $occurredAt,
            reason: $reason,
            shopifyOrderId: $shopifyOrderId,
            orderName: $orderName,
            shopifyRefundId: $shopifyRefundId,
            redemptionId: $redemptionId,
            parentEntryId: $redemptionEntryId,
            releasesEntryId: $redemptionEntryId,
        );
    }

    /**
     * Earned points taken back after a refund (D9).
     *
     * Pending first, then available, because points that never matured were
     * never the member's to spend and taking those back costs them nothing. Any
     * remainder comes out of available and may drive the balance negative,
     * which Q3 permits: the ledger stays true and the voucher value floors at
     * zero for the customer.
     */
    public static function earnReversal(
        int $pendingPoints,
        int $availablePoints,
        int $earnEntryId,
        string $idempotencyKey,
        DateTimeInterface $occurredAt,
        ?int $shopifyRefundId = null,
        ?int $shopifyOrderId = null,
        ?string $orderName = null,
        ?string $reason = null,
        string $channel = 'system',
    ): self {
        if ($pendingPoints < 0 || $availablePoints < 0) {
            throw new InvalidArgumentException('A reversal is described in positive points to take back.');
        }

        if ($pendingPoints === 0 && $availablePoints === 0) {
            throw new InvalidArgumentException('A reversal must take back at least one point.');
        }

        return new self(
            entryType: 'earn_reversal',
            pendingDelta: -$pendingPoints,
            availableDelta: -$availablePoints,
            idempotencyKey: $idempotencyKey,
            channel: $channel,
            occurredAt: $occurredAt,
            reason: $reason,
            shopifyOrderId: $shopifyOrderId,
            orderName: $orderName,
            shopifyRefundId: $shopifyRefundId,
            parentEntryId: $earnEntryId,
            // Take back the points this earn created before touching anyone
            // else's lot.
            targetLotEntryId: $earnEntryId,
        );
    }

    /**
     * A migrated opening balance (D4).
     *
     * Immediately available, since there is no pending period for points a
     * member already earned, and dated at go-live with its own grace expiry.
     */
    public static function openingBalance(
        int $points,
        string $idempotencyKey,
        DateTimeInterface $occurredAt,
        DateTimeInterface $expiresAt,
        ?string $reason = null,
    ): self {
        self::assertPositive($points, 'An opening balance');

        return new self(
            entryType: 'opening_balance',
            pendingDelta: 0,
            availableDelta: $points,
            idempotencyKey: $idempotencyKey,
            channel: 'migration',
            occurredAt: $occurredAt,
            reason: $reason,
            expiresAt: $expiresAt,
        );
    }

    /**
     * A manual correction by a member of staff. Reason mandatory.
     *
     * A positive adjustment to the available bucket carries an expiry, so
     * adjusted points behave like earned ones rather than living forever. That
     * is an implementation decision, not a Blueprint rule. See C10.
     */
    public static function adjustment(
        int $points,
        string $bucket,
        string $reason,
        string $idempotencyKey,
        DateTimeInterface $occurredAt,
        ?int $staffId = null,
        ?DateTimeInterface $expiresAt = null,
        string $channel = 'admin',
    ): self {
        if ($points === 0) {
            throw new InvalidArgumentException('An adjustment must be non-zero.');
        }

        if (! in_array($bucket, ['pending', 'available'], true)) {
            throw new InvalidArgumentException('Unknown points bucket: '.$bucket);
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('An adjustment requires a reason.');
        }

        return new self(
            entryType: 'adjustment',
            pendingDelta: $bucket === 'pending' ? $points : 0,
            availableDelta: $bucket === 'available' ? $points : 0,
            idempotencyKey: $idempotencyKey,
            channel: $channel,
            occurredAt: $occurredAt,
            reason: $reason,
            staffReference: $staffId === null ? null : (string) $staffId,
            expiresAt: $points > 0 && $bucket === 'available' ? $expiresAt : null,
        );
    }

    /** True when this posting takes points out of the available bucket. */
    public function consumesAvailable(): bool
    {
        return $this->availableDelta < 0;
    }

    /** True when this posting gives points back to a consumption's lots (D9a). */
    public function releasesLots(): bool
    {
        return $this->releasesEntryId !== null && $this->availableDelta > 0;
    }

    /** True when this posting creates points that can later be consumed or expire. */
    public function createsLot(): bool
    {
        return in_array($this->entryType, ['earn', 'opening_balance'], true)
            || ($this->entryType === 'adjustment' && $this->availableDelta > 0);
    }

    public function toAttributes(): array
    {
        return [
            'entry_type' => $this->entryType,
            'pending_delta' => $this->pendingDelta,
            'available_delta' => $this->availableDelta,
            'idempotency_key' => $this->idempotencyKey,
            'channel' => $this->channel,
            'occurred_at' => $this->occurredAt,
            'reason' => $this->reason,
            'shopify_order_id' => $this->shopifyOrderId,
            'order_name' => $this->orderName,
            'shopify_refund_id' => $this->shopifyRefundId,
            'shopify_location_id' => $this->shopifyLocationId,
            'staff_reference' => $this->staffReference,
            'redemption_id' => $this->redemptionId,
            'reward_id' => $this->rewardId,
            'parent_entry_id' => $this->parentEntryId,
            'qualifying_value_pence' => $this->qualifyingValuePence,
            'matures_at' => $this->maturesAt,
            'expires_at' => $this->expiresAt,
        ];
    }

    private static function assertPositive(int $points, string $what): void
    {
        if ($points <= 0) {
            throw new InvalidArgumentException($what.' must be a positive number of points.');
        }
    }
}
