<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Subscriber;

use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionId;

/**
 * @implements IteratorAggregate<Subscriber>
 */
final readonly class Subscribers implements IteratorAggregate, Countable
{
    /**
     * @param array<string, Subscriber> $subscribersById
     */
    private function __construct(
        private array $subscribersById
    ) {
    }

    /**
     * @param array<Subscriber> $subscribers
     */
    public static function fromArray(array $subscribers): self
    {
        $subscribersById = [];
        foreach ($subscribers as $subscriber) {
            if (!$subscriber instanceof Subscriber) {
                throw new InvalidArgumentException(sprintf('Expected instance of %s, got: %s', Subscriber::class, get_debug_type($subscriber)), 1736159393);
            }
            if (array_key_exists($subscriber->id->value, $subscribersById)) {
                throw new InvalidArgumentException(sprintf('Subscriber with id "%s" is already part of this set', $subscriber->id->value), 1736159403);
            }
            $subscribersById[$subscriber->id->value] = $subscriber;
        }
        return new self($subscribersById);
    }

    public static function none(): self
    {
        return self::fromArray([]);
    }

    public function with(Subscriber $subscriber): self
    {
        return new self([...$this->subscribersById, $subscriber->id->value => $subscriber]);
    }

    public function get(SubscriptionId $id): Subscriber
    {
        if (!$this->contain($id)) {
            throw new InvalidArgumentException(sprintf('Subscriber with the subscription id "%s" not found.', $id->value), 1736159425);
        }
        return $this->subscribersById[$id->value];
    }

    public function contain(SubscriptionId $id): bool
    {
        return array_key_exists($id->value, $this->subscribersById);
    }

    public function getIterator(): \Traversable
    {
        yield from array_values($this->subscribersById);
    }

    public function count(): int
    {
        return count($this->subscribersById);
    }
}
