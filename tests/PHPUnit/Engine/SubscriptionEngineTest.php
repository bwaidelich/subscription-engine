<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Tests\PHPUnit\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Wwwision\SubscriptionEngine\Engine\Event\EngineEvent;
use Wwwision\SubscriptionEngine\Engine\Event\NoSubscriptionsFound;
use Wwwision\SubscriptionEngine\Engine\SubscriptionEngine;
use Wwwision\SubscriptionEngine\EventStore\Event;
use Wwwision\SubscriptionEngine\EventStore\EventStore;
use Wwwision\SubscriptionEngine\Store\SubscriptionCriteria;
use Wwwision\SubscriptionEngine\Subscriber\EventHandler;
use Wwwision\SubscriptionEngine\Subscriber\ProvidesSetup;
use Wwwision\SubscriptionEngine\Subscriber\Subscriber;
use Wwwision\SubscriptionEngine\Subscriber\Subscribers;
use Wwwision\SubscriptionEngine\Subscription\Position;
use Wwwision\SubscriptionEngine\Subscription\RunMode;
use Wwwision\SubscriptionEngine\Subscription\Subscription;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionId;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionStatus;
use Wwwision\SubscriptionEngine\Tests\Mocks\InMemoryEventStore;
use Wwwision\SubscriptionEngine\Tests\Mocks\InMemorySubscriptionStore;

#[CoversClass(SubscriptionEngine::class)]
final class SubscriptionEngineTest extends TestCase
{
    private InMemoryEventStore $eventStore;

    private InMemorySubscriptionStore $subscriptionStore;

    private Subscribers $subscribers;

    /**
     * @var array<EngineEvent>
     */
    private array $emittedEngineEvents = [];

    protected function setUp(): void
    {
        $this->subscribers = Subscribers::none();
        $this->eventStore = new InMemoryEventStore();
        $this->subscriptionStore = new InMemorySubscriptionStore();
    }

    public function test_setup_invokes_subscriptionStore_setup(): void
    {
        $this->subscriptionEngine()->setup();

        self::assertTrue($this->subscriptionStore->setupCalled());

        $this->assertEmittedEngineEvents(
            'SetupStarted: Start to setup',
            'NoSubscriptionsFound: No subscriptions found for criteria: {"ids":null,"status":["NEW","BOOTING","ACTIVE"]}',
        );
    }

