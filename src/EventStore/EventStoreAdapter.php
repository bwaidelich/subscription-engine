<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\EventStore;

use Wwwision\SubscriptionEngine\Subscription\Position;

/**
 * @template E of object
 * @template F of object = object
 */
interface EventStoreAdapter
{
    /**
     * Read events from a given starting position (0 = from beginning) in the order of their position
     *
     * The returned events must honor all given options:
     * At most {@see ReadOptions::$limit} events, none with a position above {@see ReadOptions::$upTo},
     * and exactly the ones matching {@see ReadOptions::$filter}. The filter is of type F
     *
     * @return iterable<E>
     */
    public function read(Position $startPosition, ReadOptions $options): iterable;

    /**
     * Position of the last event in the global event stream
     */
    public function lastPosition(): Position;

    /**
     * Determine the position of a given event in the global stream
     *
     * @param E $event
     */
    public function eventPosition(object $event): Position;

    /**
     * Whether the given event matches the given filter, the same way {@see self::read()} applies it
     *
     * @param F $filter
     * @param E $event
     */
    public function matches(object $filter, object $event): bool;
}
