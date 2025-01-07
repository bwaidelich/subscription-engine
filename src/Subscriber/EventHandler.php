<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Subscriber;

use Wwwision\SubscriptionEngine\EventStore\Event;

interface EventHandler
{
    public function __invoke(Event $event): void;
}