    public function test_setup_detaches_subscriptions_without_subscriber(): void
    {
        $this->subscriptionStore->add(Subscription::create(id: 's1', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::ACTIVE));
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
        $this->subscriptionStore->add(Subscription::create(id: 's1', runMode: RunMode::FROM_BEGINNING, status: SubscriptionStatus::ACTIVE));
        $handler = new class implements EventHandler, ProvidesSetup {
            public function __invoke(Event $event): void { /* no-op */ }

            public function setup(): void {
                throw new \Error('Just testing');
            }
        };
        $this->subscribers = $this->subscribers->with(new Subscriber(SubscriptionId::fromString('s1'), RunMode::FROM_NOW, $handler));
        $result = $this->subscriptionEngine()->setup();

        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'ERROR', 'position' => 0, 'error' => 'Just testing'],
        );

        $this->assertEmittedEngineEvents(
            'SetupStarted: Start to setup',
            'FailedToSetupSubscriber: Subscriber "' . $handler::class . '" for "s1" has an error in the setup method: Just testing'
        );
        self::assertFalse($result->successful());
    }

    public function test_setup_discovers_new_subscriptions(): void
    {
        $this->eventStore->append('event at #1');
        $this->eventStore->append('event at #2');
        $handler = $this->createMock(EventHandler::class);
        $this->subscribers = $this->subscribers->with(new Subscriber(SubscriptionId::fromString('s1'), RunMode::FROM_BEGINNING, $handler));
        $result = $this->subscriptionEngine()->setup();

        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'BOOTING', 'position' => 0, 'error' => null],
        );

        $this->assertEmittedEngineEvents(
            'SetupStarted: Start to setup',
            'SubscriptionDiscovered: New Subscriber "s1" was found and added to the subscription store',
        );
        self::assertTrue($result->successful());
    }

    public function test_setup_marks_new_from_now_subscriptions_active(): void
    {
        $this->eventStore->append('event at #1');
        $this->eventStore->append('event at #2');
        $this->eventStore->append('event at #3');
        $handler = $this->createMock(EventHandler::class);
        $this->subscribers = $this->subscribers->with(new Subscriber(SubscriptionId::fromString('s1'), RunMode::FROM_NOW, $handler));
        $result = $this->subscriptionEngine()->setup();

        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'ACTIVE', 'position' => 3, 'error' => null],
        );

        $this->assertEmittedEngineEvents(
            'SetupStarted: Start to setup',
            'SubscriptionDiscovered: New Subscriber "s1" was found and added to the subscription store',
        );
        self::assertTrue($result->successful());
    }

    public function test_setup_does_not_change_subscriptionstate_of_active_subscriptions(): void
    {
        $this->subscriptionStore->add(Subscription::create(id: 's1', runMode: RunMode::FROM_NOW, status: SubscriptionStatus::ACTIVE)->with(position: Position::fromInteger(1)));
        $this->eventStore->append('event at #1');
        $this->eventStore->append('event at #2');
        $this->eventStore->append('event at #3');
        $handler = new class implements EventHandler, ProvidesSetup {

            public bool $setupWasCalled = false;
            public function __invoke(Event $event): void { /* no-op */ }

            public function setup(): void {
                $this->setupWasCalled = true;
            }
        };
        $this->subscribers = $this->subscribers->with(new Subscriber(SubscriptionId::fromString('s1'), RunMode::FROM_NOW, $handler));
        $result = $this->subscriptionEngine()->setup();

        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'ACTIVE', 'position' => 1, 'error' => null],
        );

        $this->assertEmittedEngineEvents(
            'SetupStarted: Start to setup',
            'ActiveSubscriptionSetup: Active subscriber "' . $handler::class . '" for "s1" has been re-setup',
        );
        self::assertTrue($result->successful());
        self::assertTrue($handler->setupWasCalled);
    }

    public function test_boot_detaches_subscriptions_without_subscriber(): void
    {
        $this->subscriptionStore->add(Subscription::create(id: 's1', runMode: RunMode::FROM_NOW, status: SubscriptionStatus::BOOTING)->with(position: Position::fromInteger(1)));
        $this->subscriptionStore->add(Subscription::create(id: 's2', runMode: RunMode::FROM_NOW, status: SubscriptionStatus::BOOTING)->with(position: Position::fromInteger(1)));
        $this->eventStore->append('event at #1');
        $this->eventStore->append('event at #2');
        $this->eventStore->append('event at #3');
        $handler = $this->createMock(EventHandler::class);
        $this->subscribers = $this->subscribers->with(new Subscriber(SubscriptionId::fromString('s1'), RunMode::FROM_NOW, $handler));

        $result = $this->subscriptionEngine()->boot();

        $this->assertSubscriptions(
            ['id' => 's1', 'status' => 'ACTIVE', 'position' => 3, 'error' => null],
            ['id' => 's2', 'status' => 'DETACHED', 'position' => 1, 'error' => null],
        );

        $this->assertEmittedEngineEvents(
            'CatchUpStarted: Start catching up subscriptions in states BOOTING.',
            'SubscriptionDetached: Subscription "s2" was marked detached',
        );
        self::assertTrue($result->successful());
    }

    public function test_boot_commits_transaction_if_no_subscription_matches(): void
    {
        $handler = $this->createMock(EventHandler::class);

        $subscriptionEngine = $this->subscriptionEngine();
        $subscriptionEngine->onEvent(function (EngineEvent $event): void {
            if ($event instanceof NoSubscriptionsFound) {
                self::assertTrue($this->subscriptionStore->transactionActive());
            }
        });
        $result = $subscriptionEngine->boot();
        self::assertFalse($this->subscriptionStore->transactionActive());

        $this->assertEmittedEngineEvents(
            'CatchUpStarted: Start catching up subscriptions in states BOOTING.',
            'NoSubscriptionsFound: No subscriptions found for criteria: {"ids":null,"status":["BOOTING"]}',
        );
        self::assertTrue($result->successful());
    }

    // ---------------------


    private function subscriptionEngine(): SubscriptionEngine
    {
        $engine = new SubscriptionEngine(
            $this->eventStore,
            $this->subscriptionStore,
            $this->subscribers,
        );
        $engine->onEvent(fn (EngineEvent $event) => $this->emittedEngineEvents[] = $event);
        return $engine;
    }

    private function assertEmittedEngineEvents(string ...$expectedEvents): void
    {
        self::assertSame($expectedEvents, array_map(static fn (EngineEvent $event) => sprintf('%s: %s', substr($event::class, strrpos($event::class, '\\') + 1), $event), $this->emittedEngineEvents));
    }

    private function assertSubscriptions(array ...$subscriptions): void
    {
        self::assertSame($subscriptions, $this->subscriptionStore->findByCriteriaForUpdate(SubscriptionCriteria::noConstraints())->map(static fn (Subscription $subscription) => [
            'id' => $subscription->id->value,
            'status' => $subscription->status->value,
            'position' => $subscription->position->value,
            'error' => $subscription->error->errorMessage,
        ]));
    }
}
