<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Tests\Mocks;

use Wwwision\SubscriptionEngine\Subscription\Position;

final readonly class MockEvent
{

    public function __construct(
        public Position $position,
        public mixed $data,
    ) {
    }
}