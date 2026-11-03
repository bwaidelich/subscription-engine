<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Engine;

final readonly class ProcessedResult
{
    /**
     * @param bool $hasPendingEvents Whether calling the catch-up again with the same arguments could process more events. Always false for catch-ups without a limit
     */
    private function __construct(
        public int $numberOfProcessedEvents,
        public Errors|null $errors,
        public bool $hasPendingEvents,
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

    /** @phpstan-assert-if-true null $this->errors */
    public function successful(): bool
    {
        return $this->errors === null;
    }
}
