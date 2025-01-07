<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Subscriber;

interface ProvidesSetup
{
    public function setup(): void;
}
