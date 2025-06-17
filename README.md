# Subscription engine for Event Stores

Subscription engine for event-sourced systems

## Usage

This package contains base types and interfaces for Subscription Engines.
In order to use it, implementations for the following interfaces have to be provided:

### `SubscriptionStore`

The [SubscriptionStore](./src/Store/SubscriptionStore.php) allows to persist and update the state of subscriptions and allows to lock access such that multiple processes cannot change the same subscription at the same time.

#### Implementations

- [wwwision/subscription-engine-doctrine](https://packagist.org/packages/wwwision/subscription-engine-doctrine) provides a DBAL implementation that is compatible with MySQL/MariaDB, PostgreSQL and SQLite.

### `EventStoreAdapter`

The [EventStoreAdapter](./src/EventStore/EventStoreAdapter.php) is the adapter for the actual event store implementation

#### Implementations

- [wwwision/subscription-engine-neos-adapter](https://packagist.org/packages/wwwision/subscription-engine-neos-adapter) - adapter for the [neos/eventstore](https://packagist.org/packages/neos/eventstore)
- [wwwision/subscription-engine-dcb-adapter](https://packagist.org/packages/wwwision/subscription-engine-dcb-adapter) - adapter for the [wwwision/dcb-eventstore](https://packagist.org/packages/wwwision/dcb-eventstore)

## Contribution

Contributions in the form of [issues](https://github.com/bwaidelich/subscription-engine/issues) or [pull requests](https://github.com/bwaidelich/subscription-engine/pulls) are highly appreciated

## License

See [LICENSE](./LICENSE)

## Acknowledgements

This implementation is heavily inspired by parts of the [patchlevel/event-sourcing](https://packagist.org/packages/patchlevel/event-sourcing) package
