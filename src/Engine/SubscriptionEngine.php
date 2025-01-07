<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Engine;

use Closure;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use Wwwision\SubscriptionEngine\EventStore\EventStore;
use Wwwision\SubscriptionEngine\Store\SubscriptionCriteria;
use Wwwision\SubscriptionEngine\Store\SubscriptionStore;
use Wwwision\SubscriptionEngine\Subscriber\ProvidesReset;
use Wwwision\SubscriptionEngine\Subscriber\ProvidesSetup;
use Wwwision\SubscriptionEngine\Subscriber\Subscribers;
use Wwwision\SubscriptionEngine\Subscription\Position;
use Wwwision\SubscriptionEngine\Subscription\Subscription;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionError;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionStatus;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionStatusFilter;

/**
 * This is the internal core for the catchup
 *
 * All functionality is low level and well encapsulated and abstracted by the {@see ContentRepositoryMaintainer}
 * It presents the only API way to interact with catchup and offers more maintenance tasks.
 *
 * This implementation is heavily inspired and adjusted from the event-sourcing package of "patchlevel":
 * {@link https://github.com/patchlevel/event-sourcing/}
 *
 * @internal implementation detail of the catchup. See {@see ContentRepository::handle()} and {@see ContentRepositoryMaintainer}
 */
final class SubscriptionEngine
{
    private bool $processing = false;

    public function __construct(
        private readonly EventStore $eventStore,
        private readonly SubscriptionStore $subscriptionStore,
        private readonly Subscribers $subscribers,
        private readonly LoggerInterface|null $logger = null,
    ) {
    }

    public function setup(SubscriptionEngineCriteria|null $criteria = null): Result
    {
        $criteria ??= SubscriptionEngineCriteria::noConstraints();

        $this->logger?->info('Subscription Engine: Start to setup.');

        $this->subscriptionStore->setup();
        $this->discoverNewSubscriptions();
        $subscriptions = $this->subscriptionStore->findByCriteriaForUpdate(SubscriptionCriteria::forEngineCriteriaAndStatus($criteria, SubscriptionStatusFilter::fromArray([
            SubscriptionStatus::NEW,
            SubscriptionStatus::BOOTING,
            SubscriptionStatus::ACTIVE
        ])));
        if ($subscriptions->isEmpty()) {
            // should not happen as this means the contentGraph is unavailable, see status information.
            $this->logger?->info('Subscription Engine: No subscriptions found.');
            return Result::success();
        }
        $errors = [];
        foreach ($subscriptions as $subscription) {
            $error = $this->setupSubscription($subscription);
            if ($error !== null) {
                $errors[] = $error;
            }
        }
        return $errors === [] ? Result::success() : Result::failed(Errors::fromArray($errors));
    }

    public function boot(SubscriptionEngineCriteria|null $criteria = null): ProcessedResult
    {
        $criteria ??= SubscriptionEngineCriteria::noConstraints();
        return $this->processExclusively(
            fn () => $this->catchUpSubscriptions($criteria, SubscriptionStatusFilter::fromArray([SubscriptionStatus::BOOTING]))
        );
    }

    public function catchUpActive(SubscriptionEngineCriteria|null $criteria = null): ProcessedResult
    {
        $criteria ??= SubscriptionEngineCriteria::noConstraints();
        return $this->processExclusively(
            fn () => $this->catchUpSubscriptions($criteria, SubscriptionStatusFilter::fromArray([SubscriptionStatus::ACTIVE]))
        );
    }

    public function reset(SubscriptionEngineCriteria|null $criteria = null): Result
    {
        $criteria ??= SubscriptionEngineCriteria::noConstraints();

        $this->logger?->info('Subscription Engine: Start to reset.');
        $subscriptions = $this->subscriptionStore->findByCriteriaForUpdate(SubscriptionCriteria::forEngineCriteriaAndStatus($criteria, SubscriptionStatusFilter::any()));
        if ($subscriptions->isEmpty()) {
            $this->logger?->info('Subscription Engine: No subscriptions to reset.');
            return Result::success();
        }
        $errors = [];
        foreach ($subscriptions as $subscription) {
            if ($subscription->status === SubscriptionStatus::NEW
                || !$this->subscribers->contain($subscription->id)
            ) {
                // Todo mark projections as detached like setup or catchup?
                continue;
            }
            $error = $this->resetSubscription($subscription);
            if ($error !== null) {
                $errors[] = $error;
            }
        }
        return $errors === [] ? Result::success() : Result::failed(Errors::fromArray($errors));
    }

    // ------------------------------

