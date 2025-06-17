<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Tests\Mocks;

use Wwwision\SubscriptionEngine\EventStore\EventStoreAdapter;
use Wwwision\SubscriptionEngine\Subscription\Position;

/**
 * @implements EventStoreAdapter<MockEvent>
 */
final class InMemoryEventStoreAdapter implements EventStoreAdapter
{

    /**
     * @var array<MockEvent>
     */
    private array $events = [];

    public function read(Position $startPosition): iterable
    {
        yield from array_slice($this->events, $startPosition->value - 1);
    }

    public function lastPosition(): Position
    {
        return Position::fromInteger(count($this->events));
    }

    public function eventPosition(object $event): Position
    {
        return $event->position;
    }

    public function append(mixed $data): void
    {
        $position = Position::fromInteger(count($this->events) + 1);
        $this->events[] = new MockEvent($position, $data);
    }
}