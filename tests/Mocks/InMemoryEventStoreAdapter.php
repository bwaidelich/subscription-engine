<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Tests\Mocks;

use InvalidArgumentException;
use Wwwision\SubscriptionEngine\EventStore\EventStoreAdapter;
use Wwwision\SubscriptionEngine\EventStore\ReadOptions;
use Wwwision\SubscriptionEngine\Subscription\Position;

/**
 * @implements EventStoreAdapter<MockEvent, MockFilter>
 */
final class InMemoryEventStoreAdapter implements EventStoreAdapter
{

    /**
     * @var array<MockEvent>
     */
    private array $events = [];

    /**
     * @var array<ReadOptions>
     */
    private array $readOptions = [];

    public function __construct(
        private readonly bool $ignoreLimit = false,
    ) {
    }

    public function read(Position $startPosition, ReadOptions $options): iterable
    {
        $this->readOptions[] = $options;
        $numberOfEvents = 0;
        foreach (array_slice($this->events, max($startPosition->value - 1, 0)) as $event) {
            if ($options->upTo !== null && $event->position->isAheadOf($options->upTo)) {
                return;
            }
            if ($options->filter !== null && !$this->matches($options->filter, $event)) {
                continue;
            }
            if (!$this->ignoreLimit && $options->limit !== null && $numberOfEvents === $options->limit) {
                return;
            }
            $numberOfEvents++;
            yield $event;
        }
    }

    public function lastPosition(): Position
    {
        return Position::fromInteger(count($this->events));
    }

    public function eventPosition(object $event): Position
    {
        return $event->position;
    }

    public function matches(object $filter, object $event): bool
    {
        if (!$filter instanceof MockFilter) {
            throw new InvalidArgumentException(sprintf('Expected filter of type %s, got: %s', MockFilter::class, get_debug_type($filter)), 1794470500);
        }
        return in_array($event->data, $filter->data, true);
    }

    public function append(mixed $data): void
    {
        $position = Position::fromInteger(count($this->events) + 1);
        $this->events[] = new MockEvent($position, $data);
    }

    public function _lastReadLimit(): int|null
    {
        return $this->_lastReadOptions()?->limit;
    }

    public function _lastReadOptions(): ReadOptions|null
    {
        return $this->readOptions[array_key_last($this->readOptions) ?? -1] ?? null;
    }

    public function _numberOfReads(): int
    {
        return count($this->readOptions);
    }
}
