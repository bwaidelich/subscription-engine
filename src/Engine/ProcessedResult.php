<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Engine;

use Wwwision\SubscriptionEngine\SubscriptionEngine;

final readonly class ProcessedResult
{
    /**
     * @param bool $hasPendingEvents Whether calling the catch-up again with the same arguments could process more events. Always false for {@see SubscriptionEngine::catchUpActive()} and {@see SubscriptionEngine::boot()} without a limit
     * @param bool $allSubscriptionsLocked Whether nothing was processed, because all matching subscriptions are locked by other processes. Only ever true for {@see SubscriptionEngine::catchUpNextActive()} and {@see SubscriptionEngine::bootNext()}
     */
    private function __construct(
        public int $numberOfProcessedEvents,
        public Errors|null $errors,
        public bool $hasPendingEvents,
        public bool $allSubscriptionsLocked = false,
    ) {
    }

    public static function success(int $numberOfProcessedEvents, bool $hasPendingEvents = false): self
    {
        return new self($numberOfProcessedEvents, null, $hasPendingEvents);
    }

    public static function failed(int $numberOfProcessedEvents, Errors $errors, bool $hasPendingEvents = false): self
    {
        return new self($numberOfProcessedEvents, $errors, $hasPendingEvents);
    }

    public static function locked(): self
    {
        return new self(0, null, false, true);
    }

    /** @phpstan-assert-if-true null $this->errors */
    public function successful(): bool
    {
        return $this->errors === null;
    }
}
