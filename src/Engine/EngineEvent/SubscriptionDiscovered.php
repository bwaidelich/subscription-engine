<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Engine\EngineEvent;

use Wwwision\SubscriptionEngine\Subscription\Subscription;

/**
 * @implements EngineEvent<void>
 */
final readonly class SubscriptionDiscovered implements EngineEvent
{
    public function __construct(
        public Subscription $subscription,
    ) {
    }

    public function __toString(): string
    {
        return sprintf('New Subscriber "%s" was found and added to the subscription store', $this->subscription->id->value);
    }
}
