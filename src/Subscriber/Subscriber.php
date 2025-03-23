<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Subscriber;

use Wwwision\SubscriptionEngine\Subscription\RunMode;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionId;

final readonly class Subscriber
{
    public function __construct(
        public SubscriptionId $id,
        public RunMode $runMode,
        public EventHandler $handler,
    ) {
    }
}
