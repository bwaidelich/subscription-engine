<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine;

use InvalidArgumentException;
use Throwable;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\ActiveSubscriptionSetup;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\AllSubscriptionsLocked;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\CatchUpFinished;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\CatchUpInitiated;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\CatchUpRunFailed;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\CatchUpStarted;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\EngineEvent;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\FailedToResetSubscriber;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\FailedToSetupSubscriber;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\NoResetResetFunctionDefined;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\NoSubscriptionsFound;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\ResetAlreadyInProgress;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\ResetStarted;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\SetupStarted;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\SubscriberFailedToHandleEvent;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\SubscriberHandledEvent;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\SubscriberNotFound;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\SubscriberSkippedEvent;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\SubscriberWasReset;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\SubscriberWasSetup;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\SubscriptionActivated;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\SubscriptionDetached;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\SubscriptionDiscovered;
use Wwwision\SubscriptionEngine\Engine\Error;
use Wwwision\SubscriptionEngine\Engine\Errors;
use Wwwision\SubscriptionEngine\Engine\Exception\RecursiveCatchUpException;
use Wwwision\SubscriptionEngine\Engine\Exception\UnknownSubscriptionIdsException;
use Wwwision\SubscriptionEngine\Engine\ProcessedResult;
use Wwwision\SubscriptionEngine\Engine\Result;
use Wwwision\SubscriptionEngine\Engine\SubscriptionEngineCriteria;
use Wwwision\SubscriptionEngine\EventStore\EventStoreAdapter;
use Wwwision\SubscriptionEngine\EventStore\ReadOptions;
use Wwwision\SubscriptionEngine\Store\SubscriptionCriteria;
use Wwwision\SubscriptionEngine\Store\SubscriptionStore;
use Wwwision\SubscriptionEngine\Subscriber\Subscribers;
use Wwwision\SubscriptionEngine\Subscription\Position;
use Wwwision\SubscriptionEngine\Subscription\RunMode;
use Wwwision\SubscriptionEngine\Subscription\Subscription;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionError;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionId;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionIds;
use Wwwision\SubscriptionEngine\Subscription\Subscriptions;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionStatus;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionStatusFilter;

/**
 * This implementation is heavily inspired and adjusted from the event-sourcing package of "patchlevel": {@link https://github.com/patchlevel/event-sourcing/}
 *
 * @template E of object
 * @template F of object = object
 */
final class SubscriptionEngine
{
    /**
     * @var array<callable(EngineEvent<E|void>): void>
     */
    private array $engineEventSubscribers = [];

    private SubscriptionIds $processingSubscriptions;

