# Subscription engine for Event Stores

Subscription engine for event-sourced systems

## Usage

This package contains base types and interfaces for Subscription Engines.
In order to use it, implementations for the following interfaces have to be provided:

### `SubscriptionStore`

The [SubscriptionStore](./src/Store/SubscriptionStore.php) allows to persist and update the state of subscriptions and allows to lock access such that multiple processes cannot change the same subscription at the same time.

#### Implementations

- [wwwision/subscription-engine-doctrine](https://packagist.org/packages/wwwision/subscription-engine-doctrine) provides a DBAL implementation that is compatible with MySQL/MariaDB, PostgreSQL and SQLite.
- [ekvedaras/subscription-engine-illuminate](https://packagist.org/packages/ekvedaras/subscription-engine-illuminate) provides an implementation that uses [Laravel database](https://laravel.com/docs/12.x/database).

### `EventStoreAdapter`

The [EventStoreAdapter](./src/EventStore/EventStoreAdapter.php) is the adapter for the actual event store implementation

#### Implementations

- [wwwision/subscription-engine-neos-adapter](https://packagist.org/packages/wwwision/subscription-engine-neos-adapter) - adapter for the [neos/eventstore](https://packagist.org/packages/neos/eventstore)
- [wwwision/subscription-engine-dcb-adapter](https://packagist.org/packages/wwwision/subscription-engine-dcb-adapter) - adapter for the [wwwision/dcb-eventstore](https://packagist.org/packages/wwwision/dcb-eventstore)

## Bounded catch-ups

By default, `SubscriptionEngine::catchUpActive()` and `SubscriptionEngine::boot()` read to the end of the event log in a single transaction and hold the lock on all matching subscriptions until they are done.
With the `limit` parameter a call reads at most that number of events, persists the positions, commits and returns:

```php
do {
    $result = $subscriptionEngine->catchUpActive($criteria, limit: 500);
    if (!$result->successful()) {
        // handle $result->errors
    }
} while ($result->hasPendingEvents);
```

The lock is released between two calls, so other workers can take their turn.

- `hasPendingEvents` means that calling the method again with the same arguments could process more events. It is `false` if all subscriptions of the run failed, even if the event log contains more events
- A subscription that is booted partially stays `BOOTING` until no events are pending
- `limit` counts the events that are read, including events that are skipped by subscriptions that are already ahead. The number of handler invocations can be higher: With 10 subscriptions, `limit: 500` can lead to 5,000 invocations
- A run reads from the lowest position of its subscriptions, so subscriptions that are ahead receive no new events until the one lagging behind has caught up. To prevent that, catch up independently lagging subscriptions separately via `SubscriptionEngineCriteria`

See [docs/decisions.md](./docs/decisions.md) for the reasoning.

## Concurrent workers

`SubscriptionEngine::catchUpNextActive()` and `SubscriptionEngine::bootNext()` allow several workers to process the same set of subscriptions in parallel, without advisory locks or partition assignment.
Each call claims one subscription, the one with the lowest position that is not locked by another worker, and catches it up in its own transaction:

```php
while (true) {
    $result = $subscriptionEngine->catchUpNextActive($criteria, limit: 500);
    if (!$result->successful()) {
        // handle $result->errors
    }
    if (!$result->hasPendingEvents) {
        // nothing to do, or everything is locked by other workers ($result->allSubscriptionsLocked)
        usleep(100_000);
    }
}
```

- `catchUpActive()` and `boot()` keep waiting for locks and remain the choice if the caller relies on its events being processed once the call returns. Both kinds of workers can be mixed
- `hasPendingEvents` means that calling the method again with the same arguments could process more events, for any matching subscription. Keep polling instead of exiting: In rare cases it is `false` while a subscription lags, and a worker holding a lock could get killed
- A booting subscription is activated as soon as it has reached the end of the event log, independent of the others
- Events of one subscription are always handled in order. There is no order *between* subscribers though: One can be ahead of another, so a handler must not rely on the projection of a different subscriber
- N subscriptions take N reads of the event log, instead of one that they share

### Failing runs

If a run fails after the claim, for example because of a deadlock or a repeating SQL error on PostgreSQL, it is rolled back and the method returns a failed result instead of throwing, unlike `catchUpActive()` and `boot()`. The subscription keeps its status.
The engine instance leaves it out of its next claims, so it can't starve the others, and retries it as soon as they have nothing left to do. A temporary error recovers by itself.

> [!WARNING]
> This state is kept per engine instance. A process that makes a single call and exits runs into a failing subscription every time and starves the others, so workers have to loop within the process. Always check `successful()`, a repeating failure is otherwise only visible through the `SubscriberFailedToHandleEvent` and `CatchUpRunFailed` engine events.

### Stop workers before `reset()`

`setup()` and `reset()` don't skip locked subscriptions, but they don't hold locks either. A worker can catch up a subscription while its `reset()` handler is running.

See [docs/decisions.md](./docs/decisions.md) for the reasoning.

## Exceptions during a catch-up

An exception that escapes a catch-up, for example a deadlock, rolls back the current run and is rethrown.
Positions and everything else that is part of the transaction stay at the last committed batch, so the catch-up can be repeated with the same engine instance.

> [!NOTE]
> A database error inside a subscriber is usually not an escaping exception: The subscription goes to `ERROR` and the run continues without it.
> If the error has ended the transaction, which MySQL and MariaDB do for a deadlock victim and PostgreSQL for every SQL error, the store fails on its next write. That exception escapes, so no subscription goes to `ERROR` and the batch is repeated on the next catch-up. An error that keeps occurring on PostgreSQL therefore blocks all subscriptions of the run. Run them separately via `SubscriptionEngineCriteria` to isolate it.

### Stores on MySQL and MariaDB

With the default isolation level (`REPEATABLE READ`), workers that wait for a subscription lock can deadlock with a worker that changes the status of a subscription.
`SubscriptionStore` implementations should use `READ COMMITTED` for their transactions on these databases, as [wwwision/subscription-engine-doctrine](https://packagist.org/packages/wwwision/subscription-engine-doctrine) does.

## Upgrading to 2.0

Implementations of the following interfaces have to be adjusted, otherwise PHP fails with a fatal error.
Implementations with these changes are also compatible with 1.x.

`EventStoreAdapter::read()` has a new parameter:

```php
public function read(Position $startPosition, int|null $limit = null): iterable;
```

Implementations must return at most `$limit` events, and all events if it is `null`.

`SubscriptionStore` has a new method:

```php
public function rollback(): void;
```

It discards all changes since the last `beginTransaction()`. Afterwards the store must be usable for a new transaction.

`SubscriptionStore::add()` and `update()` must throw an exception if the database has ended the transaction on its own, for example after a deadlock. Note that `PDO::inTransaction()` still reports `true` on MySQL and MariaDB until the next successful statement.

`SubscriptionStore` also has a new method for [concurrent workers](#concurrent-workers):

```php
public function claimNext(SubscriptionCriteria $criteria): Subscription|null;
```

It locks and returns the matching subscription with the lowest position that is not locked by another process, with ties broken by id, without waiting for locks. It must not lock any other subscription, and has to return the state at the time of the lock.

> [!WARNING]
> Up to 1.x a deadlock inside a subscriber handler on MySQL or MariaDB could persist positions without the events they stand for, for all subscriptions of that run. This can't be detected afterwards. If such deadlocks occurred, `reset()` and replay the affected subscriptions.

> [!NOTE]
> The signature of this method might still change until 2.0 is released.

## Contribution

Contributions in the form of [issues](https://github.com/bwaidelich/subscription-engine/issues) or [pull requests](https://github.com/bwaidelich/subscription-engine/pulls) are highly appreciated

## License

See [LICENSE](./LICENSE)

## Acknowledgements

This implementation is heavily inspired by parts of the [patchlevel/event-sourcing](https://packagist.org/packages/patchlevel/event-sourcing) package
