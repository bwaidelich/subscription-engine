<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Engine\EngineEvent;

use Wwwision\SubscriptionEngine\Subscription\Subscription;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionStatus;

/**
 * @implements EngineEvent<void>
 */
final readonly class SubscriptionActivated implements EngineEvent
{
    public function __construct(
        public Subscription $subscription,
        public SubscriptionStatus $previousStatus,
    ) {
    }

    public function logLevel(): int
    {
        return LOG_INFO;
    }

    public function __toString(): string
    {
        return sprintf('Subscription "%s" has been set to ACTIVE from status %s', $this->subscription->id->value, $this->previousStatus->value);
    }
}
