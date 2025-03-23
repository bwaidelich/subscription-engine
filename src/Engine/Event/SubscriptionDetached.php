<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Engine\Event;

use Wwwision\SubscriptionEngine\Subscription\Subscription;

final readonly class SubscriptionDetached implements EngineEvent
{

    public function __construct(
        public Subscription $subscription,
    ) {
    }

    public function __toString(): string
    {
        return sprintf('Subscription "%s" was marked detached', $this->subscription->id->value);
    }
}