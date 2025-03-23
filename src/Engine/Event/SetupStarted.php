<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Engine\Event;

use Wwwision\SubscriptionEngine\Subscription\Subscription;

final readonly class SetupStarted implements EngineEvent
{

    public function __toString(): string
    {
        return 'Start to setup';
    }
}