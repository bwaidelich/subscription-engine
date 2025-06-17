<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Subscription;

use DateTimeImmutable;

/**
 * Representation of a subscription
 */
final readonly class Subscription
{
    public function __construct(
        public SubscriptionId $id,
        public RunMode $runMode,
        public SubscriptionStatus $status,
        public Position $position,
        public SubscriptionError|null $error,
        public DateTimeImmutable|null $lastSavedAt,
    ) {
    }

    public static function create(
        SubscriptionId|string $id,
        RunMode $runMode,
        SubscriptionStatus $status,
    ): self {
        if (is_string($id)) {
            $id = SubscriptionId::fromString($id);
        }
        return new self($id, $runMode, $status, Position::none(), null, null);
    }

    public function with(
        SubscriptionStatus|null $status = null,
        Position|null $position = null,
        SubscriptionError|null $error = null,
    ): self {
        return new self(
            $this->id,
            $this->runMode,
            $status ?? $this->status,
            $position ?? $this->position,
            $error ?? $this->error,
            $this->lastSavedAt,
        );
    }

    public function withError(SubscriptionError $error): self
    {
        return new self(
            $this->id,
            $this->runMode,
            SubscriptionStatus::ERROR,
            $this->position,
            $error,
            $this->lastSavedAt,
        );
    }

    public function withoutError(): self
    {
        return new self(
            $this->id,
            $this->runMode,
            $this->status,
            $this->position,
            null,
            $this->lastSavedAt,
        );
    }
}
