<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Subscriber;

use Closure;
use Wwwision\SubscriptionEngine\Subscription\RunMode;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionId;

final readonly class Subscriber
{

    private function __construct(
        public SubscriptionId $id,
        public RunMode $runMode,
        public Closure $handler,
        public Closure|null $setup = null,
        public Closure|null $reset = null,
    ) {
    }

    public static function create(
        SubscriptionId|string $id,
        callable $handler,
        RunMode $runMode = RunMode::FROM_BEGINNING,
        callable|null $setup = null,
        callable|null $reset = null,
    ): self
    {
        if (is_string($id)) {
            $id = SubscriptionId::fromString($id);
        }
        return new self(
            $id,
            $runMode,
            $handler(...),
            $setup !== null ? $setup(...) : null,
            $reset !== null ? $reset(...) : null,
        );
    }
}
