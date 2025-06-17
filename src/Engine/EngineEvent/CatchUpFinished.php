<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Engine\EngineEvent;

use Wwwision\SubscriptionEngine\Engine\Errors;
use Wwwision\SubscriptionEngine\Subscription\Subscriptions;

/**
 * @implements EngineEvent<void>
 */
final readonly class CatchUpFinished implements EngineEvent
{
    public function __construct(
        public Subscriptions $subscriptions,
        public int $numberOfProcessedEvents,
        public Errors|null $errors,
    ) {
    }

    public function logLevel(): int
    {
        return LOG_DEBUG;
    }

    public function __toString(): string
    {
        $subscriptionsCount = $this->subscriptions->count();
        if ($this->errors !== null) {
            $errorCount = $this->errors->count();
            return sprintf('Finished catch-up of %d subscription%s, processed %d event%s (%d error%s)', $subscriptionsCount, $subscriptionsCount > 1 ? 's' : '', $this->numberOfProcessedEvents, $this->numberOfProcessedEvents > 1 ? 's' : '', $errorCount, $errorCount > 1 ? 's' : '');
        }
        return sprintf('Finished catch-up of %d subscription%s, processed %d event%s (no errors)', $subscriptionsCount, $subscriptionsCount > 1 ? 's' : '', $this->numberOfProcessedEvents, $this->numberOfProcessedEvents > 1 ? 's' : '');
    }
}
