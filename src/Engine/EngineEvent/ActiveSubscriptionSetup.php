<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Engine\EngineEvent;

use Wwwision\SubscriptionEngine\Subscriber\Subscriber;
use Wwwision\SubscriptionEngine\Subscription\Subscription;

/**
 * @implements EngineEvent<void>
 */
final readonly class ActiveSubscriptionSetup implements EngineEvent
{
    public function __construct(
        public Subscription $subscription,
    ) {
    }

    public function __toString(): string
    {
        return sprintf('Active subscriber for "%s" has been re-setup', $this->subscription->id->value);
    }
}
