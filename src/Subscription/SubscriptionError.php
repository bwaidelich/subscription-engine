<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Subscription;

use Throwable;

/**
 * Represents an error that happened during handling of an event by a subscriber
 */
final readonly class SubscriptionError
{
    public function __construct(
        public string $errorMessage,
        public SubscriptionStatus $previousStatus,
        public string|null $errorTrace = null,
    ) {
    }

    public static function fromPreviousStatusAndException(SubscriptionStatus $previousStatus, Throwable $error): self
    {
        return new self($error->getMessage(), $previousStatus, $error->getTraceAsString());
    }
}
