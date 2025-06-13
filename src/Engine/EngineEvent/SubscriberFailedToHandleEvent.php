<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Engine\EngineEvent;

use Throwable;
use Wwwision\SubscriptionEngine\Subscription\Position;
use Wwwision\SubscriptionEngine\Subscription\Subscription;

/**
 * @template E of object
 * @implements EngineEvent<E>
 */
final readonly class SubscriberFailedToHandleEvent implements EngineEvent
{
    /**
     * @param E $event
     */
    public function __construct(
        public Subscription $subscription,
        public object $event,
        public Position $eventPosition,
        public Throwable $exception,
    ) {
    }

    public function __toString(): string
    {
        return sprintf('Subscriber for "%s" failed to process event "%s" (position: %d): %s', $this->subscription->id->value, get_debug_type($this->event), $this->eventPosition->value, $this->exception->getMessage());
    }
}
