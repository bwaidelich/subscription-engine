<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Engine\EngineEvent;

use Wwwision\SubscriptionEngine\Subscription\Position;
use Wwwision\SubscriptionEngine\Subscription\Subscription;

/**
 * @template E of object
 * @implements EngineEvent<E>
 */
final readonly class SubscriberHandledEvent implements EngineEvent
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

    public function __toString(): string
    {
        return sprintf('Subscriber for "%s" processed event "%s" (position: %d)', $this->subscription->id->value, get_debug_type($this->event), $this->eventPosition->value);
    }
}
