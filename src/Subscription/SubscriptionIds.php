<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Subscription;

use Countable;
use IteratorAggregate;
use JsonSerializable;

/**
 * @implements \IteratorAggregate<SubscriptionId>
 */
final readonly class SubscriptionIds implements IteratorAggregate, Countable, JsonSerializable
{
    /**
     * @param array<string, SubscriptionId> $subscriptionIdsById
     */
    private function __construct(
        private array $subscriptionIdsById
    ) {
    }

    /**
     * @param array<string|SubscriptionId> $ids
     */
    public static function fromArray(array $ids): self
    {
        $subscriptionIdsById = [];
        foreach ($ids as $id) {
            if (is_string($id)) {
                $id = SubscriptionId::fromString($id);
            }
            if (!$id instanceof SubscriptionId) {
                throw new \InvalidArgumentException(sprintf('Expected instance of %s, got: %s', SubscriptionId::class, get_debug_type($id)), 1731580820);
            }
            if (array_key_exists($id->value, $subscriptionIdsById)) {
                throw new \InvalidArgumentException(sprintf('Subscription id "%s" is already part of this set', $id->value), 1731580838);
            }
            $subscriptionIdsById[$id->value] = $id;
        }
        return new self($subscriptionIdsById);
    }

    public static function none(): self
    {
        return self::fromArray([]);
    }

    public function getIterator(): \Traversable
    {
        yield from array_values($this->subscriptionIdsById);
    }

    public function isEmpty(): bool
    {
        return $this->subscriptionIdsById === [];
    }

    public function count(): int
    {
        return count($this->subscriptionIdsById);
    }

    public function contain(SubscriptionId $id): bool
    {
        return array_key_exists($id->value, $this->subscriptionIdsById);
    }

    public function intersection(self $other): self
    {
        return new self(array_intersect_key($this->subscriptionIdsById, $other->subscriptionIdsById));
    }

    public function merge(self $other): self
    {
        return new self(array_replace($this->subscriptionIdsById, $other->subscriptionIdsById));
    }

    /**
     * @return list<string>
     */
    public function toStringArray(): array
    {
        return array_values(array_map(static fn (SubscriptionId $id) => $id->value, $this->subscriptionIdsById));
    }

    /**
     * @return list<string>
     */
    public function jsonSerialize(): array
    {
        return $this->toStringArray();
    }
}
