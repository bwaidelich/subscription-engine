<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Engine\Event;

use Wwwision\SubscriptionEngine\Subscriber\Subscriber;
use Wwwision\SubscriptionEngine\Subscription\Subscription;

final readonly class ActiveSubscriptionSetup implements EngineEvent
{

    public function __construct(
        public Subscription $subscription,
        public Subscriber $subscriber,
    ) {
    }

    public function __toString(): string
    {
        return sprintf('Active subscriber "%s" for "%s" has been re-setup', $this->subscriber->handler::class, $this->subscription->id->value);
    }
}