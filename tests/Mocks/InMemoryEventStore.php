<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Tests\Mocks;

use Wwwision\SubscriptionEngine\EventStore\Event;
use Wwwision\SubscriptionEngine\EventStore\EventStore;
use Wwwision\SubscriptionEngine\Subscription\Position;

final class InMemoryEventStore implements EventStore
{

    /**
     * @var array<Event>
     */
    private array $events = [];

    public function read(Position $startPosition): iterable
    {
        yield from array_slice($this->events, $startPosition->value);
    }

    public function lastPosition(): Position
    {
        return Position::fromInteger(count($this->events));
    }

    public function append(mixed $data): void
    {
        $position = Position::fromInteger(count($this->events) + 1);
        $this->events[] = new class ($position, $data) implements Event {

            public function __construct(
                private Position $position,
                public mixed $data,
            ) {
            }

            public function position(): Position
            {
                return $this->position;
            }
        };
    }
}