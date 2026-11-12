<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Subscriber;

use Closure;
use Wwwision\SubscriptionEngine\EventStore\EventStoreAdapter;
use Wwwision\SubscriptionEngine\Subscription\RunMode;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionId;

final readonly class Subscriber
{

    /**
     * @param object|null $filter Adapter specific filter, see {@see EventStoreAdapter::read()}. Not persisted: Changing it for an existing subscription requires a new id or a reset
     */
    private function __construct(
        public SubscriptionId $id,
        public RunMode $runMode,
        public Closure $handler,
        public Closure|null $setup = null,
        public Closure|null $reset = null,
        public object|null $filter = null,
    ) {
    }

    public static function create(
        SubscriptionId|string $id,
        callable $handler,
        RunMode $runMode = RunMode::FROM_BEGINNING,
        callable|null $setup = null,
        callable|null $reset = null,
        object|null $filter = null,
    ): self {
        if (is_string($id)) {
            $id = SubscriptionId::fromString($id);
        }
        return new self(
            $id,
            $runMode,
            $handler(...),
            $setup !== null ? $setup(...) : null,
            $reset !== null ? $reset(...) : null,
            $filter,
        );
    }
}
