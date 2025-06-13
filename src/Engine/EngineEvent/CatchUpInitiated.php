<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Engine\EngineEvent;

use Wwwision\SubscriptionEngine\Engine\SubscriptionEngineCriteria;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionStatusFilter;

/**
 * @implements EngineEvent<void>
 */
final readonly class CatchUpInitiated implements EngineEvent
{
    public function __construct(
        public SubscriptionEngineCriteria $criteria,
        public SubscriptionStatusFilter $status
    ) {
    }

    public function __toString(): string
    {
        return sprintf('Initiated catch-up of subscriptions in states %s', implode(',', $this->status->toStringArray()));
    }
}