    /**
     * Find all subscribers that don't have a corresponding subscription.
     * For each match a subscription is added
     *
     * Note: newly discovered subscriptions are not ACTIVE by default, instead they have to be initialized via {@see self::setup()} explicitly
     */
    private function discoverNewSubscriptions(): void
    {
        $subscriptions = $this->subscriptionStore->findByCriteriaForUpdate(SubscriptionCriteria::noConstraints());
        foreach ($this->subscribers as $subscriber) {
            if ($subscriptions->contain($subscriber->id)) {
                continue;
            }
            $subscription = new Subscription(
                $subscriber->id,
                SubscriptionStatus::NEW,
                Position::none(),
                null,
                null
            );
            $this->subscriptionStore->add($subscription);
            $this->logger?->info(sprintf('Subscription Engine: New Subscriber "%s" was found and added to the subscription store.', $subscriber->id->value));
        }
    }

    /**
     * Set up the subscription by retrieving the corresponding subscriber and calling the setUp method on its handler
     * If the setup fails, the subscription will be in the {@see SubscriptionStatus::ERROR} state and a corresponding {@see Error} is returned
     */
    private function setupSubscription(Subscription $subscription): ?Error
    {
        if (!$this->subscribers->contain($subscription->id)) {
            // mark detached subscriptions as we cannot set up
            $this->subscriptionStore->update($subscription->with(status: SubscriptionStatus::DETACHED));
            $this->logger?->info(sprintf('Subscription Engine: Subscriber for "%s" not found and has been marked as detached.', $subscription->id->value));
            return null;
        }

        $subscriber = $this->subscribers->get($subscription->id);
        if ($subscriber->handler instanceof ProvidesSetup) {
            try {
                $subscriber->handler->setup();
            } catch (Throwable $e) {
                $this->logger?->error(sprintf('Subscription Engine: Subscriber "%s" for "%s" has an error in the setup method: %s', $subscriber::class, $subscription->id->value, $e->getMessage()));
                $this->subscriptionStore->update($subscription->withError(SubscriptionError::fromPreviousStatusAndException($subscription->status, $e)));
                return Error::create($subscription->id, $e->getMessage(), $e, null);
            }
        }

        if ($subscription->status === SubscriptionStatus::ACTIVE) {
            $this->logger?->debug(sprintf('Subscription Engine: Active subscriber "%s" for "%s" has been re-setup.', $subscriber::class, $subscription->id->value));
            return null;
        }
        $this->subscriptionStore->update($subscription->with(status: SubscriptionStatus::BOOTING));
        $this->logger?->debug(sprintf('Subscription Engine: Subscriber "%s" for "%s" has been setup, set to %s from previous %s.', $subscriber::class, $subscription->id->value, SubscriptionStatus::BOOTING->value, $subscription->status->name));
        return null;
    }

    private function resetSubscription(Subscription $subscription): ?Error
    {
        $subscriber = $this->subscribers->get($subscription->id);
        if (!$subscriber->handler instanceof ProvidesReset) {
            throw new RuntimeException(sprintf('Subscriber "%s" for "%s" does not implement the %s interface.', $subscriber::class, $subscription->id->value, ProvidesReset::class), 1736161538);
        }
        try {
            $subscriber->handler->reset();
        } catch (Throwable $e) {
            $this->logger?->error(sprintf('Subscription Engine: Subscriber "%s" for "%s" has an error in the resetState method: %s', $subscriber::class, $subscription->id->value, $e->getMessage()));
            return Error::create($subscription->id, $e->getMessage(), $e, null);
        }
        $this->subscriptionStore->update($subscription->with(status: SubscriptionStatus::BOOTING, position: Position::none())->withoutError());
        $this->logger?->debug(sprintf('Subscription Engine: For Subscriber "%s" for "%s" the resetState method has been executed.', $subscriber::class, $subscription->id->value));
        return null;
    }

