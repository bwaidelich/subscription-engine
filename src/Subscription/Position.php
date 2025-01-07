<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Subscription;

use InvalidArgumentException;

/**
 * The position of a subscription, this usually refers to the global sequence number of the corresponding event
 */
final readonly class Position
{
    private function __construct(
        public readonly int $value
    ) {
        if ($value < 0) {
            throw new InvalidArgumentException("position has to be a non-negative integer, got: $this->value", 1736158859);
        }
    }

    public static function fromInteger(int $value): self
    {
        return new self($value);
    }

    public static function none(): self
    {
        return new self(0);
    }

    public function next(): self
    {
        return new self($this->value + 1);
    }

    public function equals(self $other): bool
    {
        return $other->value === $this->value;
    }

    public function isAheadOf(self $other): bool
    {
        return $this->value > $other->value;
    }
}
