<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Engine\EngineEvent;

use Wwwision\SubscriptionEngine\Subscription\Position;
use Wwwision\SubscriptionEngine\Subscription\Subscription;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionStatus;

/**
 * @implements EngineEvent<void>
 */
final readonly class SubscriberWasReset implements EngineEvent
{
    public function __construct(
        public Subscription $subscription,
        public SubscriptionStatus $previousStatus,
        public SubscriptionStatus $newStatus,
    ) {
    }

    public function logLevel(): int
    {
        return LOG_INFO;
    }

    public function __toString(): string
    {
        return sprintf('Subscriber for "%s" has been reset, changing status from %s to %s', $this->subscription->id->value, $this->previousStatus->value, $this->newStatus->value);
    }
}
