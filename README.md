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

## Upgrading to 2.0

`EventStoreAdapter::read()` has a new parameter that implementations have to add, otherwise PHP fails with a fatal error:

```php
public function read(Position $startPosition, int|null $limit = null): iterable;
```

Implementations must return at most `$limit` events, and all events if it is `null`.
An implementation with the additional parameter is also compatible with 1.x.

> [!NOTE]
> The signature of this method might still change until 2.0 is released.

## Contribution

Contributions in the form of [issues](https://github.com/bwaidelich/subscription-engine/issues) or [pull requests](https://github.com/bwaidelich/subscription-engine/pulls) are highly appreciated

## License

See [LICENSE](./LICENSE)

## Acknowledgements

This implementation is heavily inspired by parts of the [patchlevel/event-sourcing](https://packagist.org/packages/patchlevel/event-sourcing) package
