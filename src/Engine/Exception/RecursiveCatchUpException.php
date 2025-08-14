<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Engine\Exception;

use Exception;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionIds;

/**
 * An exception that is thrown if a catchup is started from within a subscription that is currently catching up
 */
final class RecursiveCatchUpException extends Exception
{
    public static function forSubscriptionIds(SubscriptionIds $processingSubscriptions, SubscriptionIds|null $catchingUpSubscriptions): self
    {
        if ($catchingUpSubscriptions === null) {
            $catchingUpSubscriptionsDescription = 'all subscriptions';
        } else {
            $catchingUpSubscriptionsDescription = 'subscription' . ($catchingUpSubscriptions->count() !== 1 ? 's' : '') . ' "' . implode('", "', $catchingUpSubscriptions->toStringArray()) . '"';
        }
        $processingSubscriptionsDescription = 'subscription' . ($processingSubscriptions->count() !== 1 ? 's' : '') . ' "' . implode('", "', $processingSubscriptions->toStringArray()) . '"';
        return new self("Failed to catch up $catchingUpSubscriptionsDescription while catch up of $processingSubscriptionsDescription is still running");
    }
}
