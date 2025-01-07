<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\EventStore;

use Wwwision\SubscriptionEngine\Subscription\Position;

interface Event
{
    public function position(): Position;
}
