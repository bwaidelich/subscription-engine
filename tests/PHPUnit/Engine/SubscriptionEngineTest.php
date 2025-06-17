<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Tests\PHPUnit\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\EngineEvent;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\NoSubscriptionsFound;
use Wwwision\SubscriptionEngine\Engine\SubscriptionEngineCriteria;
use Wwwision\SubscriptionEngine\Store\SubscriptionCriteria;
use Wwwision\SubscriptionEngine\Subscriber\Subscriber;
use Wwwision\SubscriptionEngine\Subscriber\Subscribers;
use Wwwision\SubscriptionEngine\Subscription\Position;
use Wwwision\SubscriptionEngine\Subscription\RunMode;
use Wwwision\SubscriptionEngine\Subscription\Subscription;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionId;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionStatus;
use Wwwision\SubscriptionEngine\SubscriptionEngine;
use Wwwision\SubscriptionEngine\Tests\Mocks\InMemoryEventStoreAdapter;
use Wwwision\SubscriptionEngine\Tests\Mocks\InMemorySubscriptionStore;
use Wwwision\SubscriptionEngine\Tests\Mocks\MockEvent;

#[CoversClass(SubscriptionEngine::class)]
final class SubscriptionEngineTest extends TestCase
{
    private InMemoryEventStoreAdapter $eventStore;

    private InMemorySubscriptionStore $subscriptionStore;

    private Subscribers $subscribers;

    /**
     * @var array<EngineEvent>
     */
    private array $emittedEngineEvents = [];

    protected function setUp(): void
    {
        $this->eventStore = new InMemoryEventStoreAdapter();
        $this->subscriptionStore = new InMemorySubscriptionStore();
    }

    public function test_setup_invokes_subscriptionStore_setup(): void
    {
        $this->subscriptionEngine()->setup();

        self::assertTrue($this->subscriptionStore->_wasSetupCalled());

        $this->assertEmittedEngineEvents(
            'SetupStarted: Start to setup',
            'NoSubscriptionsFound: No subscriptions found for criteria: {"ids":null,"status":["NEW","BOOTING","ACTIVE"]}',
        );
    }

