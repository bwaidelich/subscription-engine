<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Engine\Event;

use Throwable;
use Wwwision\SubscriptionEngine\Subscriber\Subscriber;
use Wwwision\SubscriptionEngine\Subscription\Subscription;
use \Wwwision\SubscriptionEngine\EventStore\Event;

final readonly class SubscriberFailedToHandleEvent implements EngineEvent
{

    public function __construct(
        public Subscription $subscription,
        public Subscriber $subscriber,
        public Event $event,
        public Throwable $exception,
    ) {
    }

    public function __toString(): string
    {
        return sprintf('Subscriber "%s" for "%s" failed to process event "%s" (position: %d): %s', $this->subscriber->handler::class, $this->subscription->id->value, get_debug_type($this->event), $this->event->position()->value, $this->exception->getMessage());
    }
}