    private function catchUpSubscriptions(SubscriptionEngineCriteria $criteria, SubscriptionStatusFilter $status): ProcessedResult
    {
        $this->logger?->info(sprintf('Subscription Engine: Start catching up subscriptions in states %s.', implode(',', $status->toStringArray())));

        $subscriptionCriteria = SubscriptionCriteria::forEngineCriteriaAndStatus($criteria, $status);

        $numberOfProcessedEvents = 0;
        /** @var array<Error> $errors */
        $errors = [];

        $this->subscriptionStore->beginTransaction();

        $subscriptionsToCatchup = $this->subscriptionStore->findByCriteriaForUpdate($subscriptionCriteria);
        foreach ($subscriptionsToCatchup as $subscription) {
            if (!$this->subscribers->contain($subscription->id)) {
                // mark detached subscriptions as we cannot handle them and exclude them from catchup
                $this->subscriptionStore->update($subscription->with(status: SubscriptionStatus::DETACHED));
                $this->logger?->info(sprintf('Subscription Engine: Subscriber for "%s" not found and has been marked as detached.', $subscription->id->value));
                $subscriptionsToCatchup = $subscriptionsToCatchup->without($subscription->id);
            }
        }

        if ($subscriptionsToCatchup->isEmpty()) {
            $this->logger?->info('Subscription Engine: No subscriptions matched criteria. Finishing catch up.');
            $this->subscriptionStore->commit();
            return ProcessedResult::success(0);
        }

        $startPosition = $subscriptionsToCatchup->lowestPosition()?->next() ?? Position::none();
        $this->logger?->debug(sprintf('Subscription Engine: Event stream is processed from position %s.', $startPosition->value));

        /** @var array<string,Position> $highestPositionForSubscriber */
        $highestPositionForSubscriber = [];

        foreach ($this->eventStore->read($startPosition) as $event) {
            $eventPosition = $event->position();
            if ($numberOfProcessedEvents > 0) {
                $this->logger?->debug(sprintf('Subscription Engine: Current event stream position: %d', $eventPosition->value));
            }
//                if ($progressCallback !== null) {
//                    $progressCallback($eventEnvelope);
//                }
            foreach ($subscriptionsToCatchup as $subscription) {
                if (!$eventPosition->isAheadOf($subscription->position)) {
                    $this->logger?->debug(sprintf('Subscription Engine: Subscription "%s" is farther than the current even position (%d >= %d), continue catch up.', $subscription->id->value, $subscription->position->value, $eventPosition->value));
                    continue;
                }
                $subscriber = $this->subscribers->get($subscription->id);

                try {
                    ($subscriber->handler)($event);
                } catch (Throwable $e) {
                    // ERROR Case:
                    $errors[] = Error::create($subscription->id, $e->getMessage(), $errors === [] ? $e : null, $eventPosition);
                    $this->logger?->error(sprintf('Subscription Engine: Subscriber "%s" for "%s" could not process the event at position: %d: %s', $subscriber::class, $subscription->id->value, $eventPosition->value, $e->getMessage()));

                    // for the leftover events we are not including this failed subscription for catchup
                    $subscriptionsToCatchup = $subscriptionsToCatchup->without($subscription->id);
                    // update the subscription error state on either its unchanged or new position (if some events worked)
                    // note that the possibly partially applied event will not be rolled back.
                    $this->subscriptionStore->update($subscription->withError(SubscriptionError::fromPreviousStatusAndException($subscription->status, $e))->with(position: $highestPositionForSubscriber[$subscription->id->value] ?? $subscription->position));
                    continue;
                }
                // HAPPY Case:
                $this->logger?->debug(sprintf('Subscription Engine: Subscriber "%s" for "%s" processed the event at position %d.', substr(strrchr($subscriber::class, '\\') ?: '', 1), $subscription->id->value, $eventPosition->value));
                $highestPositionForSubscriber[$subscription->id->value] = $eventPosition;
            }
            $numberOfProcessedEvents++;
        }
        foreach ($subscriptionsToCatchup as $subscription) {
            // after catchup mark all subscriptions as active, so they are triggered automatically now.
            // The position will be set to the one the subscriber handled last, or if no events were in the stream, and we booted we keep the persisted position
            $this->subscriptionStore->update($subscription->with(status: SubscriptionStatus::ACTIVE, position: $highestPositionForSubscriber[$subscription->id->value] ?? $subscription->position));
            if ($subscription->status !== SubscriptionStatus::ACTIVE) {
                $this->logger?->info(sprintf('Subscription Engine: Subscription "%s" has been set to active after booting', $subscription->id->value));
            }
        }
        $this->logger?->info(sprintf('Subscription Engine: Finish catch up. %d processed events %d errors.', $numberOfProcessedEvents, count($errors)));

        $this->subscriptionStore->commit();
        return $errors === [] ? ProcessedResult::success($numberOfProcessedEvents) : ProcessedResult::failed($numberOfProcessedEvents, Errors::fromArray($errors));
    }

    /**
     * @template T
     * @param Closure(): T $closure
     * @return T
     */
    private function processExclusively(Closure $closure): mixed
    {
        if ($this->processing) {
            throw new RuntimeException('Subscription engine is already processing', 1736162059);
        }
        $this->processing = true;
        try {
            return $closure();
        } finally {
            $this->processing = false;
        }
    }
}
