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

    private bool $transactionActive = false;
    private bool $hasPendingChanges = false;
    private bool $hasCommittedChanges = false;

    public function setup(): void
    {
        $this->setupCalled = true;
    }

    public function findByCriteriaForUpdate(SubscriptionCriteria $criteria): Subscriptions
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
        $this->hasPendingChanges = true;
        $this->subscriptionsById[$subscription->id->value] = $subscription;
    }

    public function beginTransaction(): void
    {
        $this->transactionActive = true;
    }

    public function commit(): void
    {
        $this->transactionActive = false;
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

    public function _wasSetupCalled(): bool
    {
        return $this->setupCalled;
    }

    public function _isTransactionActive(): bool
    {
        return $this->transactionActive;
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