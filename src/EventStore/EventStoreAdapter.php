<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\EventStore;

use Wwwision\SubscriptionEngine\Subscription\Position;

/**
 * @template E of object
 */
interface EventStoreAdapter
{
    /**
     * Read events from a given starting position (0 = from beginning)
     *
     * @return iterable<E>
     */
    public function read(Position $startPosition): iterable;

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
}
