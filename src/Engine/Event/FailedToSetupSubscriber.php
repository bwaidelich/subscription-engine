<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Engine\Event;

use Throwable;
use Wwwision\SubscriptionEngine\Subscriber\Subscriber;
use Wwwision\SubscriptionEngine\Subscription\Subscription;

final readonly class FailedToSetupSubscriber implements EngineEvent
{

    public function __construct(
        public Subscription $subscription,
        public Subscriber $subscriber,
        public Throwable $exception,
    )
    {
    }

    public function __toString(): string
    {
        return sprintf('Subscriber "%s" for "%s" has an error in the setup method: %s', $this->subscriber->handler::class, $this->subscription->id->value, $this->exception->getMessage());
    }
}