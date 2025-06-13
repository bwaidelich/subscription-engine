<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine;

use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\ActiveSubscriptionSetup;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\CatchUpFinished;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\CatchUpInitiated;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\CatchUpStarted;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\EngineEvent;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\FailedToSetupSubscriber;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\NoSubscriptionsFound;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\SetupStarted;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\SubscriberFailedToHandleEvent;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\SubscriberHandledEvent;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\SubscriberNotFound;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\SubscriberSkippedEvent;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\SubscriptionDetached;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\SubscriptionDiscovered;
use Wwwision\SubscriptionEngine\Engine\Error;
use Wwwision\SubscriptionEngine\Engine\Errors;
use Wwwision\SubscriptionEngine\Engine\ProcessedResult;
use Wwwision\SubscriptionEngine\Engine\Result;
use Wwwision\SubscriptionEngine\Engine\SubscriptionEngineCriteria;
use Wwwision\SubscriptionEngine\EventStore\EventStoreAdapter;
use Wwwision\SubscriptionEngine\Store\SubscriptionCriteria;
use Wwwision\SubscriptionEngine\Store\SubscriptionStore;
use Wwwision\SubscriptionEngine\Subscriber\Subscribers;
use Wwwision\SubscriptionEngine\Subscription\Position;
use Wwwision\SubscriptionEngine\Subscription\RunMode;
use Wwwision\SubscriptionEngine\Subscription\Subscription;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionError;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionStatus;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionStatusFilter;

/**
 * @template E of object
 */
final class SubscriptionEngine
{
    /**
     * @var array<callable(EngineEvent<E|void>): void>
     */
    private array $engineEventSubscribers = [];

    /**
     * @param EventStoreAdapter<E> $eventStoreAdapter
     */
    public function __construct(
        private readonly EventStoreAdapter $eventStoreAdapter,
        private readonly SubscriptionStore $subscriptionStore,
        private readonly Subscribers $subscribers,
        private readonly LoggerInterface|null $logger = null,
    ) {
    }

    /**
     * @param callable(EngineEvent<E|void>): void $callback
     */
    public function onEngineEvent(callable $callback): void
    {
        $this->engineEventSubscribers[] = $callback;
    }

    public function setup(SubscriptionEngineCriteria|null $criteria = null): Result
    {
        $criteria ??= SubscriptionEngineCriteria::noConstraints();

        $this->dispatchEngineEvent(new SetupStarted());

        $this->subscriptionStore->setup();
        $this->discoverNewSubscriptions();
        $subscriptionCriteria = SubscriptionCriteria::forEngineCriteriaAndStatus($criteria, SubscriptionStatusFilter::fromArray([
            SubscriptionStatus::NEW,
            SubscriptionStatus::BOOTING,
            SubscriptionStatus::ACTIVE
        ]));
        $subscriptions = $this->subscriptionStore->findByCriteriaForUpdate($subscriptionCriteria);
        if ($subscriptions->isEmpty()) {
            $this->dispatchEngineEvent(new NoSubscriptionsFound($subscriptionCriteria));
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
        return $this->catchUpSubscriptions($criteria, SubscriptionStatusFilter::fromStatus(SubscriptionStatus::BOOTING));
    }

    public function catchUpActive(SubscriptionEngineCriteria|null $criteria = null): ProcessedResult
    {
        $criteria ??= SubscriptionEngineCriteria::noConstraints();
        return $this->catchUpSubscriptions($criteria, SubscriptionStatusFilter::fromStatus(SubscriptionStatus::ACTIVE));
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
            if ($subscription->status === SubscriptionStatus::NEW || !$this->subscribers->contain($subscription->id)) {
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
            $subscription = Subscription::create(
                $subscriber->id,
                $subscriber->runMode,
                SubscriptionStatus::NEW,
            );

            $this->subscriptionStore->add($subscription);
            $this->dispatchEngineEvent(new SubscriptionDiscovered($subscription));
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
            $this->dispatchEngineEvent(new SubscriberNotFound($subscription));
            $subscription = $subscription->with(status: SubscriptionStatus::DETACHED);
            $this->subscriptionStore->update($subscription);
            $this->dispatchEngineEvent(new SubscriptionDetached($subscription));
            return null;
        }

        $subscriber = $this->subscribers->get($subscription->id);
        if ($subscriber->setup !== null) {
            try {
                ($subscriber->setup)();
            } catch (Throwable $e) {
                $this->dispatchEngineEvent(new FailedToSetupSubscriber($subscription, $e));
                $this->subscriptionStore->update($subscription->withError(SubscriptionError::fromPreviousStatusAndException($subscription->status, $e)));
                return Error::create($subscription->id, $e->getMessage(), $e, null);
            }
        }

        if ($subscription->status === SubscriptionStatus::ACTIVE) {
            $this->dispatchEngineEvent(new ActiveSubscriptionSetup($subscription));
            return null;
        }
        if ($subscription->runMode === RunMode::FROM_NOW) {
            $this->subscriptionStore->update(
                $subscription->with(
                    status: SubscriptionStatus::ACTIVE,
                    position: $this->eventStoreAdapter->lastPosition(),
                )
            );
            return null;
        }
        $this->subscriptionStore->update($subscription->with(status: SubscriptionStatus::BOOTING));
        $this->logger?->debug(sprintf('Subscription Engine: Subscriber for "%s" has been setup, set to %s from previous %s.', $subscription->id->value, SubscriptionStatus::BOOTING->value, $subscription->status->name));
        return null;
    }

    private function resetSubscription(Subscription $subscription): ?Error
    {
        $subscriber = $this->subscribers->get($subscription->id);
        if ($subscriber->reset === null) {
            throw new RuntimeException(sprintf('No reset function provided for subscriber for "%s".', $subscription->id->value), 1736161538);
        }
        try {
            ($subscriber->reset)();
        } catch (Throwable $e) {
            $this->logger?->error(sprintf('Subscription Engine: Subscriber for "%s" has an error in the resetState method: %s', $subscription->id->value, $e->getMessage()));
            return Error::create($subscription->id, $e->getMessage(), $e, null);
        }
        $this->subscriptionStore->update($subscription->with(status: SubscriptionStatus::BOOTING, position: Position::none())->withoutError());
        $this->logger?->debug(sprintf('Subscription Engine: For Subscriber for "%s" the resetState method has been executed.', $subscription->id->value));
        return null;
    }

    private function catchUpSubscriptions(SubscriptionEngineCriteria $criteria, SubscriptionStatusFilter $status): ProcessedResult
    {
        $this->dispatchEngineEvent(new CatchUpInitiated($criteria, $status));

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
                $this->dispatchEngineEvent(new SubscriptionDetached($subscription));
                $subscriptionsToCatchup = $subscriptionsToCatchup->without($subscription->id);
            }
        }

