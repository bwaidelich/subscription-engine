<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\EventStore;

use InvalidArgumentException;
use Wwwision\SubscriptionEngine\Subscription\Position;

/**
 * Options for {@see EventStoreAdapter::read()}
 */
final readonly class ReadOptions
{
    /**
     * @param int|null $limit If specified, at most this number of events must be returned. NULL for no limit
     * @param Position|null $upTo If specified, no event with a higher position must be returned (inclusive). NULL reads to the end of the event log
     * @param object|null $filter If specified, only events matching this adapter specific filter must be returned. NULL for all events
     */
    private function __construct(
        public int|null $limit,
        public Position|null $upTo,
        public object|null $filter,
    ) {
        if ($limit !== null && $limit < 1) {
            throw new InvalidArgumentException(sprintf('limit has to be a positive integer, got: %d', $limit), 1794470400);
        }
        if ($upTo !== null && $upTo->equals(Position::none())) {
            throw new InvalidArgumentException('upTo must not be Position::none(), as it would not read any event', 1794470401);
        }
    }

    public static function create(
        int|null $limit = null,
        Position|null $upTo = null,
        object|null $filter = null,
    ): self {
        return new self($limit, $upTo, $filter);
    }
}