    public function test_setup_detaches_subscriptions_without_subscriber(): void
    {
        $this->subscriptionStore->_setSubscriptions(Subscription::create(id: 's1', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::ACTIVE));
        $result = $this->subscriptionEngine()->setup();

        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'DETACHED', 'position' => 0, 'error' => null],
        );

        $this->assertEmittedEngineEvents(
            'SetupStarted: Start to setup',
            'SubscriberNotFound: Subscriber for "s1" not found',
            'SubscriptionDetached: Subscription "s1" was marked detached',
        );
        self::assertTrue($result->successful());
    }

    public function test_setup_reports_failed_subscriber_setups(): void
    {
        $this->subscriptionStore->_setSubscriptions(Subscription::create(id: 's1', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::ACTIVE));
        $handler = new class {
            public function __invoke(MockEvent $event): void
            { /* no-op */
            }

            public function setup(): void
            {
                throw new \Error('Just testing');
            }
        };
        $subscriber = Subscriber::create(SubscriptionId::fromString('s1'), $handler, RunMode::FROM_NOW, $handler->setup(...));
        $result = $this->subscriptionEngine($subscriber)->setup();

        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'ERROR', 'position' => 0, 'error' => 'Just testing'],
        );

        $this->assertEmittedEngineEvents(
            'SetupStarted: Start to setup',
            'FailedToSetupSubscriber: Subscriber for "s1" has an error in the setup method: Just testing'
        );
        self::assertFalse($result->successful());
    }

    public function test_setup_discovers_new_subscriptions(): void
    {
        $this->eventStore->append('event at #1');
        $this->eventStore->append('event at #2');
        $subscriber = Subscriber::create(SubscriptionId::fromString('s1'), fn() => null);
        $result = $this->subscriptionEngine($subscriber)->setup();

        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'BOOTING', 'position' => 0, 'error' => null],
        );

        $this->assertEmittedEngineEvents(
            'SetupStarted: Start to setup',
            'SubscriptionDiscovered: New Subscriber "s1" was found and added to the subscription store',
            'SubscriberWasSetup: Subscriber for "s1" has been setup, changing status from BOOTING to NEW',
        );
        self::assertTrue($result->successful());
    }

    public function test_setup_marks_new_from_now_subscriptions_active(): void
    {
        $this->eventStore->append('event at #1');
        $this->eventStore->append('event at #2');
        $this->eventStore->append('event at #3');
        $subscriber = Subscriber::create(SubscriptionId::fromString('s1'), fn() => null, RunMode::FROM_NOW);
        $result = $this->subscriptionEngine($subscriber)->setup();

        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'ACTIVE', 'position' => 3, 'error' => null],
        );

        $this->assertEmittedEngineEvents(
            'SetupStarted: Start to setup',
            'SubscriptionDiscovered: New Subscriber "s1" was found and added to the subscription store',
            'SubscriberWasSetup: Subscriber for "s1" has been setup, changing status from ACTIVE to NEW',
        );
        self::assertTrue($result->successful());
    }

    public function test_setup_does_not_change_subscriptionstate_of_active_subscriptions(): void
    {
        $this->subscriptionStore->_setSubscriptions(Subscription::create(id: 's1', runMode: RunMode::FROM_NOW, status: SubscriptionStatus::ACTIVE)->with(position: Position::fromInteger(1)));
        $this->eventStore->append('event at #1');
        $this->eventStore->append('event at #2');
        $this->eventStore->append('event at #3');
        $handler = new class {

            public bool $setupWasCalled = false;

            public function __invoke(MockEvent $event): void
            { /* no-op */
            }

            public function setup(): void
            {
                $this->setupWasCalled = true;
            }
        };
        $subscriber = Subscriber::create(SubscriptionId::fromString('s1'), $handler, RunMode::FROM_NOW, $handler->setup(...));
        $result = $this->subscriptionEngine($subscriber)->setup();

        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'ACTIVE', 'position' => 1, 'error' => null],
        );

        $this->assertEmittedEngineEvents(
            'SetupStarted: Start to setup',
            'ActiveSubscriptionSetup: Active subscriber for "s1" has been re-setup',
        );
        self::assertTrue($result->successful());
        self::assertTrue($handler->setupWasCalled);
    }

    public function test_boot_detaches_subscriptions_without_subscriber(): void
    {
        $this->subscriptionStore->_setSubscriptions(
            Subscription::create(id: 's1', runMode: RunMode::FROM_NOW, status: SubscriptionStatus::BOOTING)->with(position: Position::fromInteger(1)),
            Subscription::create(id: 's2', runMode: RunMode::FROM_NOW, status: SubscriptionStatus::BOOTING)->with(position: Position::fromInteger(1))
        );
        $this->eventStore->append('event at #1');
        $this->eventStore->append('event at #2');
        $this->eventStore->append('event at #3');
        $subscriber = Subscriber::create(SubscriptionId::fromString('s1'), fn() => null, RunMode::FROM_NOW);

        $result = $this->subscriptionEngine($subscriber)->boot();

        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'ACTIVE', 'position' => 3, 'error' => null],
            ['id' => 's2', 'status' => 'DETACHED', 'position' => 1, 'error' => null],
        );

        $this->assertEmittedEngineEvents(
            'CatchUpInitiated: Initiated catch-up of subscriptions in states BOOTING',
            'SubscriptionDetached: Subscription "s2" was marked detached',
            'CatchUpStarted: Starting catch-up of 1 subscription from position 2',
            'SubscriberHandledEvent: Subscriber for "s1" processed event "' . MockEvent::class . '" (position: 2)',
            'SubscriberHandledEvent: Subscriber for "s1" processed event "' . MockEvent::class . '" (position: 3)',
            'SubscriptionActivated: Subscription "s1" has been set to ACTIVE from status BOOTING',
            'CatchUpFinished: Finished catch-up of 1 subscription, processed 2 events (no errors)',
        );
        self::assertTrue($result->successful());
    }

    public function test_boot_commits_transaction_if_no_subscription_matches(): void
    {
        $subscriptionEngine = $this->subscriptionEngine();
        $subscriptionEngine->onEngineEvent(function (EngineEvent $event): void {
            if ($event instanceof NoSubscriptionsFound) {
                self::assertTrue($this->subscriptionStore->_isTransactionActive());
            }
        });
        $result = $subscriptionEngine->boot();
        self::assertFalse($this->subscriptionStore->_isTransactionActive());

        $this->assertEmittedEngineEvents(
            'CatchUpInitiated: Initiated catch-up of subscriptions in states BOOTING',
            'NoSubscriptionsFound: No subscriptions found for criteria: {"ids":null,"status":["BOOTING"]}',
        );
        self::assertTrue($result->successful());
    }

    public function test_catchUpActive_does_not_update_subscriptions_if_none_match(): void
    {
        $this->subscriptionStore->_setSubscriptions(Subscription::create(id: 's1', runMode: RunMode::FROM_NOW, status: SubscriptionStatus::BOOTING));
        $subscriptionEngine = $this->subscriptionEngine();
        $result = $subscriptionEngine->catchUpActive();

        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'BOOTING', 'position' => 0, 'error' => null],
        );
        $this->assertEmittedEngineEvents(
            'CatchUpInitiated: Initiated catch-up of subscriptions in states ACTIVE',
            'NoSubscriptionsFound: No subscriptions found for criteria: {"ids":null,"status":["ACTIVE"]}',
        );
        self::assertTrue($result->successful());
        self::assertFalse($this->subscriptionStore->_hasPendingChanges(), 'Subscription store has pending changes');
        self::assertFalse($this->subscriptionStore->_hasCommittedChanges(), 'Subscription store has commited changes');
    }

    public function test_catchUpActive_invokes_handlers_of_active_subscriptions(): void
    {
        $handler1Invocations = 0;
        $subscriber1 = Subscriber::create(SubscriptionId::fromString('s1'), function () use (&$handler1Invocations) {
            $handler1Invocations++;
        });
        $handler2Invocations = 0;
        $subscriber2 = Subscriber::create(SubscriptionId::fromString('s2'), function () use (&$handler2Invocations) {
            $handler2Invocations++;
        });
        $handler3Invocations = 0;
        $subscriber3 = Subscriber::create(SubscriptionId::fromString('s3'), function () use (&$handler3Invocations) {
            $handler3Invocations++;
        });

        $this->subscriptionStore->_setSubscriptions(
            Subscription::create(id: 's1', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::ACTIVE),
            Subscription::create(id: 's2', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::ACTIVE)->with(position: Position::fromInteger(1)),
            Subscription::create(id: 's3', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::BOOTING),
        );

        $subscriptionEngine = $this->subscriptionEngine($subscriber1, $subscriber2, $subscriber3);

        $this->eventStore->append('event at #1');
        $this->eventStore->append('event at #2');

        $result = $subscriptionEngine->catchUpActive();

        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'ACTIVE', 'position' => 2, 'error' => null],
            ['id' => 's2', 'status' => 'ACTIVE', 'position' => 2, 'error' => null],
            ['id' => 's3', 'status' => 'BOOTING', 'position' => 0, 'error' => null],
        );
        $this->assertEmittedEngineEvents(
            'CatchUpInitiated: Initiated catch-up of subscriptions in states ACTIVE',
            'CatchUpStarted: Starting catch-up of 2 subscriptions from position 1',
            'SubscriberHandledEvent: Subscriber for "s1" processed event "' . MockEvent::class . '" (position: 1)',
            'SubscriberSkippedEvent: Subscriber for "s2" skipped event "' . MockEvent::class . '" (position: 1) because it is already father ahead (position: 1)',
            'SubscriberHandledEvent: Subscriber for "s1" processed event "' . MockEvent::class . '" (position: 2)',
            'SubscriberHandledEvent: Subscriber for "s2" processed event "' . MockEvent::class . '" (position: 2)',
            'CatchUpFinished: Finished catch-up of 2 subscriptions, processed 2 events (no errors)',
        );
        self::assertTrue($result->successful());
        self::assertSame(2, $handler1Invocations);
        self::assertSame(1, $handler2Invocations);
        self::assertSame(0, $handler3Invocations);
    }

    public function test_catchUpActive_with_failed_subscribers(): void
    {
        $handler1Invocations = 0;
        $subscriber1 = Subscriber::create(SubscriptionId::fromString('s1'), function () use (&$handler1Invocations) {
            $handler1Invocations++;
            if ($handler1Invocations > 1) {
                throw new \RuntimeException('Exception from s1');
            }
        });
        $handler2Invocations = 0;
        $subscriber2 = Subscriber::create(SubscriptionId::fromString('s2'), function () use (&$handler2Invocations) {
            $handler2Invocations++;
        });
        $handler3Invocations = 0;
        $subscriber3 = Subscriber::create(SubscriptionId::fromString('s3'), function () use (&$handler3Invocations) {
            $handler3Invocations++;
        });

        $this->subscriptionStore->_setSubscriptions(
            Subscription::create(id: 's1', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::ACTIVE),
            Subscription::create(id: 's2', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::ACTIVE)->with(position: Position::fromInteger(1)),
            Subscription::create(id: 's3', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::BOOTING),
        );

        $subscriptionEngine = $this->subscriptionEngine($subscriber1, $subscriber2, $subscriber3);

        $this->eventStore->append('event at #1');
        $this->eventStore->append('event at #2');

        $result = $subscriptionEngine->catchUpActive();

        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'ERROR', 'position' => 1, 'error' => 'Exception from s1'],
            ['id' => 's2', 'status' => 'ACTIVE', 'position' => 2, 'error' => null],
            ['id' => 's3', 'status' => 'BOOTING', 'position' => 0, 'error' => null],
        );
        $this->assertEmittedEngineEvents(
            'CatchUpInitiated: Initiated catch-up of subscriptions in states ACTIVE',
            'CatchUpStarted: Starting catch-up of 2 subscriptions from position 1',
            'SubscriberHandledEvent: Subscriber for "s1" processed event "' . MockEvent::class . '" (position: 1)',
            'SubscriberSkippedEvent: Subscriber for "s2" skipped event "' . MockEvent::class . '" (position: 1) because it is already father ahead (position: 1)',
            'SubscriberFailedToHandleEvent: Subscriber for "s1" failed to process event "' . MockEvent::class . '" (position: 2): Exception from s1',
            'SubscriberHandledEvent: Subscriber for "s2" processed event "' . MockEvent::class . '" (position: 2)',
            'CatchUpFinished: Finished catch-up of 2 subscriptions, processed 2 events (1 error)',
        );
        self::assertSame(2, $result->numberOfProcessedEvents);
        self::assertSame('Event 2 in "s1": Exception from s1', $result->errors->getClampedMessage());
    }

    public function test_reset_does_not_update_subscriptions_if_none_match(): void
    {
        $this->subscriptionStore->_setSubscriptions(
            Subscription::create(id: 's1', runMode: RunMode::FROM_NOW, status: SubscriptionStatus::ACTIVE)->with(position: Position::fromInteger(123)),
            Subscription::create(id: 's2', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::ACTIVE)->with(position: Position::fromInteger(321)),
        );
        $subscriptionEngine = $this->subscriptionEngine(
            Subscriber::create(id: 's1', handler: fn () => null, reset: fn () => null),
            Subscriber::create(id: 's2', handler: fn () => null),
        );
        $result = $subscriptionEngine->reset(SubscriptionEngineCriteria::create(ids: ['s3']));

        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'ACTIVE', 'position' => 123, 'error' => null],
            ['id' => 's2', 'status' => 'ACTIVE', 'position' => 321, 'error' => null],
        );
        $this->assertEmittedEngineEvents(
            'ResetStarted: Start to setup',
            'NoSubscriptionsFound: No subscriptions found for criteria: {"ids":{},"status":["NEW","BOOTING","ACTIVE","DETACHED","ERROR"]}'
        );
        self::assertTrue($result->successful());
        self::assertFalse($this->subscriptionStore->_hasPendingChanges(), 'Subscription store has pending changes');
        self::assertFalse($this->subscriptionStore->_hasCommittedChanges(), 'Subscription store has commited changes');
    }

    public function test_reset_invokes_reset_function_if_specified(): void
    {
        $this->subscriptionStore->_setSubscriptions(
            Subscription::create(id: 's1', runMode: RunMode::FROM_NOW, status: SubscriptionStatus::ACTIVE)->with(position: Position::fromInteger(123)),
        );
        $resetFunctionCalled = false;
        $subscriptionEngine = $this->subscriptionEngine(
            Subscriber::create(id: 's1', handler: fn () => null, reset: function () use (&$resetFunctionCalled) {
                $resetFunctionCalled = true;
            }),
        );
        $result = $subscriptionEngine->reset();

        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'BOOTING', 'position' => 0, 'error' => null],
        );
        $this->assertEmittedEngineEvents(
            'ResetStarted: Start to setup',
            'SubscriberWasReset: Subscriber for "s1" has been reset, changing status from ACTIVE to BOOTING'
        );
        self::assertTrue($result->successful());
        self::assertTrue($resetFunctionCalled, 'reset function was not called');
    }

    public function test_reset_resets_subscription_even_if_no_reset_function_is_specified(): void
    {
        $this->subscriptionStore->_setSubscriptions(
            Subscription::create(id: 's1', runMode: RunMode::FROM_NOW, status: SubscriptionStatus::ACTIVE)->with(position: Position::fromInteger(123)),
        );
        $subscriptionEngine = $this->subscriptionEngine(
            Subscriber::create(id: 's1', handler: fn () => null),
        );
        $result = $subscriptionEngine->reset();

        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'BOOTING', 'position' => 0, 'error' => null],
        );
        $this->assertEmittedEngineEvents(
            'ResetStarted: Start to setup',
            'NoResetResetFunctionDefined: No reset function defined for subscriber for "s1"',
            'SubscriberWasReset: Subscriber for "s1" has been reset, changing status from ACTIVE to BOOTING'
        );
        self::assertTrue($result->successful());
    }

    public function test_reset_skips_subscriptions_without_subscriber(): void
    {
        $this->subscriptionStore->_setSubscriptions(
            Subscription::create(id: 's1', runMode: RunMode::FROM_NOW, status: SubscriptionStatus::ACTIVE)->with(position: Position::fromInteger(123)),
        );
        $subscriptionEngine = $this->subscriptionEngine();
        $result = $subscriptionEngine->reset();

        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'ACTIVE', 'position' => 123, 'error' => null],
        );
        $this->assertEmittedEngineEvents(
            'ResetStarted: Start to setup',
            'SubscriberNotFound: Subscriber for "s1" not found',
        );
        self::assertTrue($result->successful());
    }

    public function test_reset_handles_errors_in_reset_function(): void
    {
        $this->subscriptionStore->_setSubscriptions(
            Subscription::create(id: 's1', runMode: RunMode::FROM_NOW, status: SubscriptionStatus::ACTIVE)->with(position: Position::fromInteger(123)),
        );
        $subscriptionEngine = $this->subscriptionEngine(
            Subscriber::create(id: 's1', handler: fn () => null, reset: fn () => throw new \RuntimeException('Exception from s1 reset function')),
        );
        $result = $subscriptionEngine->reset();

        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'ACTIVE', 'position' => 123, 'error' => null],
        );
        $this->assertEmittedEngineEvents(
            'ResetStarted: Start to setup',
            'FailedToResetSubscriber: Subscriber for "s1" has an error in the reset method: Exception from s1 reset function',
        );
        self::assertSame('"s1": Exception from s1 reset function', $result->errors->getClampedMessage());
    }

    // ---------------------


    private function subscriptionEngine(Subscriber ...$subscribers): SubscriptionEngine
    {
        $engine = new SubscriptionEngine(
            $this->eventStore,
            $this->subscriptionStore,
            Subscribers::fromArray($subscribers),
        );
        $engine->onEngineEvent(fn(EngineEvent $event) => $this->emittedEngineEvents[] = $event);
        return $engine;
    }

    private function assertEmittedEngineEvents(string ...$expectedEvents): void
    {
        self::assertSame($expectedEvents, array_map(static fn(EngineEvent $event) => sprintf('%s: %s', substr($event::class, strrpos($event::class, '\\') + 1), $event), $this->emittedEngineEvents));
    }

    private function assertSubscriptions(array ...$subscriptions): void
    {
        self::assertSame($subscriptions, $this->subscriptionStore->findByCriteriaForUpdate(SubscriptionCriteria::noConstraints())->map(static fn(Subscription $subscription) => [
            'id' => $subscription->id->value,
            'status' => $subscription->status->value,
            'position' => $subscription->position->value,
            'error' => $subscription->error->errorMessage,
        ]));
    }
}
