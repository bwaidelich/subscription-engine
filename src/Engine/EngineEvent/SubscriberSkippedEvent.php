<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Engine\EngineEvent;

use Wwwision\SubscriptionEngine\Subscription\Position;
use Wwwision\SubscriptionEngine\Subscription\Subscription;

/**
 * @template E of object
 * @implements EngineEvent<E>
 */
final readonly class SubscriberSkippedEvent implements EngineEvent
{
    /**
     * @param E $event
     */
    public function __construct(
        public Subscription $subscription,
        public object $event,
        public Position $eventPosition,
    ) {
    }

    public function logLevel(): int
    {
        return LOG_DEBUG;
    }

    public function __toString(): string
    {
        return sprintf('Subscriber for "%s" skipped event "%s" (position: %d) because it is already father ahead (position: %d)', $this->subscription->id->value, get_debug_type($this->event), $this->eventPosition->value, $this->subscription->position->value);
    }
}
