<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Engine\Event;

use Wwwision\SubscriptionEngine\Subscriber\Subscriber;
use Wwwision\SubscriptionEngine\Subscription\Subscription;
use \Wwwision\SubscriptionEngine\EventStore\Event;

final readonly class SubscriberHandledEvent implements EngineEvent
{

    public function __construct(
        public Subscription $subscription,
        public Subscriber $subscriber,
        public Event $event,
    ) {
    }

    public function __toString(): string
    {
        return sprintf('Subscriber "%s" for "%s" processed event "%s" (position: %d)', $this->subscriber->handler::class, $this->subscription->id->value, get_debug_type($this->event), $this->event->position()->value);
    }
}