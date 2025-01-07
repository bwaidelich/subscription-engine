<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Subscriber;

interface ProvidesReset
{
    public function reset(): void;
}
