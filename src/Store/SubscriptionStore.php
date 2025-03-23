<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Store;

use Wwwision\SubscriptionEngine\Subscription\Subscription;
use Wwwision\SubscriptionEngine\Subscription\Subscriptions;

interface SubscriptionStore
{
    public function setup(): void;

    public function findByCriteriaForUpdate(SubscriptionCriteria $criteria): Subscriptions;

    public function add(Subscription $subscription): void;

    public function update(Subscription $subscription): void;

    public function beginTransaction(): void;

    public function commit(): void;
}
