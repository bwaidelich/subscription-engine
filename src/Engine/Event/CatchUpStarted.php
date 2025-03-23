<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Engine\Event;

use Wwwision\SubscriptionEngine\Engine\SubscriptionEngineCriteria;
use Wwwision\SubscriptionEngine\Subscription\Position;
use Wwwision\SubscriptionEngine\Subscription\Subscriptions;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionStatusFilter;

final readonly class CatchUpStarted implements EngineEvent
{

    public function __construct(
        public Subscriptions $subscriptions,
        public Position $startPosition,
    ) {
    }

    public function __toString(): string
    {
        $subscriptionsCount = $this->subscriptions->count();
        return sprintf('Starting catch-up of %d subscription%s from position %d', $subscriptionsCount, $subscriptionsCount > 1 ? 's' : '', $this->startPosition->value);
    }
}