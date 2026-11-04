<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Engine\EngineEvent;

use Wwwision\SubscriptionEngine\Subscription\Subscription;

/**
 * @implements EngineEvent<void>
 */
final readonly class ResetAlreadyInProgress implements EngineEvent
{
    public function __construct(
        public Subscription $subscription,
    ) {
    }

    public function logLevel(): int
    {
        return LOG_WARNING;
    }

    public function __toString(): string
    {
        return sprintf('Subscription "%s" is already being reset, or a previous reset has crashed. Skipping it, use the "force" flag to take over', $this->subscription->id->value);
    }
}
