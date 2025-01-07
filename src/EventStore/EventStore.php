<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\EventStore;

use Wwwision\SubscriptionEngine\Subscription\Position;

interface EventStore
{
    /**
     * @return iterable<Event>
     */
    public function read(Position $startPosition): iterable;
}
