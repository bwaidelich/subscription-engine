<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Engine\EngineEvent;

use Wwwision\SubscriptionEngine\Store\SubscriptionCriteria;

/**
 * @implements EngineEvent<void>
 */
final readonly class AllSubscriptionsLocked implements EngineEvent
{
    public function __construct(
        public SubscriptionCriteria $criteria,
    ) {
    }

    public function logLevel(): int
    {
        return LOG_DEBUG;
    }

    public function __toString(): string
    {
        return sprintf('All subscriptions are locked for criteria: %s', json_encode($this->criteria));
    }
}
