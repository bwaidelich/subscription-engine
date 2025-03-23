<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Engine\Event;

use Wwwision\SubscriptionEngine\Subscription\Subscription;

final readonly class SubscriberNotFound implements EngineEvent
{

    public function __construct(
        public Subscription $subscription,
    ) {
    }

    public function __toString(): string
    {
        return sprintf('Subscriber for "%s" not found', $this->subscription->id->value);
    }
}