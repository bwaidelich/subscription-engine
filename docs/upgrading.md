# Upgrading to 2.0

## If you use the engine

`SubscriptionStatus` has the new case `RESETTING`, code with an exhaustive `match` has to handle it. A failing reset function now moves the subscription to `ERROR`, it used to keep its status.

`catchUpNextActive()` and `bootNext()` are new. Unlike `catchUpActive()` and `boot()`, they return a failed result instead of throwing if a run fails after the claim, and the next call claims the failed subscription again. See [Failing runs](./how-it-works.md#failing-runs).

### Known problems of 1.x

> [!WARNING]
> Up to 1.x workers keep running during `setup()` and `reset()`, which can handle events twice or write back an old position. On 1.x stop all workers for both. If workers were running during one of those calls, `reset()` and replay the affected subscriptions.

> [!WARNING]
> Up to 1.x a deadlock inside a subscriber handler on MySQL or MariaDB could persist positions without the events they stand for, for all subscriptions of that run. This can't be detected afterwards. If such deadlocks occurred, `reset()` and replay the affected subscriptions.

## If you maintain a store or an adapter

Implementations of the following interfaces have to be adjusted, otherwise PHP fails with a fatal error.
Implementations of `SubscriptionStore` with these changes are also compatible with 1.x, implementations of `EventStoreAdapter` are not.

### `EventStoreAdapter`

`read()` has a new parameter:

```php
public function read(Position $startPosition, ReadOptions $options): iterable;
```

Implementations must honor all [`ReadOptions`](../src/EventStore/ReadOptions.php): Return at most `limit` events, none with a position above `upTo`, and only the ones that match `filter`. A `null` option means no constraint.

A new method for [filtered subscribers](./how-it-works.md#filtered-subscribers):

```php
public function matches(object $filter, object $event): bool;
```

It has to decide the same way as `read()` with that filter. The second template parameter `F` of `EventStoreAdapter` defines which filters an adapter accepts, others should throw an `InvalidArgumentException`. An adapter without filter support can throw for every filter.

### `SubscriptionStore`

A new method to [roll back a run](./how-it-works.md#exceptions-during-a-catch-up):

```php
public function rollback(): void;
```

It discards all changes since the last `beginTransaction()`. Afterwards the store must be usable for a new transaction.

A new method for [concurrent workers](./how-it-works.md#concurrent-workers):

```php
public function claimNext(SubscriptionCriteria $criteria): Subscription|null;
```

It locks and returns the matching subscription with the lowest position that is not locked by another process, with ties broken by id, without waiting for locks. It must not lock any other subscription, and has to return the state at the time of the lock.

`add()` and `update()` must throw an exception if the database has ended the transaction on its own, for example after a deadlock. Note that `PDO::inTransaction()` still reports `true` on MySQL and MariaDB until the next successful statement.
