<?php

namespace App\Support\Money;

use InvalidArgumentException;

/**
 * Money, in integer minor units.
 *
 * Floating-point pounds and a five pound rounding rule do not belong in the
 * same system, so every monetary value in the loyalty domain is an integer of
 * pence and every field carrying one is named with a _pence suffix.
 *
 * Currency is deliberately not carried here. The programme is single-currency
 * per shop and the currency lives on the rule version, so putting it on every
 * amount would invite mixed-currency arithmetic that cannot happen.
 */
final readonly class Pence
{
    private function __construct(public int $amount) {}

    public static function of(int $amount): self
    {
        return new self($amount);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function plus(self|int $other): self
    {
        return new self($this->amount + self::valueOf($other));
    }

    public function minus(self|int $other): self
    {
        return new self($this->amount - self::valueOf($other));
    }

    public function times(int $factor): self
    {
        return new self($this->amount * $factor);
    }

    /**
     * Rounds DOWN to a whole multiple. Used for the five pound voucher
     * increment, and for nothing else - rounding up would hand out money the
     * member has not earned.
     */
    public function floorToMultipleOf(self|int $increment): self
    {
        $step = self::valueOf($increment);

        if ($step <= 0) {
            throw new InvalidArgumentException('A rounding increment must be positive.');
        }

        if ($this->amount <= 0) {
            return self::zero();
        }

        return new self(intdiv($this->amount, $step) * $step);
    }

    /** The smaller of the two. The redemption ladder is a chain of these. */
    public function min(self|int $other): self
    {
        return new self(min($this->amount, self::valueOf($other)));
    }

    /** Never show a customer a negative reward: a clawback floors at zero. */
    public function atLeastZero(): self
    {
        return $this->amount < 0 ? self::zero() : $this;
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    public function toInt(): int
    {
        return $this->amount;
    }

    /** Presentation only, and only at the edge. */
    public function format(string $symbol = '£'): string
    {
        return sprintf('%s%s%.2f', $this->amount < 0 ? '-' : '', $symbol, abs($this->amount) / 100);
    }

    private static function valueOf(self|int $value): int
    {
        return $value instanceof self ? $value->amount : $value;
    }
}
