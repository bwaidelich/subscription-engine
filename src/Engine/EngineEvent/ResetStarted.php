<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Engine\EngineEvent;

/**
 * @implements EngineEvent<void>
 */
final readonly class ResetStarted implements EngineEvent
{

    public function logLevel(): int
    {
        return LOG_DEBUG;
    }

    public function __toString(): string
    {
        return 'Start to setup';
    }
}