        if ($subscriptionsToCatchup->isEmpty()) {
            $this->dispatchEngineEvent(new NoSubscriptionsFound($subscriptionCriteria));
            $this->subscriptionStore->commit();
            return ProcessedResult::success(0);
        }

        $startPosition = $subscriptionsToCatchup->lowestPosition()?->next() ?? Position::none();
        $this->dispatchEngineEvent(new CatchUpStarted($subscriptionsToCatchup, $startPosition));

        /** @var array<string,Position> $highestPositionForSubscriber */
        $highestPositionForSubscriber = [];

        foreach ($this->eventStoreAdapter->read($startPosition) as $event) {
            $eventPosition = $this->eventStoreAdapter->eventPosition($event);
            foreach ($subscriptionsToCatchup as $subscription) {
                $subscriber = $this->subscribers->get($subscription->id);
                if (!$eventPosition->isAheadOf($subscription->position)) {
                    $this->dispatchEngineEvent(new SubscriberSkippedEvent($subscription, $event, $eventPosition));
                    continue;
                }
                try {
                    ($subscriber->handler)($event);
                } catch (Throwable $e) {
                    // ERROR Case:
                    $errors[] = Error::create($subscription->id, $e->getMessage(), $errors === [] ? $e : null, $eventPosition);
                    $this->dispatchEngineEvent(new SubscriberFailedToHandleEvent($subscription, $event, $eventPosition, $e));

                    // for the leftover events we are not including this failed subscription for catchup
                    $subscriptionsToCatchup = $subscriptionsToCatchup->without($subscription->id);
                    // update the subscription error state on either its unchanged or new position (if some events worked)
                    // note that the possibly partially applied event will not be rolled back.
                    $this->subscriptionStore->update($subscription->withError(SubscriptionError::fromPreviousStatusAndException($subscription->status, $e))->with(position: $highestPositionForSubscriber[$subscription->id->value] ?? $subscription->position));
                    continue;
                }
                // HAPPY Case:
                $this->dispatchEngineEvent(new SubscriberHandledEvent($subscription, $event, $eventPosition));
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
        $this->subscriptionStore->commit();
        $errorsVo = $errors !== [] ? Errors::fromArray($errors) : null;
        $this->dispatchEngineEvent(new CatchUpFinished($subscriptionsToCatchup, $numberOfProcessedEvents, $errorsVo));
        return $errorsVo === null ? ProcessedResult::success($numberOfProcessedEvents) : ProcessedResult::failed($numberOfProcessedEvents, $errorsVo);
    }

    /**
     * @param EngineEvent<E|void> $event
     */
    private function dispatchEngineEvent(EngineEvent $event): void
    {
        foreach ($this->engineEventSubscribers as $subscriber) {
            ($subscriber)($event);
        }
    }
}
