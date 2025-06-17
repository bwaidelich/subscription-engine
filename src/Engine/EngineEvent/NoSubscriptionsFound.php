<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Engine\EngineEvent;

use Wwwision\SubscriptionEngine\Store\SubscriptionCriteria;

/**
 * @implements EngineEvent<void>
 */
final readonly class NoSubscriptionsFound implements EngineEvent
{
    public function __construct(
        public SubscriptionCriteria $criteria,
    ) {
    }

    public function logLevel(): int
    {
        return LOG_INFO;
    }

    public function __toString(): string
    {
        return sprintf('No subscriptions found for criteria: %s', json_encode($this->criteria));
    }
}
