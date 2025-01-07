<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Engine;

use Throwable;
use Wwwision\SubscriptionEngine\Subscription\Position;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionId;

final readonly class Error
{
    private function __construct(
        public SubscriptionId $subscriptionId,
        public string $message,
        public Throwable|null $throwable,
        public Position|null $position,
    ) {
    }

    public static function create(
        SubscriptionId $subscriptionId,
        string $message,
        Throwable|null $throwable,
        Position|null $position,
    ): self {
        return new self(
            $subscriptionId,
            $message,
            $throwable,
            $position
        );
    }
}
