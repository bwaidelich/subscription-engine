<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Engine\EngineEvent;

use Throwable;
use Wwwision\SubscriptionEngine\Subscription\Subscription;

/**
 * @implements EngineEvent<void>
 */
final readonly class FailedToSetupSubscriber implements EngineEvent
{
    public function __construct(
        public Subscription $subscription,
        public Throwable $exception,
    ) {
    }

    public function __toString(): string
    {
        return sprintf('Subscriber for "%s" has an error in the setup method: %s', $this->subscription->id->value, $this->exception->getMessage());
    }
}
