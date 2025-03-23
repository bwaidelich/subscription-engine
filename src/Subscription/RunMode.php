<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Subscription;

/**
 * The run mode of a subscription
 */
enum RunMode : string
{
    /**
     * Handle all events
     */
    case FROM_BEGINNING = 'FROM_BEGINNING';

    /**
     * Handle all events
     */
    case FROM_NOW = 'FROM_NOW';

    /**
     * Handle all events only once
     */
    case ONCE = 'ONCE';
}