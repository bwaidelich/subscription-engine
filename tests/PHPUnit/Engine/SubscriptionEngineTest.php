<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Tests\PHPUnit\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\CatchUpFinished;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\EngineEvent;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\NoSubscriptionsFound;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\SubscriberHandledEvent;
use Wwwision\SubscriptionEngine\Engine\Exception\RecursiveCatchUpException;
use Wwwision\SubscriptionEngine\Engine\Exception\UnknownSubscriptionIdsException;
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
            ['id' => 's1', 'status' => 'ERROR', 'position' => 0, 'error' => 'Error: Just testing in file ' . __FILE__ . ' on line ' . (__LINE__ - 7)],
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
        $handler1ExceptionLineNumber = 0;
        $subscriber1 = Subscriber::create(SubscriptionId::fromString('s1'), function () use (&$handler1Invocations, &$handler1ExceptionLineNumber) {
            $handler1Invocations++;
            if ($handler1Invocations > 1) {
                $handler1ExceptionLineNumber = __LINE__ + 1;
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
            ['id' => 's1', 'status' => 'ERROR', 'position' => 1, 'error' => 'RuntimeException: Exception from s1 in file ' . __FILE__ . ' on line ' . $handler1ExceptionLineNumber],
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

    public function test_catchUpActive_fails_if_called_recursively_without_criteria(): void
    {
        $subscriptionEngine = null;
        $subscriber1 = Subscriber::create('s1', static function () use (&$subscriptionEngine) {
            $subscriptionEngine->catchUpActive();
        });
        $this->subscriptionStore->_setSubscriptions(
            Subscription::create(id: 's1', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::ACTIVE),
        );
        $subscriptionEngine = $this->subscriptionEngine($subscriber1);
        $this->eventStore->append('event at #1');
        $result = $subscriptionEngine->catchUpActive();
        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'ERROR', 'position' => 0, 'error' => RecursiveCatchUpException::class . ': Failed to catch up all subscriptions while catch up of subscription "s1" is still running in file ' . realpath(__DIR__ . '/../../../src/Engine/Exception/RecursiveCatchUpException.php') . ' on line 23'],
        );
        $this->assertEmittedEngineEvents(
            'CatchUpInitiated: Initiated catch-up of subscriptions in states ACTIVE',
            'CatchUpStarted: Starting catch-up of 1 subscription from position 1',
            'CatchUpInitiated: Initiated catch-up of subscriptions in states ACTIVE',
            'SubscriberFailedToHandleEvent: Subscriber for "s1" failed to process event "' . MockEvent::class . '" (position: 1): Failed to catch up all subscriptions while catch up of subscription "s1" is still running',
            'CatchUpFinished: Finished catch-up of 1 subscription, processed 1 event (1 error)',
        );
        self::assertSame(1, $result->numberOfProcessedEvents);
        self::assertSame('Event 1 in "s1": Failed to catch up all subscriptions while catch up of subscription "s1" is still running', $result->errors->getClampedMessage());
    }

    public function test_catchUpActive_fails_if_called_recursively_with_overlapping_criteria(): void
    {
        $subscriptionEngine = null;
        $subscriber1 = Subscriber::create('s1', static function () use (&$subscriptionEngine) {
            $subscriptionEngine->catchUpActive(SubscriptionEngineCriteria::create(ids: ['s3']));
        });
        $subscriber2 = Subscriber::create('s2', static function () use (&$subscriptionEngine) {
            $subscriptionEngine->catchUpActive(SubscriptionEngineCriteria::create(ids: ['s3']));
        });
        $subscriber3 = Subscriber::create('s3', static fn () => null);
        $this->subscriptionStore->_setSubscriptions(
            Subscription::create(id: 's1', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::ACTIVE),
            Subscription::create(id: 's2', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::ACTIVE),
            Subscription::create(id: 's3', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::ACTIVE),
        );
        $subscriptionEngine = $this->subscriptionEngine($subscriber1, $subscriber2, $subscriber3);
        $this->eventStore->append('event at #1');
        $result = $subscriptionEngine->catchUpActive(SubscriptionEngineCriteria::create(ids: ['s3', 's1']));
        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'ERROR', 'position' => 0, 'error' => RecursiveCatchUpException::class . ': Failed to catch up subscription "s3" while catch up of subscriptions "s1", "s3" is still running in file ' . realpath(__DIR__ . '/../../../src/Engine/Exception/RecursiveCatchUpException.php') . ' on line 23'],
            ['id' => 's2', 'status' => 'ACTIVE', 'position' => 0, 'error' => null],
            ['id' => 's3', 'status' => 'ACTIVE', 'position' => 1, 'error' => null],
        );
        $this->assertEmittedEngineEvents(
            'CatchUpInitiated: Initiated catch-up of subscriptions in states ACTIVE',
            'CatchUpStarted: Starting catch-up of 2 subscriptions from position 1',
            'CatchUpInitiated: Initiated catch-up of subscriptions in states ACTIVE',
            'SubscriberFailedToHandleEvent: Subscriber for "s1" failed to process event "' . MockEvent::class . '" (position: 1): Failed to catch up subscription "s3" while catch up of subscriptions "s1", "s3" is still running',
            'SubscriberHandledEvent: Subscriber for "s3" processed event "' . MockEvent::class . '" (position: 1)',
            'CatchUpFinished: Finished catch-up of 2 subscriptions, processed 1 event (1 error)',
        );
        self::assertSame(1, $result->numberOfProcessedEvents);
        self::assertSame('Event 1 in "s1": Failed to catch up subscription "s3" while catch up of subscriptions "s1", "s3" is still running', $result->errors->getClampedMessage());

        $this->eventStore->append('event at #2');
        $result = $subscriptionEngine->catchUpActive(SubscriptionEngineCriteria::create(ids: ['s3', 's2']));
        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'ERROR', 'position' => 0, 'error' => RecursiveCatchUpException::class . ': Failed to catch up subscription "s3" while catch up of subscriptions "s1", "s3" is still running in file ' . realpath(__DIR__ . '/../../../src/Engine/Exception/RecursiveCatchUpException.php') . ' on line 23'],
            ['id' => 's2', 'status' => 'ERROR', 'position' => 0, 'error' => RecursiveCatchUpException::class . ': Failed to catch up subscription "s3" while catch up of subscriptions "s2", "s3" is still running in file ' . realpath(__DIR__ . '/../../../src/Engine/Exception/RecursiveCatchUpException.php') . ' on line 23'],
            ['id' => 's3', 'status' => 'ACTIVE', 'position' => 2, 'error' => null],
        );
        $this->assertEmittedEngineEvents(
            'CatchUpInitiated: Initiated catch-up of subscriptions in states ACTIVE',
            'CatchUpStarted: Starting catch-up of 2 subscriptions from position 1',
            'CatchUpInitiated: Initiated catch-up of subscriptions in states ACTIVE',
            'SubscriberFailedToHandleEvent: Subscriber for "s2" failed to process event "' . MockEvent::class . '" (position: 1): Failed to catch up subscription "s3" while catch up of subscriptions "s2", "s3" is still running',
            'SubscriberSkippedEvent: Subscriber for "s3" skipped event "' . MockEvent::class . '" (position: 1) because it is already father ahead (position: 1)',
            'SubscriberHandledEvent: Subscriber for "s3" processed event "' . MockEvent::class . '" (position: 2)',
            'CatchUpFinished: Finished catch-up of 2 subscriptions, processed 2 events (1 error)',
        );
        self::assertSame(2, $result->numberOfProcessedEvents);
        self::assertSame('Event 1 in "s2": Failed to catch up subscription "s3" while catch up of subscriptions "s2", "s3" is still running', $result->errors->getClampedMessage());
    }

    public function test_catchUpActive_does_not_fail_if_called_recursively_with_non_overlapping_criteria(): void
    {
        $subscriptionEngine = null;
        $subscriber1 = Subscriber::create('s1', static function () use (&$subscriptionEngine) {
            $subscriptionEngine->catchUpActive(SubscriptionEngineCriteria::create(ids: ['s3']));
        });
        $subscriber2 = Subscriber::create('s2', static fn () => null);
        $subscriber3 = Subscriber::create('s3', static fn () => null);
        $this->subscriptionStore->_setSubscriptions(
            Subscription::create(id: 's1', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::ACTIVE),
            Subscription::create(id: 's2', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::ACTIVE),
            Subscription::create(id: 's3', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::ACTIVE),
        );
        $subscriptionEngine = $this->subscriptionEngine($subscriber1, $subscriber2, $subscriber3);
        $this->eventStore->append('event at #1');
        $result = $subscriptionEngine->catchUpActive(SubscriptionEngineCriteria::create(ids: ['s2', 's1']));
        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'ACTIVE', 'position' => 1, 'error' => null],
            ['id' => 's2', 'status' => 'ACTIVE', 'position' => 1, 'error' => null],
            ['id' => 's3', 'status' => 'ACTIVE', 'position' => 1, 'error' => null],
        );
        $this->assertEmittedEngineEvents(
            'CatchUpInitiated: Initiated catch-up of subscriptions in states ACTIVE',
            'CatchUpStarted: Starting catch-up of 2 subscriptions from position 1',
            'CatchUpInitiated: Initiated catch-up of subscriptions in states ACTIVE',
            'CatchUpStarted: Starting catch-up of 1 subscription from position 1',
            'SubscriberHandledEvent: Subscriber for "s3" processed event "' . MockEvent::class . '" (position: 1)',
            'CatchUpFinished: Finished catch-up of 1 subscription, processed 1 event (no errors)',
            'SubscriberHandledEvent: Subscriber for "s1" processed event "' . MockEvent::class . '" (position: 1)',
            'SubscriberHandledEvent: Subscriber for "s2" processed event "' . MockEvent::class . '" (position: 1)',
            'CatchUpFinished: Finished catch-up of 2 subscriptions, processed 1 event (no errors)',
        );
        self::assertSame(1, $result->numberOfProcessedEvents);
        self::assertTrue($result->successful());
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
            Subscriber::create(id: 's3', handler: fn () => null),
        );
        $result = $subscriptionEngine->reset(SubscriptionEngineCriteria::create(ids: ['s3']));

        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'ACTIVE', 'position' => 123, 'error' => null],
            ['id' => 's2', 'status' => 'ACTIVE', 'position' => 321, 'error' => null],
        );
        $this->assertEmittedEngineEvents(
            'ResetStarted: Start to setup',
            'NoSubscriptionsFound: No subscriptions found for criteria: {"ids":["s3"],"status":["NEW","BOOTING","ACTIVE","DETACHED","ERROR","RESETTING"]}'
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

    public function test_getSubscriptions_returns_empty_set_if_no_subscriptions_exist(): void
    {
        $subscriptions = $this->subscriptionEngine()->getSubscriptions();
        self::assertTrue($subscriptions->isEmpty());
    }

    public function test_getSubscriptions_returns_all_subscriptions_without_criteria(): void
    {
        $this->subscriptionStore->_setSubscriptions(
            Subscription::create(id: 's1', runMode: RunMode::FROM_NOW, status: SubscriptionStatus::ACTIVE),
            Subscription::create(id: 's2', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::BOOTING),
            Subscription::create(id: 's3', runMode: RunMode::FROM_NOW, status: SubscriptionStatus::DETACHED),
        );

        $subscriptions = $this->subscriptionEngine()->getSubscriptions();

        self::assertSame(['s1', 's2', 's3'], $subscriptions->getIds()->toStringArray());
    }

    public function test_getSubscriptions_filters_by_criteria(): void
    {
        $this->subscriptionStore->_setSubscriptions(
            Subscription::create(id: 's1', runMode: RunMode::FROM_NOW, status: SubscriptionStatus::ACTIVE),
            Subscription::create(id: 's2', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::BOOTING),
            Subscription::create(id: 's3', runMode: RunMode::FROM_NOW, status: SubscriptionStatus::DETACHED),
        );

        $subscriptions = $this->subscriptionEngine()->getSubscriptions(SubscriptionEngineCriteria::create(ids: ['s1', 's3']));

        self::assertSame(['s1', 's3'], $subscriptions->getIds()->toStringArray());
    }

    public function test_getSubscriptions_returns_subscriptions_of_any_status(): void
    {
        $this->subscriptionStore->_setSubscriptions(
            Subscription::create(id: 's1', runMode: RunMode::FROM_NOW, status: SubscriptionStatus::NEW),
            Subscription::create(id: 's2', runMode: RunMode::FROM_NOW, status: SubscriptionStatus::BOOTING),
            Subscription::create(id: 's3', runMode: RunMode::FROM_NOW, status: SubscriptionStatus::ACTIVE),
            Subscription::create(id: 's4', runMode: RunMode::FROM_NOW, status: SubscriptionStatus::DETACHED),
            Subscription::create(id: 's5', runMode: RunMode::FROM_NOW, status: SubscriptionStatus::ERROR),
        );

        $subscriptions = $this->subscriptionEngine()->getSubscriptions();

        self::assertSame(['s1', 's2', 's3', 's4', 's5'], $subscriptions->getIds()->toStringArray());
    }

    public function test_getSubscription_returns_null_if_no_subscription_with_given_id_exists(): void
    {
        $this->subscriptionStore->_setSubscriptions(
            Subscription::create(id: 's1', runMode: RunMode::FROM_NOW, status: SubscriptionStatus::ACTIVE),
        );

        self::assertNull($this->subscriptionEngine()->getSubscription(SubscriptionId::fromString('non-existing')));
    }

    public function test_getSubscription_returns_matching_subscription(): void
    {
        $this->subscriptionStore->_setSubscriptions(
            Subscription::create(id: 's1', runMode: RunMode::FROM_NOW, status: SubscriptionStatus::ACTIVE),
            Subscription::create(id: 's2', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::BOOTING)->with(position: Position::fromInteger(7)),
        );

        $subscription = $this->subscriptionEngine()->getSubscription(SubscriptionId::fromString('s2'));

        self::assertNotNull($subscription);
        self::assertSame('s2', $subscription->id->value);
        self::assertSame(SubscriptionStatus::BOOTING, $subscription->status);
        self::assertSame(7, $subscription->position->value);
    }

    public function test_getSubscription_returns_subscription_in_any_status(): void
    {
        $this->subscriptionStore->_setSubscriptions(
            Subscription::create(id: 's1', runMode: RunMode::FROM_NOW, status: SubscriptionStatus::DETACHED),
        );

        $subscription = $this->subscriptionEngine()->getSubscription(SubscriptionId::fromString('s1'));

        self::assertNotNull($subscription);
        self::assertSame(SubscriptionStatus::DETACHED, $subscription->status);
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

        self::assertSame(SubscriptionStatus::ERROR, $this->subscriptionStore->findByCriteria(SubscriptionCriteria::noConstraints())->get(SubscriptionId::fromString('s1'))->status);
        self::assertSame(SubscriptionStatus::ACTIVE, $this->subscriptionStore->findByCriteria(SubscriptionCriteria::noConstraints())->get(SubscriptionId::fromString('s1'))->error?->previousStatus);
        self::assertSame(123, $this->subscriptionStore->findByCriteria(SubscriptionCriteria::noConstraints())->get(SubscriptionId::fromString('s1'))->position->value);
        $this->assertEmittedEngineEvents(
            'ResetStarted: Start to setup',
            'FailedToResetSubscriber: Subscriber for "s1" has an error in the reset method: Exception from s1 reset function',
        );
        self::assertSame('"s1": Exception from s1 reset function', $result->errors->getClampedMessage());
    }

    public function test_reset_keeps_workers_out_while_its_handler_is_running(): void
    {
        $this->eventStore->append(1);
        $this->eventStore->append(1);
        $this->eventStore->append(1);
        $this->subscriptionStore->_setSubscriptions(self::subscriptionAt('s1', SubscriptionStatus::ACTIVE, 2));
        $handled = [];
        $handler = function (MockEvent $event) use (&$handled) {
            $handled[] = $event->position->value;
        };
        $worker = $this->subscriptionEngine(Subscriber::create(id: 's1', handler: $handler));
        $results = [];
        $transactionActiveInHandler = null;
        $engine = $this->subscriptionEngine(Subscriber::create(id: 's1', handler: $handler, reset: function () use ($worker, &$results, &$transactionActiveInHandler) {
            $transactionActiveInHandler = $this->subscriptionStore->_isTransactionActive();
            $results[] = $worker->catchUpActive();
            $results[] = $worker->catchUpNextActive();
            $results[] = $worker->boot();
            $results[] = $worker->bootNext();
        }));

        $result = $engine->reset();

        self::assertTrue($result->successful());
        self::assertFalse($transactionActiveInHandler, 'handlers must be able to use DDL');
        self::assertSame([], $handled);
        self::assertSame([0, 0, 0, 0], array_map(static fn ($r) => $r->numberOfProcessedEvents, $results));
        $this->assertSubscriptions(['id' => 's1', 'status' => 'BOOTING', 'position' => 0, 'error' => null]);
    }

    public function test_reset_fences_all_subscriptions_before_the_first_handler_runs(): void
    {
        $this->subscriptionStore->_setSubscriptions(self::subscriptionAt('s1', SubscriptionStatus::ACTIVE, 1), self::subscriptionAt('s2', SubscriptionStatus::BOOTING, 1), self::subscriptionAt('s3', SubscriptionStatus::ACTIVE, 1));
        $statusInHandler = [];
        $engine = $this->subscriptionEngine(
            Subscriber::create(id: 's1', handler: fn () => null, reset: function () use (&$statusInHandler) {
                $statusInHandler = array_map(static fn (Subscription $s) => $s->status->value, iterator_to_array($this->subscriptionStore->findByCriteria(SubscriptionCriteria::noConstraints()), false));
            }),
            Subscriber::create(id: 's2', handler: fn () => null),
        );

        $engine->reset();

        self::assertSame(['RESETTING', 'RESETTING', 'ACTIVE'], $statusInHandler, 's3 has no subscriber and must not be fenced');
        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'BOOTING', 'position' => 0, 'error' => null],
            ['id' => 's2', 'status' => 'BOOTING', 'position' => 0, 'error' => null],
            ['id' => 's3', 'status' => 'ACTIVE', 'position' => 1, 'error' => null],
        );
    }

    public function test_reset_changes_nothing_if_the_fence_fails(): void
    {
        $this->subscriptionStore->_setSubscriptions(self::subscriptionAt('s1', SubscriptionStatus::ACTIVE, 1), self::subscriptionAt('s2', SubscriptionStatus::ACTIVE, 2));
        $this->subscriptionStore->_failOnUpdateOf('s2');
        $handlerCalled = false;
        $engine = $this->subscriptionEngine(
            Subscriber::create(id: 's1', handler: fn () => null, reset: function () use (&$handlerCalled) {
                $handlerCalled = true;
            }),
            Subscriber::create(id: 's2', handler: fn () => null),
        );

        try {
            $engine->reset();
            self::fail('Expected the exception to escape');
        } catch (\RuntimeException $e) {
            self::assertSame('Update of s2 failed', $e->getMessage());
        }

        self::assertFalse($handlerCalled);
        self::assertFalse($this->subscriptionStore->_isTransactionActive());
        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'ACTIVE', 'position' => 1, 'error' => null],
            ['id' => 's2', 'status' => 'ACTIVE', 'position' => 2, 'error' => null],
        );
    }

    public function test_reset_continues_with_other_subscriptions_if_a_handler_fails(): void
    {
        $this->subscriptionStore->_setSubscriptions(self::subscriptionAt('s1', SubscriptionStatus::ACTIVE, 1), self::subscriptionAt('s2', SubscriptionStatus::ACTIVE, 2));
        $engine = $this->subscriptionEngine(
            Subscriber::create(id: 's1', handler: fn () => null, reset: fn () => throw new \RuntimeException('Exception from s1 reset function')),
            Subscriber::create(id: 's2', handler: fn () => null),
        );

        $result = $engine->reset();

        self::assertFalse($result->successful());
        $subscriptions = $this->subscriptionStore->findByCriteria(SubscriptionCriteria::noConstraints());
        self::assertSame(SubscriptionStatus::ERROR, $subscriptions->get(SubscriptionId::fromString('s1'))->status);
        self::assertSame(SubscriptionStatus::BOOTING, $subscriptions->get(SubscriptionId::fromString('s2'))->status);

        // a retry recovers from ERROR without force
        $retried = $this->subscriptionEngine(Subscriber::create(id: 's1', handler: fn () => null), Subscriber::create(id: 's2', handler: fn () => null))->reset();
        self::assertTrue($retried->successful());
        self::assertSame(SubscriptionStatus::BOOTING, $this->subscriptionStore->findByCriteria(SubscriptionCriteria::noConstraints())->get(SubscriptionId::fromString('s1'))->status);
    }

    public function test_reset_refuses_a_subscription_that_is_already_resetting(): void
    {
        $this->subscriptionStore->_setSubscriptions(self::subscriptionAt('s1', SubscriptionStatus::RESETTING, 3), self::subscriptionAt('s2', SubscriptionStatus::ACTIVE, 2));
        $handlerCalled = false;
        $engine = $this->subscriptionEngine(
            Subscriber::create(id: 's1', handler: fn () => null, reset: function () use (&$handlerCalled) {
                $handlerCalled = true;
            }),
            Subscriber::create(id: 's2', handler: fn () => null, reset: fn () => null),
        );

        $result = $engine->reset();

        self::assertFalse($handlerCalled);
        self::assertFalse($result->successful());
        self::assertStringContainsString('"s1"', $result->errors->getClampedMessage());
        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'RESETTING', 'position' => 3, 'error' => null],
            ['id' => 's2', 'status' => 'BOOTING', 'position' => 0, 'error' => null],
        );
        $this->assertEmittedEngineEvents(
            'ResetStarted: Start to setup',
            'ResetAlreadyInProgress: Subscription "s1" is already being reset, or a previous reset has crashed. Skipping it, use the "force" flag to take over',
            'SubscriberWasReset: Subscriber for "s2" has been reset, changing status from ACTIVE to BOOTING',
        );
    }

    public function test_reset_with_force_takes_over_a_subscription_that_is_resetting(): void
    {
        $this->subscriptionStore->_setSubscriptions(self::subscriptionAt('s1', SubscriptionStatus::RESETTING, 3));
        $handlerCalled = false;
        $engine = $this->subscriptionEngine(Subscriber::create(id: 's1', handler: fn () => null, reset: function () use (&$handlerCalled) {
            $handlerCalled = true;
        }));

        $result = $engine->reset(force: true);

        self::assertTrue($handlerCalled);
        self::assertTrue($result->successful());
        $this->assertSubscriptions(['id' => 's1', 'status' => 'BOOTING', 'position' => 0, 'error' => null]);
    }

    public function test_reset_leaves_subscriptions_fenced_if_the_final_write_fails_and_force_recovers(): void
    {
        $this->subscriptionStore->_setSubscriptions(self::subscriptionAt('s1', SubscriptionStatus::ACTIVE, 1), self::subscriptionAt('s2', SubscriptionStatus::ACTIVE, 2));
        $engine = $this->subscriptionEngine(
            Subscriber::create(id: 's1', handler: fn () => null, reset: function () {
                $this->subscriptionStore->_failOnUpdateOf('s1');
            }),
            Subscriber::create(id: 's2', handler: fn () => null),
        );

        try {
            $engine->reset();
            self::fail('Expected the exception to escape');
        } catch (\RuntimeException $e) {
            self::assertSame('Update of s1 failed', $e->getMessage());
        }
        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'RESETTING', 'position' => 1, 'error' => null],
            ['id' => 's2', 'status' => 'RESETTING', 'position' => 2, 'error' => null],
        );

        $this->subscriptionStore->_failOnUpdateOf();
        self::assertTrue($this->subscriptionEngine(Subscriber::create(id: 's1', handler: fn () => null), Subscriber::create(id: 's2', handler: fn () => null))->reset(force: true)->successful());
        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'BOOTING', 'position' => 0, 'error' => null],
            ['id' => 's2', 'status' => 'BOOTING', 'position' => 0, 'error' => null],
        );
    }

    public function test_setup_does_not_regress_the_position_written_by_a_worker_during_its_handler(): void
    {
        $this->eventStore->append(1);
        $this->eventStore->append(1);
        $this->eventStore->append(1);
        $this->subscriptionStore->_setSubscriptions(Subscription::create(id: 's1', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::BOOTING));
        $worker = $this->subscriptionEngine(Subscriber::create(id: 's1', handler: fn () => null));
        $transactionActiveInHandler = null;
        $engine = $this->subscriptionEngine(Subscriber::create(id: 's1', handler: fn () => null, setup: function () use ($worker, &$transactionActiveInHandler) {
            $transactionActiveInHandler = $this->subscriptionStore->_isTransactionActive();
            $worker->boot(limit: 2);
        }));

        $result = $engine->setup();

        self::assertTrue($result->successful());
        self::assertFalse($transactionActiveInHandler);
        $this->assertSubscriptions(['id' => 's1', 'status' => 'BOOTING', 'position' => 2, 'error' => null]);
    }

    public function test_setup_does_not_downgrade_a_subscription_that_a_worker_activated_during_its_handler(): void
    {
        $this->eventStore->append(1);
        $this->subscriptionStore->_setSubscriptions(Subscription::create(id: 's1', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::BOOTING));
        $worker = $this->subscriptionEngine(Subscriber::create(id: 's1', handler: fn () => null));
        $engine = $this->subscriptionEngine(Subscriber::create(id: 's1', handler: fn () => null, setup: fn () => $worker->boot()));

        $engine->setup();

        $this->assertSubscriptions(['id' => 's1', 'status' => 'ACTIVE', 'position' => 1, 'error' => null]);
    }

    public function test_setup_does_not_overwrite_the_fence_of_a_concurrent_reset(): void
    {
        $this->subscriptionStore->_setSubscriptions(self::subscriptionAt('s1', SubscriptionStatus::BOOTING, 2));
        $engine = $this->subscriptionEngine(Subscriber::create(id: 's1', handler: fn () => null, setup: function () {
            $this->subscriptionStore->_setSubscriptions(self::subscriptionAt('s1', SubscriptionStatus::RESETTING, 2));
        }));

        self::assertTrue($engine->setup()->successful());

        $this->assertSubscriptions(['id' => 's1', 'status' => 'RESETTING', 'position' => 2, 'error' => null]);
    }

    public function test_setup_ignores_resetting_subscriptions(): void
    {
        $this->subscriptionStore->_setSubscriptions(self::subscriptionAt('s1', SubscriptionStatus::RESETTING, 2));
        $handlerCalled = false;
        $engine = $this->subscriptionEngine(Subscriber::create(id: 's1', handler: fn () => null, setup: function () use (&$handlerCalled) {
            $handlerCalled = true;
        }));

        $engine->setup();

        self::assertFalse($handlerCalled);
        $this->assertSubscriptions(['id' => 's1', 'status' => 'RESETTING', 'position' => 2, 'error' => null]);
    }

    public function test_setup_writes_a_failing_handler_on_the_fresh_subscription(): void
    {
        $this->subscriptionStore->_setSubscriptions(self::subscriptionAt('s1', SubscriptionStatus::BOOTING, 0));
        $worker = $this->subscriptionEngine(Subscriber::create(id: 's1', handler: fn () => null));
        $this->eventStore->append(1);
        $this->eventStore->append(1);
        $engine = $this->subscriptionEngine(Subscriber::create(id: 's1', handler: fn () => null, setup: function () use ($worker) {
            $worker->boot(limit: 1);
            throw new \RuntimeException('Exception from s1 setup function');
        }));

        self::assertFalse($engine->setup()->successful());

        $subscription = $this->subscriptionStore->findByCriteria(SubscriptionCriteria::noConstraints())->get(SubscriptionId::fromString('s1'));
        self::assertSame(SubscriptionStatus::ERROR, $subscription->status);
        self::assertSame(1, $subscription->position->value);
    }

    public function test_setup_does_not_overwrite_a_fence_with_a_failing_handler(): void
    {
        $this->subscriptionStore->_setSubscriptions(self::subscriptionAt('s1', SubscriptionStatus::BOOTING, 2));
        $engine = $this->subscriptionEngine(Subscriber::create(id: 's1', handler: fn () => null, setup: function () {
            $this->subscriptionStore->_setSubscriptions(self::subscriptionAt('s1', SubscriptionStatus::RESETTING, 2));
            throw new \RuntimeException('Exception from s1 setup function');
        }));

        self::assertFalse($engine->setup()->successful());

        $this->assertSubscriptions(['id' => 's1', 'status' => 'RESETTING', 'position' => 2, 'error' => null]);
    }

    public function test_setup_rolls_back_and_rethrows_if_the_transition_fails(): void
    {
        $this->subscriptionStore->_setSubscriptions(Subscription::create(id: 's1', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::NEW));
        $this->subscriptionStore->_failOnUpdateOf('s1');
        $engine = $this->subscriptionEngine(Subscriber::create(id: 's1', handler: fn () => null));

        try {
            $engine->setup();
            self::fail('Expected the exception to escape');
        } catch (\RuntimeException $e) {
            self::assertSame('Update of s1 failed', $e->getMessage());
        }

        self::assertFalse($this->subscriptionStore->_isTransactionActive());
        self::assertTrue($this->subscriptionStore->_wasRollbackCalled());
    }

    /**
     * @return iterable<array{string, array<string>|null}>
     */
    public static function recursiveSetupAndResetCalls(): iterable
    {
        yield 'setup without criteria' => ['setup', null];
        yield 'setup with overlapping criteria' => ['setup', ['s1']];
        yield 'reset without criteria' => ['reset', null];
        yield 'reset with overlapping criteria' => ['reset', ['s1']];
    }

    /**
     * @param array<string>|null $ids
     */
    #[DataProvider('recursiveSetupAndResetCalls')]
    public function test_setup_and_reset_throw_if_called_recursively_for_the_subscription_being_processed(string $method, array|null $ids): void
    {
        $this->eventStore->append(1);
        $this->subscriptionStore->_setSubscriptions(self::subscriptionAt('s1', SubscriptionStatus::ACTIVE, 0));
        $engine = null;
        $caught = null;
        $engine = $this->subscriptionEngine(Subscriber::create(id: 's1', handler: function () use (&$engine, &$caught, $method, $ids) {
            try {
                $engine->{$method}($ids !== null ? SubscriptionEngineCriteria::create($ids) : null);
            } catch (RecursiveCatchUpException $e) {
                $caught = $e;
            }
        }));

        $engine->catchUpActive();

        self::assertNotNull($caught);
        $this->assertSubscriptions(['id' => 's1', 'status' => 'ACTIVE', 'position' => 1, 'error' => null]);
    }

    public function test_reset_is_allowed_recursively_for_other_subscriptions(): void
    {
        $this->eventStore->append(1);
        $this->subscriptionStore->_setSubscriptions(self::subscriptionAt('s1', SubscriptionStatus::ACTIVE, 0), self::subscriptionAt('s2', SubscriptionStatus::ERROR, 0));
        $engine = null;
        $engine = $this->subscriptionEngine(
            Subscriber::create(id: 's1', handler: function () use (&$engine) {
                $engine->reset(SubscriptionEngineCriteria::create(['s2']));
            }),
            Subscriber::create(id: 's2', handler: fn () => null),
        );

        self::assertTrue($engine->catchUpActive(SubscriptionEngineCriteria::create(['s1']))->successful());

        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'ACTIVE', 'position' => 1, 'error' => null],
            ['id' => 's2', 'status' => 'BOOTING', 'position' => 0, 'error' => null],
        );
    }

    // ---------------------

    public static function engineMethods(): iterable
    {
        yield 'setup' => ['setup'];
        yield 'boot' => ['boot'];
        yield 'catchUpActive' => ['catchUpActive'];
        yield 'reset' => ['reset'];
    }

    #[DataProvider('engineMethods')]
    public function test_engine_methods_throw_for_an_unknown_subscription_id(string $method): void
    {
        $this->subscriptionStore->_setSubscriptions(Subscription::create(id: 's1', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::BOOTING));
        $subscriber = Subscriber::create(SubscriptionId::fromString('s1'), fn() => null);
        $subscriptionEngine = $this->subscriptionEngine($subscriber);

        try {
            $subscriptionEngine->{$method}(SubscriptionEngineCriteria::create(['s1', 's2']));
            self::fail(sprintf('Expected %s to be thrown', UnknownSubscriptionIdsException::class));
        } catch (UnknownSubscriptionIdsException $exception) {
            self::assertSame('Unknown subscription id "s2"', $exception->getMessage());
        }

        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'BOOTING', 'position' => 0, 'error' => null],
        );
        $this->assertEmittedEngineEvents();
    }

    #[DataProvider('engineMethods')]
    public function test_engine_methods_throw_for_unknown_subscription_ids(string $method): void
    {
        $this->subscriptionStore->_setSubscriptions(Subscription::create(id: 's1', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::BOOTING));
        $subscriber = Subscriber::create(SubscriptionId::fromString('s1'), fn() => null);
        $subscriptionEngine = $this->subscriptionEngine($subscriber);

        try {
            $subscriptionEngine->{$method}(SubscriptionEngineCriteria::create(['s1', 's2', 's3']));
            self::fail(sprintf('Expected %s to be thrown', UnknownSubscriptionIdsException::class));
        } catch (UnknownSubscriptionIdsException $exception) {
            self::assertSame('Unknown subscription ids "s2", "s3"', $exception->getMessage());
        }

        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'BOOTING', 'position' => 0, 'error' => null],
        );
        $this->assertEmittedEngineEvents();
    }

    #[DataProvider('engineMethods')]
    public function test_engine_methods_throw_for_the_id_of_a_detached_subscription(string $method): void
    {
        $this->subscriptionStore->_setSubscriptions(Subscription::create(id: 's1', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::DETACHED));
        $subscriptionEngine = $this->subscriptionEngine();

        try {
            $subscriptionEngine->{$method}(SubscriptionEngineCriteria::create(['s1']));
            self::fail(sprintf('Expected %s to be thrown', UnknownSubscriptionIdsException::class));
        } catch (UnknownSubscriptionIdsException $exception) {
            self::assertSame('Unknown subscription id "s1"', $exception->getMessage());
        }

        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'DETACHED', 'position' => 0, 'error' => null],
        );
        $this->assertEmittedEngineEvents();
    }

    public function test_catchUpActive_can_run_again_after_all_matching_subscriptions_were_detached(): void
    {
        $this->subscriptionStore->_setSubscriptions(
            Subscription::create(id: 's1', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::ACTIVE),
        );
        $subscriptionEngine = $this->subscriptionEngine();

        $subscriptionEngine->catchUpActive();
        $result = $subscriptionEngine->catchUpActive();

        self::assertTrue($result->successful());
        self::assertSame(0, $result->numberOfProcessedEvents);
    }

    public function test_catchUpActive_with_limit_stops_after_limit_and_reports_pending_events(): void
    {
        $handledEvents = [];
        $subscriber = Subscriber::create(SubscriptionId::fromString('s1'), function (MockEvent $event) use (&$handledEvents) {
            $handledEvents[] = $event->position->value;
        });
        $this->subscriptionStore->_setSubscriptions(
            Subscription::create(id: 's1', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::ACTIVE),
        );
        $this->eventStore->append('event at #1');
        $this->eventStore->append('event at #2');
        $this->eventStore->append('event at #3');

        $result = $this->subscriptionEngine($subscriber)->catchUpActive(limit: 2);

        self::assertSame([1, 2], $handledEvents);
        self::assertSame(2, $result->numberOfProcessedEvents);
        self::assertTrue($result->hasPendingEvents);
        self::assertFalse($this->subscriptionStore->_isTransactionActive());
        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'ACTIVE', 'position' => 2, 'error' => null],
        );
        $this->assertEmittedEngineEvents(
            'CatchUpInitiated: Initiated catch-up of subscriptions in states ACTIVE',
            'CatchUpStarted: Starting catch-up of 1 subscription from position 1',
            'SubscriberHandledEvent: Subscriber for "s1" processed event "' . MockEvent::class . '" (position: 1)',
            'SubscriberHandledEvent: Subscriber for "s1" processed event "' . MockEvent::class . '" (position: 2)',
            'CatchUpFinished: Finished catch-up of 1 subscription, processed 2 events (no errors), more events pending',
        );
    }

    public function test_boot_with_limit_keeps_subscriptions_booting_until_no_events_are_pending(): void
    {
        $subscriber = Subscriber::create(SubscriptionId::fromString('s1'), fn() => null);
        $this->subscriptionStore->_setSubscriptions(
            Subscription::create(id: 's1', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::BOOTING),
        );
        $this->eventStore->append('event at #1');
        $this->eventStore->append('event at #2');
        $this->eventStore->append('event at #3');
        $subscriptionEngine = $this->subscriptionEngine($subscriber);

        $firstResult = $subscriptionEngine->boot(limit: 2);

        self::assertTrue($firstResult->hasPendingEvents);
        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'BOOTING', 'position' => 2, 'error' => null],
        );
        $this->assertEmittedEngineEvents(
            'CatchUpInitiated: Initiated catch-up of subscriptions in states BOOTING',
            'CatchUpStarted: Starting catch-up of 1 subscription from position 1',
            'SubscriberHandledEvent: Subscriber for "s1" processed event "' . MockEvent::class . '" (position: 1)',
            'SubscriberHandledEvent: Subscriber for "s1" processed event "' . MockEvent::class . '" (position: 2)',
            'CatchUpFinished: Finished catch-up of 1 subscription, processed 2 events (no errors), more events pending',
        );

        $secondResult = $subscriptionEngine->boot(limit: 2);

        self::assertFalse($secondResult->hasPendingEvents);
        self::assertSame(1, $secondResult->numberOfProcessedEvents);
        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'ACTIVE', 'position' => 3, 'error' => null],
        );
        $this->assertEmittedEngineEvents(
            'CatchUpInitiated: Initiated catch-up of subscriptions in states BOOTING',
            'CatchUpStarted: Starting catch-up of 1 subscription from position 3',
            'SubscriberHandledEvent: Subscriber for "s1" processed event "' . MockEvent::class . '" (position: 3)',
            'SubscriptionActivated: Subscription "s1" has been set to ACTIVE from status BOOTING',
            'CatchUpFinished: Finished catch-up of 1 subscription, processed 1 event (no errors)',
        );
    }

    public function test_catchUpActive_with_limit_reports_no_pending_events_if_all_subscriptions_failed(): void
    {
        $subscriber = Subscriber::create(SubscriptionId::fromString('s1'), function () {
            throw new \RuntimeException('Exception from s1');
        });
        $this->subscriptionStore->_setSubscriptions(
            Subscription::create(id: 's1', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::ACTIVE),
        );
        $this->eventStore->append('event at #1');
        $this->eventStore->append('event at #2');
        $this->eventStore->append('event at #3');

        $result = $this->subscriptionEngine($subscriber)->catchUpActive(limit: 2);

        self::assertFalse($result->successful());
        self::assertFalse($result->hasPendingEvents);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function invalidLimitDataProvider(): iterable
    {
        yield 'catchUpActive with zero' => ['catchUpActive', 0];
        yield 'catchUpActive with negative value' => ['catchUpActive', -1];
        yield 'boot with zero' => ['boot', 0];
        yield 'boot with negative value' => ['boot', -1];
        yield 'catchUpNextActive with zero' => ['catchUpNextActive', 0];
        yield 'bootNext with zero' => ['bootNext', 0];
    }

    #[DataProvider('invalidLimitDataProvider')]
    public function test_catch_up_methods_throw_for_a_limit_below_one(string $method, int $limit): void
    {
        $subscriptionEngine = $this->subscriptionEngine();

        try {
            $subscriptionEngine->{$method}(limit: $limit);
            self::fail('Expected exception');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString((string)$limit, $e->getMessage());
        }
        self::assertFalse($this->subscriptionStore->_isTransactionActive());
        $this->assertEmittedEngineEvents();
    }

    public function test_boot_with_limit_activates_subscriptions_in_one_run_if_backlog_equals_limit(): void
    {
        $subscriber = Subscriber::create(SubscriptionId::fromString('s1'), fn() => null);
        $this->subscriptionStore->_setSubscriptions(
            Subscription::create(id: 's1', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::BOOTING),
        );
        $this->eventStore->append('event at #1');
        $this->eventStore->append('event at #2');

        $result = $this->subscriptionEngine($subscriber)->boot(limit: 2);

        self::assertSame(2, $result->numberOfProcessedEvents);
        self::assertFalse($result->hasPendingEvents);
        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'ACTIVE', 'position' => 2, 'error' => null],
        );
    }

    public function test_catchUpActive_with_limit_counts_skipped_events(): void
    {
        $subscriber1 = Subscriber::create(SubscriptionId::fromString('s1'), fn() => null);
        $subscriber2Invocations = 0;
        $subscriber2 = Subscriber::create(SubscriptionId::fromString('s2'), function () use (&$subscriber2Invocations) {
            $subscriber2Invocations++;
        });
        $this->subscriptionStore->_setSubscriptions(
            Subscription::create(id: 's1', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::ACTIVE),
            Subscription::create(id: 's2', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::ACTIVE)->with(position: Position::fromInteger(3)),
        );
        $this->eventStore->append('event at #1');
        $this->eventStore->append('event at #2');
        $this->eventStore->append('event at #3');
        $this->eventStore->append('event at #4');

        $result = $this->subscriptionEngine($subscriber1, $subscriber2)->catchUpActive(limit: 2);

        self::assertSame(2, $result->numberOfProcessedEvents);
        self::assertTrue($result->hasPendingEvents);
        self::assertSame(0, $subscriber2Invocations);
        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'ACTIVE', 'position' => 2, 'error' => null],
            ['id' => 's2', 'status' => 'ACTIVE', 'position' => 3, 'error' => null],
        );
    }

    public function test_catchUpActive_with_limit_reports_pending_events_if_a_subscription_remains_after_failure(): void
    {
        $subscriber1 = Subscriber::create(SubscriptionId::fromString('s1'), function () {
            throw new \RuntimeException('Exception from s1');
        });
        $subscriber2 = Subscriber::create(SubscriptionId::fromString('s2'), fn() => null);
        $this->subscriptionStore->_setSubscriptions(
            Subscription::create(id: 's1', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::ACTIVE),
            Subscription::create(id: 's2', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::ACTIVE),
        );
        $this->eventStore->append('event at #1');
        $this->eventStore->append('event at #2');
        $this->eventStore->append('event at #3');

        $result = $this->subscriptionEngine($subscriber1, $subscriber2)->catchUpActive(limit: 2);

        self::assertFalse($result->successful());
        self::assertTrue($result->hasPendingEvents);
        $subscriptions = $this->subscriptionStore->findByCriteria(SubscriptionCriteria::noConstraints());
        self::assertSame('ERROR', $subscriptions->get(SubscriptionId::fromString('s1'))->status->value);
        self::assertSame(0, $subscriptions->get(SubscriptionId::fromString('s1'))->position->value);
        self::assertSame('ACTIVE', $subscriptions->get(SubscriptionId::fromString('s2'))->status->value);
        self::assertSame(2, $subscriptions->get(SubscriptionId::fromString('s2'))->position->value);
    }

    public function test_catchUpActive_with_limit_drains_the_event_log_when_called_in_a_loop(): void
    {
        $handledEvents = [];
        $subscriber = Subscriber::create(SubscriptionId::fromString('s1'), function (MockEvent $event) use (&$handledEvents) {
            $handledEvents[] = $event->position->value;
        });
        $this->subscriptionStore->_setSubscriptions(
            Subscription::create(id: 's1', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::ACTIVE),
        );
        for ($i = 1; $i <= 5; $i++) {
            $this->eventStore->append('event at #' . $i);
        }
        $subscriptionEngine = $this->subscriptionEngine($subscriber);

        $numberOfRuns = 0;
        do {
            $result = $subscriptionEngine->catchUpActive(limit: 2);
            $numberOfRuns++;
        } while ($result->hasPendingEvents);

        self::assertSame(3, $numberOfRuns);
        self::assertSame([1, 2, 3, 4, 5], $handledEvents);
    }

    public function test_catchUpActive_with_limit_requests_one_more_event_than_it_processes(): void
    {
        $this->subscriptionStore->_setSubscriptions(
            Subscription::create(id: 's1', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::ACTIVE),
        );
        $this->eventStore->append('event at #1');

        $this->subscriptionEngine(Subscriber::create(SubscriptionId::fromString('s1'), fn() => null))->catchUpActive(limit: 2);

        self::assertSame(3, $this->eventStore->_lastReadLimit());
    }

    public function test_catchUpActive_without_limit_reads_to_the_end_of_the_event_log(): void
    {
        $this->subscriptionStore->_setSubscriptions(
            Subscription::create(id: 's1', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::ACTIVE),
        );
        $this->eventStore->append('event at #1');
        $this->eventStore->append('event at #2');

        $result = $this->subscriptionEngine(Subscriber::create(SubscriptionId::fromString('s1'), fn() => null))->catchUpActive();

        self::assertNull($this->eventStore->_lastReadLimit());
        self::assertSame(2, $result->numberOfProcessedEvents);
        self::assertFalse($result->hasPendingEvents);
    }

    public function test_catchUpActive_with_limit_stops_at_limit_if_the_adapter_returns_more_events(): void
    {
        $this->eventStore = new InMemoryEventStoreAdapter(ignoreLimit: true);
        $this->eventStore->append('event at #1');
        $this->eventStore->append('event at #2');
        $this->eventStore->append('event at #3');
        $this->eventStore->append('event at #4');
        $this->subscriptionStore->_setSubscriptions(
            Subscription::create(id: 's1', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::ACTIVE),
        );

        $result = $this->subscriptionEngine(Subscriber::create(SubscriptionId::fromString('s1'), fn() => null))->catchUpActive(limit: 2);

        self::assertSame(2, $result->numberOfProcessedEvents);
        self::assertTrue($result->hasPendingEvents);
        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'ACTIVE', 'position' => 2, 'error' => null],
        );
    }

    public function test_catchUp_rolls_back_and_rethrows_if_an_exception_escapes(): void
    {
        $this->subscriptionStore->_setSubscriptions(
            Subscription::create(id: 's1', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::ACTIVE),
            Subscription::create(id: 'detached', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::ACTIVE),
        );
        $this->eventStore->append('event at #1');
        $subscriptionEngine = $this->subscriptionEngine(Subscriber::create(SubscriptionId::fromString('s1'), fn() => null));
        $exception = new \RuntimeException('Escaping exception');
        $subscriptionEngine->onEngineEvent(static function (EngineEvent $event) use ($exception) {
            if ($event instanceof SubscriberHandledEvent) {
                throw $exception;
            }
        });

        try {
            $subscriptionEngine->catchUpActive();
            self::fail('Expected exception');
        } catch (\RuntimeException $e) {
            self::assertSame($exception, $e);
        }

        self::assertFalse($this->subscriptionStore->_isTransactionActive());
        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'ACTIVE', 'position' => 0, 'error' => null],
            ['id' => 'detached', 'status' => 'ACTIVE', 'position' => 0, 'error' => null],
        );
    }

    public function test_catchUp_can_run_again_after_an_exception_escaped(): void
    {
        $handledEvents = [];
        $subscriber = Subscriber::create(SubscriptionId::fromString('s1'), function (MockEvent $event) use (&$handledEvents) {
            $handledEvents[] = $event->position->value;
        });
        $this->subscriptionStore->_setSubscriptions(
            Subscription::create(id: 's1', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::ACTIVE),
        );
        $this->eventStore->append('event at #1');
        $subscriptionEngine = $this->subscriptionEngine($subscriber);
        $fail = true;
        $subscriptionEngine->onEngineEvent(static function (EngineEvent $event) use (&$fail) {
            if ($fail && $event instanceof SubscriberHandledEvent) {
                throw new \RuntimeException('Escaping exception');
            }
        });
        try {
            $subscriptionEngine->catchUpActive();
            self::fail('Expected exception');
        } catch (\RuntimeException $e) {
            self::assertSame('Escaping exception', $e->getMessage());
        }
        $fail = false;

        $result = $subscriptionEngine->catchUpActive();

        self::assertTrue($result->successful());
        self::assertSame([1, 1], $handledEvents);
        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'ACTIVE', 'position' => 1, 'error' => null],
        );
    }

    public function test_catchUp_keeps_committed_changes_if_an_exception_escapes_after_the_commit(): void
    {
        $this->subscriptionStore->_setSubscriptions(
            Subscription::create(id: 's1', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::ACTIVE),
        );
        $this->eventStore->append('event at #1');
        $subscriptionEngine = $this->subscriptionEngine(Subscriber::create(SubscriptionId::fromString('s1'), fn() => null));
        $fail = true;
        $subscriptionEngine->onEngineEvent(static function (EngineEvent $event) use (&$fail) {
            if ($fail && $event instanceof CatchUpFinished) {
                throw new \RuntimeException('Exception from listener');
            }
        });
        try {
            $subscriptionEngine->catchUpActive();
            self::fail('Expected exception');
        } catch (\RuntimeException $e) {
            self::assertSame('Exception from listener', $e->getMessage());
        }
        $fail = false;

        self::assertFalse($this->subscriptionStore->_wasRollbackCalled());
        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'ACTIVE', 'position' => 1, 'error' => null],
        );
        self::assertSame(0, $subscriptionEngine->catchUpActive()->numberOfProcessedEvents);
    }

    public function test_catchUp_rolls_back_if_the_commit_fails(): void
    {
        $this->subscriptionStore->_setSubscriptions(
            Subscription::create(id: 's1', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::ACTIVE),
        );
        $this->eventStore->append('event at #1');
        $this->subscriptionStore->_failOnCommit();

        try {
            $this->subscriptionEngine(Subscriber::create(SubscriptionId::fromString('s1'), fn() => null))->catchUpActive();
            self::fail('Expected exception');
        } catch (\RuntimeException $e) {
            self::assertSame('Commit failed', $e->getMessage());
        }

        self::assertFalse($this->subscriptionStore->_isTransactionActive());
        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'ACTIVE', 'position' => 0, 'error' => null],
        );
    }

    public function test_catchUp_rethrows_the_original_exception_if_the_rollback_fails(): void
    {
        $this->subscriptionStore->_setSubscriptions(
            Subscription::create(id: 's1', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::ACTIVE),
        );
        $this->eventStore->append('event at #1');
        $this->subscriptionStore->_failOnRollback();
        $subscriptionEngine = $this->subscriptionEngine(Subscriber::create(SubscriptionId::fromString('s1'), fn() => null));
        $subscriptionEngine->onEngineEvent(static function (EngineEvent $event) {
            if ($event instanceof SubscriberHandledEvent) {
                throw new \RuntimeException('Escaping exception');
            }
        });

        $this->expectExceptionMessage('Escaping exception');
        $subscriptionEngine->catchUpActive();
    }

    public function test_catchUp_stops_and_rolls_back_if_the_store_fails_to_record_a_failed_subscriber(): void
    {
        $this->subscriptionStore->_setSubscriptions(
            Subscription::create(id: 's1', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::ACTIVE),
            Subscription::create(id: 's2', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::ACTIVE),
        );
        $this->eventStore->append('event at #1');
        $this->eventStore->append('event at #2');
        $subscriber1 = Subscriber::create(SubscriptionId::fromString('s1'), function () {
            throw new \RuntimeException('Deadlock');
        });
        $subscriber2Invocations = 0;
        $subscriber2 = Subscriber::create(SubscriptionId::fromString('s2'), function () use (&$subscriber2Invocations) {
            $subscriber2Invocations++;
        });
        $this->subscriptionStore->_failOnUpdate();

        try {
            $this->subscriptionEngine($subscriber1, $subscriber2)->catchUpActive();
            self::fail('Expected exception');
        } catch (\RuntimeException $e) {
            self::assertSame('Update failed', $e->getMessage());
        }

        self::assertSame(0, $subscriber2Invocations);
        self::assertTrue($this->subscriptionStore->_wasRollbackCalled());
        self::assertFalse($this->subscriptionStore->_isTransactionActive());
    }

    public function test_catchUpNextActive_catches_up_only_the_subscription_with_the_lowest_position(): void
    {
        $this->eventStore->append('event at #1');
        $this->eventStore->append('event at #2');
        $this->eventStore->append('event at #3');
        $this->subscriptionStore->_setSubscriptions(
            self::subscriptionAt('s1', SubscriptionStatus::ACTIVE, 2),
            self::subscriptionAt('s2', SubscriptionStatus::ACTIVE, 1),
        );

        $result = $this->subscriptionEngine(
            Subscriber::create(SubscriptionId::fromString('s1'), fn() => null),
            Subscriber::create(SubscriptionId::fromString('s2'), fn() => null),
        )->catchUpNextActive();

        self::assertSame(2, $result->numberOfProcessedEvents);
        self::assertTrue($result->successful());
        self::assertFalse($this->subscriptionStore->_isTransactionActive());
        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'ACTIVE', 'position' => 2, 'error' => null],
            ['id' => 's2', 'status' => 'ACTIVE', 'position' => 3, 'error' => null],
        );
    }

    public function test_catchUpNextActive_skips_subscriptions_that_are_locked_by_another_process(): void
    {
        $this->eventStore->append('event at #1');
        $this->subscriptionStore->_setSubscriptions(
            self::subscriptionAt('s1', SubscriptionStatus::ACTIVE, 0),
            self::subscriptionAt('s2', SubscriptionStatus::ACTIVE, 0),
        );
        $this->subscriptionStore->_lock('s1');

        $result = $this->subscriptionEngine(
            Subscriber::create(SubscriptionId::fromString('s1'), fn() => null),
            Subscriber::create(SubscriptionId::fromString('s2'), fn() => null),
        )->catchUpNextActive();

        self::assertSame(1, $result->numberOfProcessedEvents);
        self::assertFalse($result->allSubscriptionsLocked);
        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'ACTIVE', 'position' => 0, 'error' => null],
            ['id' => 's2', 'status' => 'ACTIVE', 'position' => 1, 'error' => null],
        );
    }

    public function test_catchUpNextActive_reports_if_all_subscriptions_are_locked(): void
    {
        $this->eventStore->append('event at #1');
        $this->subscriptionStore->_setSubscriptions(self::subscriptionAt('s1', SubscriptionStatus::ACTIVE, 0));
        $this->subscriptionStore->_lock('s1');

        $result = $this->subscriptionEngine(Subscriber::create(SubscriptionId::fromString('s1'), fn() => null))->catchUpNextActive();

        self::assertTrue($result->successful());
        self::assertTrue($result->allSubscriptionsLocked);
        self::assertFalse($result->hasPendingEvents);
        self::assertSame(0, $result->numberOfProcessedEvents);
        self::assertFalse($this->subscriptionStore->_isTransactionActive());
        $this->assertEmittedEngineEvents(
            'CatchUpInitiated: Initiated catch-up of subscriptions in states ACTIVE',
            'AllSubscriptionsLocked: All subscriptions are locked for criteria: {"ids":null,"status":["ACTIVE"]}',
        );
    }

    public function test_catchUpNextActive_does_not_report_locks_if_no_subscription_matches(): void
    {
        $this->subscriptionStore->_setSubscriptions(self::subscriptionAt('s1', SubscriptionStatus::BOOTING, 0));

        $result = $this->subscriptionEngine(Subscriber::create(SubscriptionId::fromString('s1'), fn() => null))->catchUpNextActive();

        self::assertTrue($result->successful());
        self::assertFalse($result->allSubscriptionsLocked);
        self::assertFalse($this->subscriptionStore->_isTransactionActive());
        $this->assertEmittedEngineEvents(
            'CatchUpInitiated: Initiated catch-up of subscriptions in states ACTIVE',
            'NoSubscriptionsFound: No subscriptions found for criteria: {"ids":null,"status":["ACTIVE"]}',
        );
    }

    public function test_catchUpActive_does_not_report_locks(): void
    {
        $result = $this->subscriptionEngine()->catchUpActive();

        self::assertFalse($result->allSubscriptionsLocked);
    }


    public function test_catchUpNextActive_reports_pending_events_of_other_subscriptions(): void
    {
        $this->eventStore->append('event at #1');
        $this->eventStore->append('event at #2');
        $this->eventStore->append('event at #3');
        $this->subscriptionStore->_setSubscriptions(
            self::subscriptionAt('s1', SubscriptionStatus::ACTIVE, 1),
            self::subscriptionAt('s2', SubscriptionStatus::ACTIVE, 2),
        );
        $subscriptionEngine = $this->subscriptionEngine(
            Subscriber::create(SubscriptionId::fromString('s1'), fn() => null),
            Subscriber::create(SubscriptionId::fromString('s2'), fn() => null),
        );

        self::assertTrue($subscriptionEngine->catchUpNextActive()->hasPendingEvents);
        self::assertFalse($subscriptionEngine->catchUpNextActive()->hasPendingEvents);
        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'ACTIVE', 'position' => 3, 'error' => null],
            ['id' => 's2', 'status' => 'ACTIVE', 'position' => 3, 'error' => null],
        );
    }

    public function test_catchUpNextActive_reports_pending_events_of_the_claimed_subscription_if_the_limit_was_reached(): void
    {
        $this->eventStore->append('event at #1');
        $this->eventStore->append('event at #2');
        $this->subscriptionStore->_setSubscriptions(self::subscriptionAt('s1', SubscriptionStatus::ACTIVE, 0));

        $result = $this->subscriptionEngine(Subscriber::create(SubscriptionId::fromString('s1'), fn() => null))->catchUpNextActive(limit: 1);

        self::assertSame(1, $result->numberOfProcessedEvents);
        self::assertTrue($result->hasPendingEvents);
    }

    public function test_catchUpNextActive_reports_pending_events_of_locked_subscriptions_that_are_behind(): void
    {
        $this->eventStore->append('event at #1');
        $this->eventStore->append('event at #2');
        $this->subscriptionStore->_setSubscriptions(
            self::subscriptionAt('s1', SubscriptionStatus::ACTIVE, 0),
            self::subscriptionAt('s2', SubscriptionStatus::ACTIVE, 1),
        );
        $this->subscriptionStore->_lock('s2');

        $result = $this->subscriptionEngine(
            Subscriber::create(SubscriptionId::fromString('s1'), fn() => null),
            Subscriber::create(SubscriptionId::fromString('s2'), fn() => null),
        )->catchUpNextActive();

        self::assertSame(2, $result->numberOfProcessedEvents);
        self::assertTrue($result->hasPendingEvents);
    }

    public function test_catchUpNextActive_reports_no_pending_events_if_the_claimed_subscription_had_no_events(): void
    {
        $this->eventStore->append('event at #1');
        $this->subscriptionStore->_setSubscriptions(
            self::subscriptionAt('s1', SubscriptionStatus::ACTIVE, 1),
            self::subscriptionAt('s2', SubscriptionStatus::ACTIVE, 0),
        );
        $this->subscriptionStore->_lock('s2');

        $result = $this->subscriptionEngine(
            Subscriber::create(SubscriptionId::fromString('s1'), fn() => null),
            Subscriber::create(SubscriptionId::fromString('s2'), fn() => null),
        )->catchUpNextActive();

        self::assertSame(0, $result->numberOfProcessedEvents);
        self::assertFalse($result->hasPendingEvents);
        self::assertFalse($result->allSubscriptionsLocked);
    }

    public function test_catchUpNextActive_does_not_count_a_subscription_that_went_to_error_as_pending(): void
    {
        $this->eventStore->append('event at #1');
        $this->eventStore->append('event at #2');
        $this->subscriptionStore->_setSubscriptions(self::subscriptionAt('s1', SubscriptionStatus::ACTIVE, 0));

        $result = $this->subscriptionEngine(Subscriber::create(SubscriptionId::fromString('s1'), static function () {
            throw new \RuntimeException('Just testing');
        }))->catchUpNextActive(limit: 1);

        self::assertFalse($result->successful());
        self::assertFalse($result->hasPendingEvents);
        self::assertSame('s1', iterator_to_array($result->errors)[0]->subscriptionId->value);
    }

    public function test_catchUpNextActive_reports_pending_events_of_others_if_the_claimed_subscription_went_to_error(): void
    {
        $this->eventStore->append('event at #1');
        $this->subscriptionStore->_setSubscriptions(
            self::subscriptionAt('s1', SubscriptionStatus::ACTIVE, 0),
            self::subscriptionAt('s2', SubscriptionStatus::ACTIVE, 0),
        );

        $result = $this->subscriptionEngine(
            Subscriber::create(SubscriptionId::fromString('s1'), static function () {
                throw new \RuntimeException('Just testing');
            }),
            Subscriber::create(SubscriptionId::fromString('s2'), fn() => null),
        )->catchUpNextActive();

        self::assertFalse($result->successful());
        self::assertTrue($result->hasPendingEvents);
    }

    public function test_catchUpNextActive_emits_engine_events_for_the_claimed_subscription(): void
    {
        $this->eventStore->append('event at #1');
        $this->subscriptionStore->_setSubscriptions(
            self::subscriptionAt('s1', SubscriptionStatus::ACTIVE, 0),
            self::subscriptionAt('s2', SubscriptionStatus::ACTIVE, 1),
        );

        $this->subscriptionEngine(
            Subscriber::create(SubscriptionId::fromString('s1'), fn() => null),
            Subscriber::create(SubscriptionId::fromString('s2'), fn() => null),
        )->catchUpNextActive();

        $this->assertEmittedEngineEvents(
            'CatchUpInitiated: Initiated catch-up of subscriptions in states ACTIVE',
            'CatchUpStarted: Starting catch-up of 1 subscription from position 1',
            'SubscriberHandledEvent: Subscriber for "s1" processed event "Wwwision\SubscriptionEngine\Tests\Mocks\MockEvent" (position: 1)',
            'CatchUpFinished: Finished catch-up of 1 subscription, processed 1 event (no errors)',
        );
    }

    public function test_bootNext_activates_a_subscription_as_soon_as_it_reached_the_end_of_the_event_log(): void
    {
        $this->eventStore->append('event at #1');
        $this->subscriptionStore->_setSubscriptions(
            self::subscriptionAt('s1', SubscriptionStatus::BOOTING, 0),
            self::subscriptionAt('s2', SubscriptionStatus::BOOTING, 0),
            self::subscriptionAt('s3', SubscriptionStatus::ACTIVE, 0),
        );

        $result = $this->subscriptionEngine(
            Subscriber::create(SubscriptionId::fromString('s1'), fn() => null),
            Subscriber::create(SubscriptionId::fromString('s2'), fn() => null),
            Subscriber::create(SubscriptionId::fromString('s3'), fn() => null),
        )->bootNext();

        self::assertTrue($result->hasPendingEvents);
        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'ACTIVE', 'position' => 1, 'error' => null],
            ['id' => 's2', 'status' => 'BOOTING', 'position' => 0, 'error' => null],
            ['id' => 's3', 'status' => 'ACTIVE', 'position' => 0, 'error' => null],
        );
    }

    public function test_bootNext_keeps_a_partially_booted_subscription_booting(): void
    {
        $this->eventStore->append('event at #1');
        $this->eventStore->append('event at #2');
        $this->subscriptionStore->_setSubscriptions(self::subscriptionAt('s1', SubscriptionStatus::BOOTING, 0));

        $result = $this->subscriptionEngine(Subscriber::create(SubscriptionId::fromString('s1'), fn() => null))->bootNext(limit: 1);

        self::assertTrue($result->hasPendingEvents);
        $this->assertSubscriptions(['id' => 's1', 'status' => 'BOOTING', 'position' => 1, 'error' => null]);
    }

    public function test_bootNext_reports_booting_subscriptions_at_the_end_of_the_event_log_as_pending(): void
    {
        $this->subscriptionStore->_setSubscriptions(
            self::subscriptionAt('s1', SubscriptionStatus::BOOTING, 0),
            self::subscriptionAt('s2', SubscriptionStatus::BOOTING, 0),
        );
        $subscriptionEngine = $this->subscriptionEngine(
            Subscriber::create(SubscriptionId::fromString('s1'), fn() => null),
            Subscriber::create(SubscriptionId::fromString('s2'), fn() => null),
        );

        self::assertTrue($subscriptionEngine->bootNext()->hasPendingEvents);
        self::assertFalse($subscriptionEngine->bootNext()->hasPendingEvents);
        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'ACTIVE', 'position' => 0, 'error' => null],
            ['id' => 's2', 'status' => 'ACTIVE', 'position' => 0, 'error' => null],
        );
    }


    public function test_catchUpNextActive_detaches_subscriptions_without_subscriber_and_claims_the_next_one(): void
    {
        $this->eventStore->append('event at #1');
        $this->subscriptionStore->_setSubscriptions(
            self::subscriptionAt('removed', SubscriptionStatus::ACTIVE, 0),
            self::subscriptionAt('s1', SubscriptionStatus::ACTIVE, 0),
        );

        $result = $this->subscriptionEngine(Subscriber::create(SubscriptionId::fromString('s1'), fn() => null))->catchUpNextActive();

        self::assertSame(1, $result->numberOfProcessedEvents);
        $this->assertSubscriptions(
            ['id' => 'removed', 'status' => 'DETACHED', 'position' => 0, 'error' => null],
            ['id' => 's1', 'status' => 'ACTIVE', 'position' => 1, 'error' => null],
        );
    }

    public function test_catchUpNextActive_does_not_report_locks_if_all_claimed_subscriptions_were_detached(): void
    {
        $this->subscriptionStore->_setSubscriptions(self::subscriptionAt('removed', SubscriptionStatus::ACTIVE, 0));

        $result = $this->subscriptionEngine()->catchUpNextActive();

        self::assertFalse($result->allSubscriptionsLocked);
        self::assertFalse($this->subscriptionStore->_isTransactionActive());
        $this->assertSubscriptions(['id' => 'removed', 'status' => 'DETACHED', 'position' => 0, 'error' => null]);
    }

    /**
     * @return iterable<string, array{string, array<string>|null, SubscriptionStatus}>
     */
    public static function nextMethodsWithOverlappingCriteriaDataProvider(): iterable
    {
        yield 'catchUpNextActive without ids' => ['catchUpNextActive', null, SubscriptionStatus::ACTIVE];
        yield 'catchUpNextActive with overlapping ids' => ['catchUpNextActive', ['s1'], SubscriptionStatus::ACTIVE];
        yield 'bootNext without ids' => ['bootNext', null, SubscriptionStatus::BOOTING];
        yield 'bootNext with overlapping ids' => ['bootNext', ['s1'], SubscriptionStatus::BOOTING];
    }

    #[DataProvider('nextMethodsWithOverlappingCriteriaDataProvider')]
    public function test_next_methods_throw_if_called_recursively_for_the_subscription_being_processed(string $method, array|null $ids, SubscriptionStatus $status): void
    {
        $this->eventStore->append('event at #1');
        $this->subscriptionStore->_setSubscriptions(self::subscriptionAt('s1', $status, 0));
        $caught = null;
        $subscriptionEngine = null;
        $subscriber = Subscriber::create(SubscriptionId::fromString('s1'), static function () use (&$subscriptionEngine, &$caught, $method, $ids) {
            try {
                $subscriptionEngine->{$method}($ids !== null ? SubscriptionEngineCriteria::create($ids) : null);
            } catch (RecursiveCatchUpException $e) {
                $caught = $e;
            }
        });
        $subscriptionEngine = $this->subscriptionEngine($subscriber);

        $subscriptionEngine->{$method}();

        self::assertInstanceOf(RecursiveCatchUpException::class, $caught);
    }

    public function test_catchUpNextActive_allows_a_nested_call_for_other_subscriptions(): void
    {
        $this->eventStore->append('event at #1');
        $this->subscriptionStore->_setSubscriptions(
            self::subscriptionAt('s1', SubscriptionStatus::ACTIVE, 0),
            self::subscriptionAt('s2', SubscriptionStatus::ACTIVE, 0),
        );
        $subscriptionEngine = null;
        $subscriber1 = Subscriber::create(SubscriptionId::fromString('s1'), static function () use (&$subscriptionEngine) {
            $subscriptionEngine->catchUpNextActive(SubscriptionEngineCriteria::create(['s2']));
        });
        $subscriptionEngine = $this->subscriptionEngine($subscriber1, Subscriber::create(SubscriptionId::fromString('s2'), fn() => null));

        $result = $subscriptionEngine->catchUpNextActive();

        self::assertTrue($result->successful());
        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'ACTIVE', 'position' => 1, 'error' => null],
            ['id' => 's2', 'status' => 'ACTIVE', 'position' => 1, 'error' => null],
        );
    }

    public function test_next_methods_throw_for_unknown_subscription_ids(): void
    {
        $this->expectException(UnknownSubscriptionIdsException::class);
        $this->subscriptionEngine()->catchUpNextActive(SubscriptionEngineCriteria::create(['unknown']));
    }


    public function test_catchUpNextActive_returns_a_failed_result_and_rolls_back_if_the_run_fails(): void
    {
        $this->eventStore->append('event at #1');
        $this->subscriptionStore->_setSubscriptions(self::subscriptionAt('s1', SubscriptionStatus::ACTIVE, 0));
        $this->subscriptionStore->_failOnUpdateOf('s1');

        $result = $this->subscriptionEngine($this->poisonedSubscriber('s1'))->catchUpNextActive();

        self::assertFalse($result->successful());
        self::assertSame(0, $result->numberOfProcessedEvents);
        self::assertFalse($result->hasPendingEvents);
        $error = iterator_to_array($result->errors)[0];
        self::assertSame('s1', $error->subscriptionId->value);
        self::assertSame('Update of s1 failed', $error->message);
        self::assertInstanceOf(\RuntimeException::class, $error->throwable);
        self::assertTrue($this->subscriptionStore->_wasRollbackCalled());
        self::assertFalse($this->subscriptionStore->_isTransactionActive());
        $this->assertSubscriptions(['id' => 's1', 'status' => 'ACTIVE', 'position' => 0, 'error' => null]);
        $this->assertEmittedEngineEvents(
            'CatchUpInitiated: Initiated catch-up of subscriptions in states ACTIVE',
            'CatchUpStarted: Starting catch-up of 1 subscription from position 1',
            'SubscriberFailedToHandleEvent: Subscriber for "s1" failed to process event "Wwwision\SubscriptionEngine\Tests\Mocks\MockEvent" (position: 1): Poisoned',
            'CatchUpRunFailed: Catch-up of subscription "s1" failed and was rolled back: Update of s1 failed',
        );
    }

    public function test_catchUpNextActive_returns_a_failed_result_if_the_commit_fails(): void
    {
        $this->subscriptionStore->_setSubscriptions(self::subscriptionAt('s1', SubscriptionStatus::ACTIVE, 0));
        $this->subscriptionStore->_failOnCommit();

        $result = $this->subscriptionEngine(Subscriber::create(SubscriptionId::fromString('s1'), fn() => null))->catchUpNextActive();

        self::assertFalse($result->successful());
        self::assertFalse($this->subscriptionStore->_isTransactionActive());
    }

    public function test_catchUpNextActive_lets_a_failing_subscription_not_starve_the_others(): void
    {
        $this->eventStore->append('event at #1');
        $this->eventStore->append('event at #2');
        $this->subscriptionStore->_setSubscriptions(
            self::subscriptionAt('poison', SubscriptionStatus::ACTIVE, 0),
            self::subscriptionAt('s1', SubscriptionStatus::ACTIVE, 1),
            self::subscriptionAt('s2', SubscriptionStatus::ACTIVE, 1),
        );
        $this->subscriptionStore->_failOnUpdateOf('poison');
        $subscriptionEngine = $this->subscriptionEngine(
            $this->poisonedSubscriber('poison'),
            Subscriber::create(SubscriptionId::fromString('s1'), fn() => null),
            Subscriber::create(SubscriptionId::fromString('s2'), fn() => null),
        );

        $failures = 0;
        do {
            $result = $subscriptionEngine->catchUpNextActive();
            $failures += $result->successful() ? 0 : 1;
        } while ($result->hasPendingEvents);

        self::assertSame(1, $failures);
        $this->assertSubscriptions(
            ['id' => 'poison', 'status' => 'ACTIVE', 'position' => 0, 'error' => null],
            ['id' => 's1', 'status' => 'ACTIVE', 'position' => 2, 'error' => null],
            ['id' => 's2', 'status' => 'ACTIVE', 'position' => 2, 'error' => null],
        );
    }

    public function test_catchUpNextActive_retries_a_failed_subscription_once_the_others_have_no_work(): void
    {
        $this->eventStore->append('event at #1');
        $this->subscriptionStore->_setSubscriptions(
            self::subscriptionAt('flaky', SubscriptionStatus::ACTIVE, 0),
            self::subscriptionAt('s1', SubscriptionStatus::ACTIVE, 1),
        );
        $this->subscriptionStore->_failOnUpdateOf('flaky');
        $attempts = 0;
        $flaky = Subscriber::create(SubscriptionId::fromString('flaky'), function () use (&$attempts) {
            $attempts++;
            if ($attempts === 1) {
                throw new \RuntimeException('Temporary');
            }
            $this->subscriptionStore->_failOnUpdateOf();
        });
        $subscriptionEngine = $this->subscriptionEngine($flaky, Subscriber::create(SubscriptionId::fromString('s1'), fn() => null));

        self::assertFalse($subscriptionEngine->catchUpNextActive()->successful());
        self::assertSame(0, $subscriptionEngine->catchUpNextActive()->numberOfProcessedEvents, 'Claims s1, which has no work');
        self::assertSame(1, $attempts);
        $result = $subscriptionEngine->catchUpNextActive();

        self::assertTrue($result->successful());
        self::assertSame(2, $attempts);
        $this->assertSubscriptions(
            ['id' => 'flaky', 'status' => 'ACTIVE', 'position' => 1, 'error' => null],
            ['id' => 's1', 'status' => 'ACTIVE', 'position' => 1, 'error' => null],
        );
    }

    public function test_catchUpNextActive_retries_a_failed_subscription_right_away_if_nothing_else_matches(): void
    {
        $this->eventStore->append('event at #1');
        $this->subscriptionStore->_setSubscriptions(self::subscriptionAt('poison', SubscriptionStatus::ACTIVE, 0));
        $this->subscriptionStore->_failOnUpdateOf('poison');
        $attempts = 0;
        $subscriptionEngine = $this->subscriptionEngine(Subscriber::create(SubscriptionId::fromString('poison'), static function () use (&$attempts) {
            $attempts++;
            throw new \RuntimeException('Poisoned');
        }));

        $subscriptionEngine->catchUpNextActive();
        $subscriptionEngine->catchUpNextActive();

        self::assertSame(2, $attempts);
    }

    public function test_an_idle_boot_does_not_retry_active_subscriptions_that_were_set_aside(): void
    {
        $this->eventStore->append('event at #1');
        $this->subscriptionStore->_setSubscriptions(
            self::subscriptionAt('poison', SubscriptionStatus::ACTIVE, 0),
            self::subscriptionAt('s1', SubscriptionStatus::ACTIVE, 0),
        );
        $this->subscriptionStore->_failOnUpdateOf('poison');
        $subscriptionEngine = $this->subscriptionEngine($this->poisonedSubscriber('poison'), Subscriber::create(SubscriptionId::fromString('s1'), fn() => null));

        $subscriptionEngine->catchUpNextActive();
        $subscriptionEngine->bootNext();
        $result = $subscriptionEngine->catchUpNextActive();

        self::assertTrue($result->successful());
        $this->assertSubscriptions(
            ['id' => 'poison', 'status' => 'ACTIVE', 'position' => 0, 'error' => null],
            ['id' => 's1', 'status' => 'ACTIVE', 'position' => 1, 'error' => null],
        );
    }

    public function test_an_idle_run_only_retries_failed_subscriptions_of_its_criteria(): void
    {
        $this->eventStore->append('event at #1');
        $this->subscriptionStore->_setSubscriptions(
            self::subscriptionAt('poison', SubscriptionStatus::ACTIVE, 0),
            self::subscriptionAt('s1', SubscriptionStatus::ACTIVE, 0),
            self::subscriptionAt('idle', SubscriptionStatus::ACTIVE, 1),
        );
        $this->subscriptionStore->_failOnUpdateOf('poison');
        $subscriptionEngine = $this->subscriptionEngine(
            $this->poisonedSubscriber('poison'),
            Subscriber::create(SubscriptionId::fromString('s1'), fn() => null),
            Subscriber::create(SubscriptionId::fromString('idle'), fn() => null),
        );

        $subscriptionEngine->catchUpNextActive();
        self::assertSame(0, $subscriptionEngine->catchUpNextActive(SubscriptionEngineCriteria::create(['idle']))->numberOfProcessedEvents);
        $result = $subscriptionEngine->catchUpNextActive();

        self::assertTrue($result->successful());
        $this->assertSubscriptions(
            ['id' => 'poison', 'status' => 'ACTIVE', 'position' => 0, 'error' => null],
            ['id' => 's1', 'status' => 'ACTIVE', 'position' => 1, 'error' => null],
            ['id' => 'idle', 'status' => 'ACTIVE', 'position' => 1, 'error' => null],
        );
    }

    public function test_bootNext_sets_failed_subscriptions_aside_and_does_not_report_them_as_pending(): void
    {
        $this->subscriptionStore->_setSubscriptions(self::subscriptionAt('poison', SubscriptionStatus::BOOTING, 0));
        $this->eventStore->append('event at #1');
        $this->subscriptionStore->_failOnUpdateOf('poison');

        $result = $this->subscriptionEngine($this->poisonedSubscriber('poison'))->bootNext();

        self::assertFalse($result->successful());
        self::assertFalse($result->hasPendingEvents);
        $this->assertSubscriptions(['id' => 'poison', 'status' => 'BOOTING', 'position' => 0, 'error' => null]);
    }

    public function test_catchUpNextActive_rethrows_if_the_transaction_can_not_be_started(): void
    {
        $this->subscriptionStore->_failOnBeginTransaction();

        $this->expectExceptionMessage('Begin failed');
        $this->subscriptionEngine()->catchUpNextActive();
    }

    public function test_catchUpNextActive_rolls_back_and_rethrows_if_the_claim_fails(): void
    {
        $this->subscriptionStore->_failOnClaim();

        try {
            $this->subscriptionEngine()->catchUpNextActive();
            self::fail('Expected exception');
        } catch (\RuntimeException $e) {
            self::assertSame('Claim failed', $e->getMessage());
        }
        self::assertTrue($this->subscriptionStore->_wasRollbackCalled());
        self::assertFalse($this->subscriptionStore->_isTransactionActive());
    }

    public function test_catchUpNextActive_rethrows_exceptions_of_listeners_after_the_commit(): void
    {
        $this->eventStore->append('event at #1');
        $this->subscriptionStore->_setSubscriptions(self::subscriptionAt('s1', SubscriptionStatus::ACTIVE, 0));
        $subscriptionEngine = $this->subscriptionEngine(Subscriber::create(SubscriptionId::fromString('s1'), fn() => null));
        $subscriptionEngine->onEngineEvent(static function (EngineEvent $event) {
            if ($event instanceof CatchUpFinished) {
                throw new \RuntimeException('Listener failed');
            }
        });

        try {
            $subscriptionEngine->catchUpNextActive();
            self::fail('Expected exception');
        } catch (\RuntimeException $e) {
            self::assertSame('Listener failed', $e->getMessage());
        }
        self::assertFalse($this->subscriptionStore->_wasRollbackCalled());
        $this->assertSubscriptions(['id' => 's1', 'status' => 'ACTIVE', 'position' => 1, 'error' => null]);
    }

    public function test_catchUpActive_still_rethrows_if_the_run_fails(): void
    {
        $this->eventStore->append('event at #1');
        $this->subscriptionStore->_setSubscriptions(self::subscriptionAt('s1', SubscriptionStatus::ACTIVE, 0));
        $this->subscriptionStore->_failOnUpdateOf('s1');

        $this->expectExceptionMessage('Update of s1 failed');
        $this->subscriptionEngine($this->poisonedSubscriber('s1'))->catchUpActive();
    }

    private function poisonedSubscriber(string $id): Subscriber
    {
        return Subscriber::create(SubscriptionId::fromString($id), static function () {
            throw new \RuntimeException('Poisoned');
        });
    }


    private static function subscriptionAt(string $id, SubscriptionStatus $status, int $position): Subscription
    {
        return Subscription::create(id: $id, runMode: RunMode::FROM_BEGINNING, status: $status)->with(position: Position::fromInteger($position));
    }

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
        $this->emittedEngineEvents = [];
    }

    private function assertSubscriptions(array ...$subscriptions): void
    {
        self::assertSame($subscriptions, $this->subscriptionStore->findByCriteria(SubscriptionCriteria::noConstraints())->map(static fn(Subscription $subscription) => [
            'id' => $subscription->id->value,
            'status' => $subscription->status->value,
            'position' => $subscription->position->value,
            'error' => $subscription->error->errorMessage,
        ]));
    }
}
