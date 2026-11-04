<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Tests\Mocks;

use Wwwision\SubscriptionEngine\Store\SubscriptionCriteria;
use Wwwision\SubscriptionEngine\Store\SubscriptionStore;
use Wwwision\SubscriptionEngine\Subscription\Subscription;
use Wwwision\SubscriptionEngine\Subscription\Subscriptions;

final class InMemorySubscriptionStore implements SubscriptionStore
{

    /**
     * @var array<string, Subscription>
     */
    private array $subscriptionsById = [];

    private bool $setupCalled = false;

    /**
     * @var array<string> Subscriptions that are locked by another process
     */
    private array $lockedSubscriptionIds = [];

    /**
     * @var array<array<string, Subscription>>
     */
    private array $transactionSnapshots = [];
    private bool $rollbackCalled = false;
    private bool $failOnRollback = false;
    private bool $failOnCommit = false;
    private bool $failOnUpdate = false;
    private bool $failOnClaim = false;
    private bool $failOnBeginTransaction = false;

    /**
     * @var array<string>
     */
    private array $failOnUpdateOf = [];
    private bool $hasPendingChanges = false;
    private bool $hasCommittedChanges = false;

    public function setup(): void
    {
        $this->setupCalled = true;
    }

    public function findByCriteria(SubscriptionCriteria $criteria): Subscriptions
    {
        return $this->filterByCriteria($criteria);
    }

    public function findByCriteriaForUpdate(SubscriptionCriteria $criteria): Subscriptions
    {
        if ($this->transactionSnapshots === []) {
            throw new \LogicException('A lock outside of a transaction is released right away');
        }
        return $this->filterByCriteria($criteria);
    }

    public function claimNext(SubscriptionCriteria $criteria): Subscription|null
    {
        if ($this->failOnClaim) {
            throw new \RuntimeException('Claim failed');
        }
        $candidates = array_values(array_filter(iterator_to_array($this->filterByCriteria($criteria), false), fn(Subscription $subscription) => !in_array($subscription->id->value, $this->lockedSubscriptionIds, true)));
        usort($candidates, static fn(Subscription $a, Subscription $b) => [$a->position->value, $a->id->value] <=> [$b->position->value, $b->id->value]);
        return $candidates[0] ?? null;
    }

    private function filterByCriteria(SubscriptionCriteria $criteria): Subscriptions
    {
        $filteredSubscriptions = array_filter($this->subscriptionsById, static function (Subscription $subscription) use ($criteria) {
            if ($criteria->ids !== null && !in_array($subscription->id->value, $criteria->ids->toStringArray(), true)) {
                return false;
            }
            if ($criteria->status !== null && !in_array($subscription->status->value, $criteria->status->toStringArray(), true)) {
                return false;
            }
            return true;
        });
        return Subscriptions::fromArray($filteredSubscriptions);
    }

    public function add(Subscription $subscription): void
    {
        $this->hasPendingChanges = true;
        $this->subscriptionsById[$subscription->id->value] = $subscription;
    }

    public function update(Subscription $subscription): void
    {
        if ($this->failOnUpdate) {
            throw new \RuntimeException('Update failed');
        }
        if (in_array($subscription->id->value, $this->failOnUpdateOf, true)) {
            throw new \RuntimeException(sprintf('Update of %s failed', $subscription->id->value));
        }
        $this->hasPendingChanges = true;
        $this->subscriptionsById[$subscription->id->value] = $subscription;
    }

    public function beginTransaction(): void
    {
        if ($this->failOnBeginTransaction) {
            throw new \RuntimeException('Begin failed');
        }
        $this->transactionSnapshots[] = $this->subscriptionsById;
    }

    public function rollback(): void
    {
        $this->rollbackCalled = true;
        if ($this->failOnRollback) {
            throw new \RuntimeException('Rollback failed');
        }
        $this->subscriptionsById = array_pop($this->transactionSnapshots);
        $this->hasPendingChanges = false;
    }

    public function commit(): void
    {
        if ($this->failOnCommit) {
            throw new \RuntimeException('Commit failed');
        }
        array_pop($this->transactionSnapshots);
        if ($this->hasPendingChanges) {
            $this->hasCommittedChanges = true;
            $this->hasPendingChanges = false;
        }
    }

    public function _setSubscriptions(Subscription ...$subscriptions): void
    {
        $this->subscriptionsById = [];
        foreach ($subscriptions as $subscription) {
            $this->subscriptionsById[$subscription->id->value] = $subscription;
        }
    }

    public function _lock(string ...$subscriptionIds): void
    {
        $this->lockedSubscriptionIds = $subscriptionIds;
    }

    public function _wasRollbackCalled(): bool
    {
        return $this->rollbackCalled;
    }

    public function _failOnRollback(): void
    {
        $this->failOnRollback = true;
    }

    public function _failOnUpdate(): void
    {
        $this->failOnUpdate = true;
    }

    public function _failOnUpdateOf(string ...$subscriptionIds): void
    {
        $this->failOnUpdateOf = $subscriptionIds;
    }

    public function _failOnClaim(): void
    {
        $this->failOnClaim = true;
    }

    public function _failOnBeginTransaction(): void
    {
        $this->failOnBeginTransaction = true;
    }

    public function _failOnCommit(): void
    {
        $this->failOnCommit = true;
    }

    public function _wasSetupCalled(): bool
    {
        return $this->setupCalled;
    }

    public function _isTransactionActive(): bool
    {
        return $this->transactionSnapshots !== [];
    }

    public function _hasPendingChanges(): bool
    {
        return $this->hasPendingChanges;
    }

    public function _hasCommittedChanges(): bool
    {
        return $this->hasCommittedChanges;
    }
}