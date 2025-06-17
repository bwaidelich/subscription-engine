<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Engine\EngineEvent;

use Wwwision\SubscriptionEngine\Subscription\Subscription;

/**
 * @implements EngineEvent<void>
 */
final readonly class NoResetResetFunctionDefined implements EngineEvent
{

    public function __construct(
        public Subscription $subscription,
    ) {
    }

    public function logLevel(): int
    {
        return LOG_DEBUG;
    }

    public function __toString(): string
    {
        return sprintf('No reset function defined for subscriber for "%s"', $this->subscription->id->value);
    }
}
