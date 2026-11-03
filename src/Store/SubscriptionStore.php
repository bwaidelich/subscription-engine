<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Store;

use Wwwision\SubscriptionEngine\Subscription\Subscription;
use Wwwision\SubscriptionEngine\Subscription\Subscriptions;

/**
 * Contracts for a "Subscription repository"
 * It allows to persist and update the state of subscriptions and allows to lock access such that multiple processes cannot change the same subscription at the same time
 */
interface SubscriptionStore
{
    public function setup(): void;

    public function findByCriteria(SubscriptionCriteria $criteria): Subscriptions;

    public function findByCriteriaForUpdate(SubscriptionCriteria $criteria): Subscriptions;

    /**
     * Locks the next subscription that is matching the criteria and not locked by another process, without waiting for locks.
     * This is the subscription with the lowest position at the time of the read, and the lowest id among those with the same position.
     * The returned subscription must reflect the state at the time of the lock, because the event handling continues from its position.
     * The lock is held until {@see self::commit()} or {@see self::rollback()}
     *
     * Implementations must not lock more than the returned subscription, so that other processes can claim the remaining ones
     *
     * @return Subscription|null The claimed subscription, or NULL if there is none that matches the criteria and is available
     */
    public function claimNext(SubscriptionCriteria $criteria): Subscription|null;

    /**
     * @throws \Throwable if the database has ended the transaction, see {@see self::update()}
     */
    public function add(Subscription $subscription): void;

    /**
     * Implementations must throw an exception if the database has ended the transaction on its own, for example because of a deadlock in a subscriber handler.
     * Otherwise, the change would be persisted right away, and so could be a position without the events it stands for.
     *
     * @throws \Throwable if the database has ended the transaction
     */
    public function update(Subscription $subscription): void;

    public function beginTransaction(): void;

    public function commit(): void;

    /**
     * Discard all changes since the last {@see self::beginTransaction()}
     * The subscription engine calls this if an exception escapes a catch-up, afterwards the store must be usable for a new transaction
     */
    public function rollback(): void;
}