    /**
     * @param EventStoreAdapter<E, F> $eventStoreAdapter
     */
    public function __construct(
        private readonly EventStoreAdapter $eventStoreAdapter,
        private readonly SubscriptionStore $subscriptionStore,
        private readonly Subscribers $subscribers,
    ) {
        $this->processingSubscriptions = SubscriptionIds::none();
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
        $this->assertKnownSubscriptionIds($criteria);
        $subscriptionCriteria = SubscriptionCriteria::forEngineCriteriaAndStatus($criteria, self::statusesToSetUp());
        $this->assertNoRecursiveCall($subscriptionCriteria);
        $this->dispatchEngineEvent(new SetupStarted());

        $this->subscriptionStore->setup();
        $this->discoverNewSubscriptions();
        $subscriptions = $this->subscriptionStore->findByCriteria($subscriptionCriteria);
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

    public function boot(SubscriptionEngineCriteria|null $criteria = null, int|null $limit = null): ProcessedResult
    {
        $criteria ??= SubscriptionEngineCriteria::noConstraints();
        return $this->catchUpSubscriptions($criteria, SubscriptionStatusFilter::fromStatus(SubscriptionStatus::BOOTING), $limit);
    }

    public function catchUpActive(SubscriptionEngineCriteria|null $criteria = null, int|null $limit = null): ProcessedResult
    {
        $criteria ??= SubscriptionEngineCriteria::noConstraints();
        return $this->catchUpSubscriptions($criteria, SubscriptionStatusFilter::fromStatus(SubscriptionStatus::ACTIVE), $limit);
    }

    /**
     * Catches up a single active subscription: The one with the lowest position that is not locked by another process
     * Unlike {@see self::catchUpActive()} this does not wait for locks, so that several workers can process the same set of subscriptions in parallel
     */
    public function catchUpNextActive(SubscriptionEngineCriteria|null $criteria = null, int|null $limit = null): ProcessedResult
    {
        return $this->catchUpNext($criteria ?? SubscriptionEngineCriteria::noConstraints(), SubscriptionStatus::ACTIVE, $limit);
    }

    /**
     * Boots a single subscription: The one with the lowest position that is not locked by another process
     * Unlike {@see self::boot()} this does not wait for locks, and the subscription is activated as soon as it has reached the end of the event log
     */
    public function bootNext(SubscriptionEngineCriteria|null $criteria = null, int|null $limit = null): ProcessedResult
    {
        return $this->catchUpNext($criteria ?? SubscriptionEngineCriteria::noConstraints(), SubscriptionStatus::BOOTING, $limit);
    }

    /**
     * Waits for running catch-ups and keeps new ones out with the status {@see SubscriptionStatus::RESETTING}, while the reset functions run without a lock
     *
     * @param bool $force Whether to take over subscriptions that are already {@see SubscriptionStatus::RESETTING}. Only use this if the process that started the reset is gone
     */
    public function reset(SubscriptionEngineCriteria|null $criteria = null, bool $force = false): Result
    {
        $criteria ??= SubscriptionEngineCriteria::noConstraints();
        $this->assertKnownSubscriptionIds($criteria);
        $subscriptionCriteria = SubscriptionCriteria::forEngineCriteriaAndStatus($criteria, SubscriptionStatusFilter::any());
        $this->assertNoRecursiveCall($subscriptionCriteria);
        $this->dispatchEngineEvent(new ResetStarted());
        $subscriptions = $this->fenceSubscriptions($subscriptionCriteria, $force);
        if ($subscriptions->isEmpty()) {
            $this->dispatchEngineEvent(new NoSubscriptionsFound($subscriptionCriteria));
            return Result::success();
        }
        $errors = [];
        foreach ($subscriptions as $subscription) {
            $error = $this->resetSubscription($subscription, $force);
            if ($error !== null) {
                $errors[] = $error;
            }
        }
        return $errors === [] ? Result::success() : Result::failed(Errors::fromArray($errors));
    }

    public function getSubscriptions(SubscriptionEngineCriteria|null $criteria = null): Subscriptions
    {
        $subscriptionCriteria = SubscriptionCriteria::forEngineCriteriaAndStatus($criteria ??= SubscriptionEngineCriteria::noConstraints(), SubscriptionStatusFilter::any());
        return $this->subscriptionStore->findByCriteria($subscriptionCriteria);
    }

    public function getSubscription(SubscriptionId $subscriptionId): Subscription|null
    {
        $subscriptionCriteria = SubscriptionCriteria::forEngineCriteriaAndStatus(SubscriptionEngineCriteria::create([$subscriptionId]), SubscriptionStatusFilter::any());
        $subscriptions = $this->subscriptionStore->findByCriteria($subscriptionCriteria);
        if (!$subscriptions->contain($subscriptionId)) {
            return null;
        }
        return $subscriptions->get($subscriptionId);
    }

    // ------------------------------

    /**
     * @throws UnknownSubscriptionIdsException
     */
    private function assertKnownSubscriptionIds(SubscriptionEngineCriteria $criteria): void
    {
        if ($criteria->ids === null) {
            return;
        }
        $unknownSubscriptionIds = [];
        foreach ($criteria->ids as $subscriptionId) {
            if (!$this->subscribers->contain($subscriptionId)) {
                $unknownSubscriptionIds[] = $subscriptionId;
            }
        }
        if ($unknownSubscriptionIds !== []) {
            throw UnknownSubscriptionIdsException::forSubscriptionIds(SubscriptionIds::fromArray($unknownSubscriptionIds));
        }
    }

    /**
     * Find all subscribers that don't have a corresponding subscription.
     * For each match a subscription is added
     *
     * Note: newly discovered subscriptions are not ACTIVE by default, instead they have to be initialized via {@see self::setup()} explicitly
     */
    private function discoverNewSubscriptions(): void
    {
        $subscriptions = $this->subscriptionStore->findByCriteria(SubscriptionCriteria::noConstraints());
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
     *
     * The handler runs without a lock, so that it can use DDL. A worker can change the subscription in the meantime, so the transition is decided by its fresh state
     */
    private function setupSubscription(Subscription $subscription): ?Error
    {
        if (!$this->subscribers->contain($subscription->id)) {
            // mark detached subscriptions as we cannot set up
            $this->dispatchEngineEvent(new SubscriberNotFound($subscription));
            $detached = $this->transactional(function () use ($subscription) {
                $freshSubscription = $this->findSubscriptionToSetUpForUpdate($subscription->id);
                if ($freshSubscription === null) {
                    return null;
                }
                $detached = $freshSubscription->with(status: SubscriptionStatus::DETACHED);
                $this->subscriptionStore->update($detached);
                return $detached;
            });
            if ($detached !== null) {
                $this->dispatchEngineEvent(new SubscriptionDetached($detached));
            }
            return null;
        }

        $subscriber = $this->subscribers->get($subscription->id);
        if ($subscriber->setup !== null) {
            try {
                ($subscriber->setup)();
            } catch (Throwable $e) {
                $this->dispatchEngineEvent(new FailedToSetupSubscriber($subscription, $e));
                $this->transactional(function () use ($subscription, $e) {
                    $freshSubscription = $this->findSubscriptionToSetUpForUpdate($subscription->id);
                    if ($freshSubscription !== null) {
                        $this->subscriptionStore->update($freshSubscription->withError(SubscriptionError::fromPreviousStatusAndException($freshSubscription->status, $e)));
                    }
                });
                return Error::create($subscription->id, $e->getMessage(), $e, null);
            }
        }

        $freshSubscription = $this->transactional(function () use ($subscription) {
            $freshSubscription = $this->findSubscriptionToSetUpForUpdate($subscription->id);
            if ($freshSubscription === null || $freshSubscription->status === SubscriptionStatus::ACTIVE) {
                return $freshSubscription;
            }
            if ($freshSubscription->runMode === RunMode::FROM_NOW) {
                $this->subscriptionStore->update($freshSubscription->with(status: SubscriptionStatus::ACTIVE, position: $this->eventStoreAdapter->lastPosition()));
            } elseif ($freshSubscription->status === SubscriptionStatus::NEW) {
                $this->subscriptionStore->update($freshSubscription->with(status: SubscriptionStatus::BOOTING));
            }
            return $freshSubscription;
        });
        if ($freshSubscription === null) {
            return null;
        }
        if ($freshSubscription->status === SubscriptionStatus::ACTIVE) {
            $this->dispatchEngineEvent(new ActiveSubscriptionSetup($freshSubscription));
            return null;
        }
        $this->dispatchEngineEvent(new SubscriberWasSetup($freshSubscription, previousStatus: $freshSubscription->status, newStatus: $freshSubscription->runMode === RunMode::FROM_NOW ? SubscriptionStatus::ACTIVE : SubscriptionStatus::BOOTING));
        return null;
    }

    private static function statusesToSetUp(): SubscriptionStatusFilter
    {
        return SubscriptionStatusFilter::fromArray([
            SubscriptionStatus::NEW,
            SubscriptionStatus::BOOTING,
            SubscriptionStatus::ACTIVE,
        ]);
    }

    /**
     * @return Subscription|null The current state of the subscription, or NULL if it must not be set up anymore, for example because a reset has started in the meantime
     */
    private function findSubscriptionToSetUpForUpdate(SubscriptionId $subscriptionId): Subscription|null
    {
        $subscriptions = $this->subscriptionStore->findByCriteriaForUpdate(SubscriptionCriteria::create(SubscriptionIds::fromArray([$subscriptionId]), self::statusesToSetUp()));
        return $subscriptions->contain($subscriptionId) ? $subscriptions->get($subscriptionId) : null;
    }

    /**
     * Waits for the locks of all matching subscriptions and sets the status that no catch-up matches, in one transaction: Once this returns, none of them is being processed anymore
     *
     * @return Subscriptions The state of the subscriptions before this call
     */
    private function fenceSubscriptions(SubscriptionCriteria $subscriptionCriteria, bool $force): Subscriptions
    {
        return $this->transactional(function () use ($subscriptionCriteria, $force) {
            $subscriptions = $this->subscriptionStore->findByCriteriaForUpdate($subscriptionCriteria);
            foreach ($subscriptions as $subscription) {
                if (!$this->subscribers->contain($subscription->id) || (!$force && $subscription->status === SubscriptionStatus::RESETTING)) {
                    continue;
                }
                $this->subscriptionStore->update($subscription->with(status: SubscriptionStatus::RESETTING));
            }
            return $subscriptions;
        });
    }

    /**
     * @param Subscription $subscription The state before {@see self::fenceSubscriptions()}
     */
    private function resetSubscription(Subscription $subscription, bool $force): ?Error
    {
        if (!$this->subscribers->contain($subscription->id)) {
            $this->dispatchEngineEvent(new SubscriberNotFound($subscription));
            return null;
        }
        if (!$force && $subscription->status === SubscriptionStatus::RESETTING) {
            $event = new ResetAlreadyInProgress($subscription);
            $this->dispatchEngineEvent($event);
            return Error::create($subscription->id, (string)$event, null, null);
        }
        $subscriber = $this->subscribers->get($subscription->id);
        if ($subscriber->reset === null) {
            $this->dispatchEngineEvent(new NoResetResetFunctionDefined($subscription));
        } else {
            try {
                ($subscriber->reset)();
            } catch (Throwable $e) {
                $this->dispatchEngineEvent(new FailedToResetSubscriber($subscription, $e));
                // the projection may be partially cleared, so no catch-up must continue on it
                $this->subscriptionStore->update($subscription->withError(SubscriptionError::fromPreviousStatusAndException($subscription->status, $e)));
                return Error::create($subscription->id, $e->getMessage(), $e, null);
            }
        }
        $this->subscriptionStore->update($subscription->with(status: SubscriptionStatus::BOOTING, position: Position::none())->withoutError());
        $this->dispatchEngineEvent(new SubscriberWasReset($subscription, $subscription->status, SubscriptionStatus::BOOTING));
        return null;
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function transactional(callable $callback): mixed
    {
        $this->subscriptionStore->beginTransaction();
        try {
            $result = $callback();
            $this->subscriptionStore->commit();
            return $result;
        } catch (Throwable $e) {
            $this->rollbackTransaction();
            throw $e;
        }
    }

    private function catchUpSubscriptions(SubscriptionEngineCriteria $criteria, SubscriptionStatusFilter $status, int|null $limit): ProcessedResult
    {
        $subscriptionCriteria = $this->prepareCatchUp($criteria, $status, $limit);
        $this->subscriptionStore->beginTransaction();
        $processingSubscriptionsBackup = $this->processingSubscriptions;
        $committed = false;
        try {
            $subscriptionsToCatchup = $this->subscriptionStore->findByCriteriaForUpdate($subscriptionCriteria);
            $this->processingSubscriptions = $this->processingSubscriptions->merge($subscriptionsToCatchup->getIds());
            foreach ($subscriptionsToCatchup as $subscription) {
                if (!$this->subscribers->contain($subscription->id)) {
                    // mark detached subscriptions as we cannot handle them and exclude them from catchup
                    $this->subscriptionStore->update($subscription->with(status: SubscriptionStatus::DETACHED));
                    $this->dispatchEngineEvent(new SubscriptionDetached($subscription));
                    $subscriptionsToCatchup = $subscriptionsToCatchup->without($subscription->id);
                }
            }
            $initialSubscriptionsToCatchup = $subscriptionsToCatchup;

            if ($subscriptionsToCatchup->isEmpty()) {
                $this->dispatchEngineEvent(new NoSubscriptionsFound($subscriptionCriteria));
                $this->subscriptionStore->commit();
                $committed = true;
                return ProcessedResult::success(0);
            }

            [$numberOfProcessedEvents, $errors, $hasPendingEvents] = $this->handleEvents($subscriptionsToCatchup, $limit);
            $this->subscriptionStore->commit();
            $committed = true;
            $errorsVo = $errors !== [] ? Errors::fromArray($errors) : null;
            $this->dispatchEngineEvent(new CatchUpFinished($initialSubscriptionsToCatchup, $numberOfProcessedEvents, $errorsVo, $hasPendingEvents));
            return $errorsVo === null ? ProcessedResult::success($numberOfProcessedEvents, $hasPendingEvents) : ProcessedResult::failed($numberOfProcessedEvents, $errorsVo, $hasPendingEvents);
        } catch (Throwable $e) {
            if (!$committed) {
                $this->rollbackTransaction();
            }
            throw $e;
        } finally {
            $this->processingSubscriptions = $processingSubscriptionsBackup;
        }
    }

    /**
     * Claims the next available subscription and catches it up in its own transaction.
     *
     * A run that fails after the claim rolls back and returns a failed result with the id of the subscription instead of throwing.
     * The engine keeps no memory of it: With lowest position first, the same subscription is claimed again by the next call, unless the caller leaves it out
     */
    private function catchUpNext(SubscriptionEngineCriteria $criteria, SubscriptionStatus $status, int|null $limit): ProcessedResult
    {
        $subscriptionCriteria = $this->prepareCatchUp($criteria, SubscriptionStatusFilter::fromStatus($status), $limit);
        $this->subscriptionStore->beginTransaction();
        $processingSubscriptionsBackup = $this->processingSubscriptions;
        $claimed = null;
        $committed = false;
        try {
            $claimed = $this->claimNextWithSubscriber($subscriptionCriteria);
            if ($claimed === null) {
                $this->subscriptionStore->commit();
                $committed = true;
                if ($this->subscriptionStore->findByCriteria($subscriptionCriteria)->isEmpty()) {
                    $this->dispatchEngineEvent(new NoSubscriptionsFound($subscriptionCriteria));
                    return ProcessedResult::success(0);
                }
                $this->dispatchEngineEvent(new AllSubscriptionsLocked($subscriptionCriteria));
                return ProcessedResult::locked();
            }
            $this->processingSubscriptions = $this->processingSubscriptions->merge(SubscriptionIds::fromArray([$claimed->id]));
            $claimedSubscriptions = Subscriptions::fromArray([$claimed]);
            [$numberOfProcessedEvents, $errors, , $hadNothingToRead] = $this->handleEvents($claimedSubscriptions, $limit);
            $this->subscriptionStore->commit();
            $committed = true;
            if ($status === SubscriptionStatus::ACTIVE && $hadNothingToRead) {
                // the claimed subscription had the lowest position, so all others that are not locked are at the end of the event log, too
                $hasPendingEvents = false;
            } else {
                $hasPendingEvents = $this->hasPendingSubscriptions($subscriptionCriteria, $status);
            }
            $errorsVo = $errors !== [] ? Errors::fromArray($errors) : null;
            $this->dispatchEngineEvent(new CatchUpFinished($claimedSubscriptions, $numberOfProcessedEvents, $errorsVo, $hasPendingEvents));
            return $errorsVo === null ? ProcessedResult::success($numberOfProcessedEvents, $hasPendingEvents) : ProcessedResult::failed($numberOfProcessedEvents, $errorsVo, $hasPendingEvents);
        } catch (Throwable $e) {
            if ($committed) {
                throw $e;
            }
            $this->rollbackTransaction();
            if ($claimed === null) {
                throw $e;
            }
            $this->dispatchEngineEvent(new CatchUpRunFailed($claimed, $e));
            return ProcessedResult::failed(0, Errors::fromArray([Error::create($claimed->id, $e->getMessage(), $e, null)]), $this->hasPendingSubscriptions($subscriptionCriteria, $status));
        } finally {
            $this->processingSubscriptions = $processingSubscriptionsBackup;
        }
    }

    /**
     * Whether a next run could process more: A booting subscription has at least to be activated, an active one has to be behind the event log
     * A subscription whose run failed counts as well, as it is still behind
     */
    private function hasPendingSubscriptions(SubscriptionCriteria $subscriptionCriteria, SubscriptionStatus $status): bool
    {
        $subscriptions = $this->subscriptionStore->findByCriteria($subscriptionCriteria);
        if ($status === SubscriptionStatus::BOOTING) {
            return !$subscriptions->isEmpty();
        }
        $lastPosition = $this->eventStoreAdapter->lastPosition();
        foreach ($subscriptions as $subscription) {
            if ($lastPosition->isAheadOf($subscription->position)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Subscriptions without a subscriber are marked detached, a removed subscriber often has a low position and must not look like an idle run
     */
    private function claimNextWithSubscriber(SubscriptionCriteria $subscriptionCriteria): Subscription|null
    {
        while (($subscription = $this->subscriptionStore->claimNext($subscriptionCriteria)) !== null) {
            if ($this->subscribers->contain($subscription->id)) {
                return $subscription;
            }
            $this->subscriptionStore->update($subscription->with(status: SubscriptionStatus::DETACHED));
            $this->dispatchEngineEvent(new SubscriptionDetached($subscription));
        }
        return null;
    }

    private function prepareCatchUp(SubscriptionEngineCriteria $criteria, SubscriptionStatusFilter $status, int|null $limit): SubscriptionCriteria
    {
        if ($limit !== null && $limit < 1) {
            throw new InvalidArgumentException(sprintf('limit has to be a positive integer, got: %d', $limit), 1793750400);
        }
        $this->assertKnownSubscriptionIds($criteria);
        $this->dispatchEngineEvent(new CatchUpInitiated($criteria, $status));

        $subscriptionCriteria = SubscriptionCriteria::forEngineCriteriaAndStatus($criteria, $status);
        $this->assertNoRecursiveCall($subscriptionCriteria);
        return $subscriptionCriteria;
    }

    /**
     * On the same connection, a lock of the running catch-up looks free. Its commit would also overwrite whatever a nested call has changed
     *
     * @throws RecursiveCatchUpException
     */
    private function assertNoRecursiveCall(SubscriptionCriteria $subscriptionCriteria): void
    {
        if ($this->processingSubscriptions->isEmpty()) {
            return;
        }
        if ($subscriptionCriteria->ids === null || !$subscriptionCriteria->ids->intersection($this->processingSubscriptions)->isEmpty()) {
            throw RecursiveCatchUpException::forSubscriptionIds($this->processingSubscriptions, $subscriptionCriteria->ids);
        }
    }

    /**
     * Passes the events to the given subscriptions and persists their new state, without ending the transaction
     *
     * A single subscription with a filter passes it to the adapter. As the end of such a read can't be seen from the returned events, it is bounded by a snapshot of the last position.
     * Several subscriptions share one unfiltered read, and the engine applies their filters. Events that a filter excludes advance the position like handled ones
     *
     * @return array{int, array<Error>, bool, bool} number of processed events, errors, whether the limit prevented reading more events for the remaining subscriptions and whether there was nothing to read at all
     */
    private function handleEvents(Subscriptions $subscriptionsToCatchup, int|null $limit): array
    {
        $numberOfProcessedEvents = 0;
        /** @var array<Error> $errors */
        $errors = [];
        $startPosition = $subscriptionsToCatchup->lowestPosition()?->next() ?? Position::none();
        $this->dispatchEngineEvent(new CatchUpStarted($subscriptionsToCatchup, $startPosition));

        /** @var array<string,Position> $highestPositionForSubscriber */
        $highestPositionForSubscriber = [];

        $hasPendingEvents = false;
        $readLimit = $limit !== null ? $limit + 1 : null;
        $pushedDownFilter = null;
        if ($subscriptionsToCatchup->count() === 1) {
            foreach ($subscriptionsToCatchup as $subscription) {
                $pushedDownFilter = $this->subscribers->get($subscription->id)->filter;
            }
        }
        $upTo = null;
        if ($pushedDownFilter !== null) {
            $upTo = $this->eventStoreAdapter->lastPosition();
            $events = $startPosition->isAheadOf($upTo) ? [] : $this->eventStoreAdapter->read($startPosition, ReadOptions::create(limit: $readLimit, upTo: $upTo, filter: $pushedDownFilter));
        } else {
            $events = $this->eventStoreAdapter->read($startPosition, ReadOptions::create(limit: $readLimit));
        }
        foreach ($events as $event) {
            if ($numberOfProcessedEvents === $limit) {
                $hasPendingEvents = true;
                break;
            }
            $eventPosition = $this->eventStoreAdapter->eventPosition($event);
            foreach ($subscriptionsToCatchup as $subscription) {
                $subscriber = $this->subscribers->get($subscription->id);
                if (!$eventPosition->isAheadOf($subscription->position)) {
                    $this->dispatchEngineEvent(new SubscriberSkippedEvent($subscription, $event, $eventPosition));
                    continue;
                }
                if ($pushedDownFilter === null && $subscriber->filter !== null) {
                    /** @var F $filter */
                    $filter = $subscriber->filter;
                    if (!$this->eventStoreAdapter->matches($filter, $event)) {
                        $highestPositionForSubscriber[$subscription->id->value] = $eventPosition;
                        continue;
                    }
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
        // without any remaining subscription, calling the catch-up again could not process more events
        $hasPendingEvents = $hasPendingEvents && !$subscriptionsToCatchup->isEmpty();
        foreach ($subscriptionsToCatchup as $subscription) {
            // The position will be set to the one the subscriber handled last, or if no events were in the stream, and we booted we keep the persisted position
            $position = $highestPositionForSubscriber[$subscription->id->value] ?? $subscription->position;
            if (!$hasPendingEvents && $upTo !== null && $upTo->isAheadOf($position)) {
                // a filtered read that reached its end has seen every event up to the snapshot, even if none of them matched
                $position = $upTo;
            }
            if ($hasPendingEvents) {
                // a partial catch-up keeps the status: Booting subscriptions must not be triggered automatically until they reached the end of the event log
                $this->subscriptionStore->update($subscription->with(position: $position));
                continue;
            }
            // after catchup mark all subscriptions as active, so they are triggered automatically now.
            $this->subscriptionStore->update($subscription->with(status: SubscriptionStatus::ACTIVE, position: $position));
            if ($subscription->status !== SubscriptionStatus::ACTIVE) {
                $this->dispatchEngineEvent(new SubscriptionActivated($subscription, $subscription->status));
            }
        }
        $hadNothingToRead = $upTo !== null ? $startPosition->isAheadOf($upTo) : $numberOfProcessedEvents === 0;
        return [$numberOfProcessedEvents, $errors, $hasPendingEvents, $hadNothingToRead];
    }

    /**
     * A failed rollback must not hide the exception that caused it, usually the connection is gone in that case and the transaction with it
     */
    private function rollbackTransaction(): void
    {
        try {
            $this->subscriptionStore->rollback();
        } catch (Throwable) {
        }
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
