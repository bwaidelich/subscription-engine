<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Subscription;

/**
 * The run mode of a subscription
 */
enum RunMode : string
{
    /**
     * Handle all events (default for projections)
     */
    case FROM_BEGINNING = 'FROM_BEGINNING';

    /**
     * Handle all events (default for event handlers that must not be triggered for events from the past)
     */
    case FROM_NOW = 'FROM_NOW';

    /**
     * Handle all events only once (rarely useful for one-time processes, like migrations)
     */
    case ONCE = 'ONCE';
}
