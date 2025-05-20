<?php

namespace Nuxgame\LaravelDynamicDBFailover\Tests\Unit\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Database\DatabaseManager as DBManager;
use Mockery;
use Nuxgame\LaravelDynamicDBFailover\Services\DatabaseFailoverManager;
use Nuxgame\LaravelDynamicDBFailover\Enums\ConnectionStatus;
use Nuxgame\LaravelDynamicDBFailover\Events\ExitedLimitedFunctionalityModeEvent;
use Nuxgame\LaravelDynamicDBFailover\Events\LimitedFunctionalityModeActivatedEvent;
use Nuxgame\LaravelDynamicDBFailover\Events\SwitchedToPrimaryConnectionEvent;
use Nuxgame\LaravelDynamicDBFailover\Events\SwitchedToFailoverConnectionEvent;
use Nuxgame\LaravelDynamicDBFailover\HealthCheck\ConnectionStateManager;
use Orchestra\Testbench\TestCase;

class DatabaseFailoverManagerTest extends TestCase
{
    protected $configMock;
    protected $stateManagerMock;
    protected $dbManagerMock;
    protected $eventDispatcherMock;
    protected $manager;

    protected string $primaryName = 'test_primary_db';
    protected string $failoverName = 'test_failover_db';
    protected string $blockingName = 'test_blocking_db';

    protected function setUp(): void
    {
        parent::setUp();

        $this->configMock = Mockery::mock(ConfigRepository::class);
        $this->stateManagerMock = Mockery::mock(ConnectionStateManager::class);
        $this->dbManagerMock = Mockery::mock(DBManager::class);
        $this->eventDispatcherMock = Mockery::mock(EventDispatcher::class);

        $this->app->instance(EventDispatcher::class, $this->eventDispatcherMock);
        $this->app->instance(\Illuminate\Contracts\Events\Dispatcher::class, $this->eventDispatcherMock);

        $this->configMock->shouldReceive('get')->with('dynamic_db_failover.connections.primary')->andReturn($this->primaryName);
        $this->configMock->shouldReceive('get')->with('dynamic_db_failover.connections.failover')->andReturn($this->failoverName);
        $this->configMock->shouldReceive('get')->with('dynamic_db_failover.connections.blocking')->andReturn($this->blockingName);
        $this->configMock->shouldReceive('get')->with('dynamic_db_failover.enabled')->andReturn(true);

        $this->dbManagerMock->shouldReceive('connection')->with($this->primaryName)->andReturnSelf();
        $this->dbManagerMock->shouldReceive('connection')->with($this->failoverName)->andReturnSelf();
        $this->dbManagerMock->shouldReceive('connection')->with($this->blockingName)->andReturnSelf();
        $this->dbManagerMock->shouldReceive('reconnect')->withAnyArgs();
        $this->dbManagerMock->shouldReceive('disconnect')->withAnyArgs();
        $this->dbManagerMock->shouldReceive('setDefaultConnection')->withAnyArgs();

        $this->manager = new DatabaseFailoverManager(
            $this->configMock,
            $this->stateManagerMock,
            $this->dbManagerMock,
            $this->eventDispatcherMock
        );
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        Mockery::close();
        parent::tearDown();
    }

    public function test_determine_and_set_connection_uses_primary_on_initial_call_if_healthy()
    {
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->primaryName)->andReturn(ConnectionStatus::HEALTHY);
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->failoverName)->andReturn(ConnectionStatus::HEALTHY);
        $this->stateManagerMock->shouldReceive('getFailureCount')->with($this->primaryName)->andReturn(0);
        $this->stateManagerMock->shouldReceive('getFailureCount')->with($this->failoverName)->andReturn(0);

        $this->eventDispatcherMock->shouldReceive('dispatch')->once()->with(Mockery::on(function ($event) {
            return $event instanceof SwitchedToPrimaryConnectionEvent &&
                   $event->previousConnectionName === null &&
                   $event->newConnectionName === $this->primaryName;
        }));

        $activeConnection = $this->manager->determineAndSetConnection();
        $this->assertEquals($this->primaryName, $activeConnection);
    }

    public function test_determine_and_set_connection_uses_primary_when_already_primary_and_healthy_no_event()
    {
        // To simulate that the manager believes it's already on primary:
        // Directly set the internal currentActiveConnectionName state of the manager.
        // This focuses the test on the logic that prevents re-dispatching events if already on the target connection.
        $reflection = new \ReflectionObject($this->manager);
        $property = $reflection->getProperty('currentActiveConnectionName');
        $property->setAccessible(true);
        $property->setValue($this->manager, $this->primaryName);

        // Conditions: Primary is healthy.
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->primaryName)->andReturn(ConnectionStatus::HEALTHY);
        // Failover status doesn't strictly matter if primary is healthy and chosen, but mock it for completeness of resolveActiveConnection.
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->failoverName)->andReturn(ConnectionStatus::HEALTHY)->byDefault();

        // No event should be dispatched if the connection doesn't change.
        $this->eventDispatcherMock->shouldNotReceive('dispatch');

        $activeConnection = $this->manager->determineAndSetConnection();
        $this->assertEquals($this->primaryName, $activeConnection);
    }

    public function test_determine_and_set_connection_switches_to_primary_from_failover()
    {
        // Initial setup: Primary is DOWN, Failover is HEALTHY.
        // This will cause the first call to determineAndSetConnection to switch to failover.
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->primaryName)
            ->once() // Expect this for the first call to resolveActiveConnection
            ->andReturn(ConnectionStatus::DOWN); // Initially Primary is DOWN
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->failoverName)
            ->once() // Expect this for the first call to resolveActiveConnection
            ->andReturn(ConnectionStatus::HEALTHY); // Failover is HEALTHY

        // Expect a switch to failover first.
        $this->eventDispatcherMock->shouldReceive('dispatch')
            ->once()
            ->ordered()
            ->with(Mockery::on(function ($event) {
                return $event instanceof SwitchedToFailoverConnectionEvent &&
                       $event->previousConnectionName === null &&
                       $event->newConnectionName === $this->failoverName;
            }));

        // First call: switches to failover. manager's internal currentActiveConnectionName becomes $this->failoverName.
        // This call consumes the ->once() expectations for getConnectionStatus.
        $this->manager->determineAndSetConnection();

        // Now, setup for switching back to primary: Primary becomes HEALTHY.
        // These are new expectations for the second call to resolveActiveConnection.
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->primaryName)
            ->andReturn(ConnectionStatus::HEALTHY); // Now Primary is HEALTHY
        // Failover also needs to be mocked for the second call to resolveActiveConnection, in case primary isn't chosen.
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->failoverName)
            ->andReturn(ConnectionStatus::HEALTHY); // Failover remains HEALTHY

        // Expect the switch to primary.
        $this->eventDispatcherMock->shouldReceive('dispatch')
            ->once()
            ->ordered()
            ->with(Mockery::on(function ($event) {
                return $event instanceof SwitchedToPrimaryConnectionEvent &&
                       $event->previousConnectionName === $this->failoverName &&
                       $event->newConnectionName === $this->primaryName;
            }));

        $activeConnection = $this->manager->determineAndSetConnection(); // Second call
        $this->assertEquals($this->primaryName, $activeConnection);
    }

    public function test_determine_and_set_connection_uses_primary_when_healthy()
    {
        // This test verifies switching to Primary when it's healthy,
        // assuming the system was previously on Failover.

        // Step 1: Simulate being on Failover.
        // Primary is DOWN, Failover is HEALTHY.
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->primaryName)
            ->once() // Expect this for the first call to resolveActiveConnection
            ->andReturn(ConnectionStatus::DOWN);
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->failoverName)
            ->once() // Expect this for the first call to resolveActiveConnection
            ->andReturn(ConnectionStatus::HEALTHY);

        // Expect first switch to Failover
        $this->eventDispatcherMock->shouldReceive('dispatch')
            ->once()
            ->ordered()
            ->with(Mockery::on(function ($event) {
                return $event instanceof SwitchedToFailoverConnectionEvent &&
                       $event->previousConnectionName === null &&
                       $event->newConnectionName === $this->failoverName;
            }));
        // This call consumes the ->once() expectations for getConnectionStatus.
        $this->manager->determineAndSetConnection(); // Now on failover, $this->manager->currentActiveConnectionName is $this->failoverName

        // Step 2: Primary becomes HEALTHY. Failover also remains HEALTHY.
        // System should switch from Failover to Primary.
        // These are new expectations for the second call to resolveActiveConnection.
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->primaryName)
            ->andReturn(ConnectionStatus::HEALTHY);
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->failoverName)
            ->andReturn(ConnectionStatus::HEALTHY); // Failover remains HEALTHY

        $this->eventDispatcherMock->shouldReceive('dispatch')
            ->once()
            ->ordered()
            ->with(Mockery::on(function ($event) {
                return $event instanceof SwitchedToPrimaryConnectionEvent &&
                       $event->previousConnectionName === $this->failoverName &&
                       $event->newConnectionName === $this->primaryName;
            }));

        $activeConnection = $this->manager->determineAndSetConnection();
        $this->assertEquals($this->primaryName, $activeConnection);
    }

    public function test_force_switch_to_primary_when_already_primary_no_event()
    {
        // Simulate manager already being on the primary connection by setting internal state.
        $reflection = new \ReflectionObject($this->manager);
        $property = $reflection->getProperty('currentActiveConnectionName');
        $property->setAccessible(true);
        $property->setValue($this->manager, $this->primaryName);

        // forceSwitchToPrimary should still attempt to set connection statuses as healthy,
        // regardless of whether a switch event is dispatched.
        $this->stateManagerMock->shouldReceive('setConnectionStatus')->with($this->primaryName, ConnectionStatus::HEALTHY, 0)->once();
        $this->stateManagerMock->shouldReceive('setConnectionStatus')->with($this->failoverName, ConnectionStatus::HEALTHY, 0)->once();

        // No event should be dispatched because currentActiveConnectionName is already primary.
        $this->eventDispatcherMock->shouldNotReceive('dispatch');

        $this->manager->forceSwitchToPrimary();
        // Assert that the connection indeed remains primary.
        $this->assertEquals($this->primaryName, $this->manager->getCurrentActiveConnectionName(), "Manager should still report primary as current after no-op force switch.");
    }

    public function test_determine_and_set_connection_switches_to_failover()
    {
        // Step 1: Ensure current connection is Primary.
        // Primary is HEALTHY.
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->primaryName)
            ->once() // Expect this for the first call to resolveActiveConnection
            ->andReturn(ConnectionStatus::HEALTHY);
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->failoverName)
            ->once() // Expect this for the first call to resolveActiveConnection
            ->andReturn(ConnectionStatus::HEALTHY); // Failover also HEALTHY initially

        // Expect first switch to Primary (previous will be null)
        $this->eventDispatcherMock->shouldReceive('dispatch')
            ->once()
            ->ordered()
            ->with(Mockery::on(function ($event) {
                return $event instanceof SwitchedToPrimaryConnectionEvent &&
                       $event->previousConnectionName === null &&
                       $event->newConnectionName === $this->primaryName;
            }));
        // This call consumes the ->once() expectations for getConnectionStatus.
        $this->manager->determineAndSetConnection(); // manager's currentActiveConnectionName is now $this->primaryName

        // Step 2: Primary goes DOWN, Failover is HEALTHY.
        // System should switch from Primary to Failover.
        // These are new expectations for the second call to resolveActiveConnection.
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->primaryName)
            ->andReturn(ConnectionStatus::DOWN); // Primary is now DOWN
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->failoverName)
            ->andReturn(ConnectionStatus::HEALTHY); // Failover remains HEALTHY

        // The original test had a getFailureCount mock for failover. It is not strictly necessary
        // for the P-DOWN, F-HEALTHY path, so omitting for clarity unless issues arise.
        // $this->stateManagerMock->shouldReceive('getFailureCount')->with($this->failoverName)->andReturn(0);

        // Expect the switch to failover.
        $this->eventDispatcherMock->shouldReceive('dispatch')
            ->once()
            ->ordered()
            ->with(Mockery::on(function ($event) {
                return $event instanceof SwitchedToFailoverConnectionEvent &&
                       $event->previousConnectionName === $this->primaryName &&
                       $event->newConnectionName === $this->failoverName;
            }));

        $activeConnection = $this->manager->determineAndSetConnection(); // Second call
        $this->assertEquals($this->failoverName, $activeConnection);
    }

    public function test_determine_and_set_connection_switches_to_blocking()
    {
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->primaryName)->andReturn(ConnectionStatus::DOWN);
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->failoverName)->andReturn(ConnectionStatus::DOWN);

        // The dbManagerMock->getName() is not used by determineAndSetConnection to evaluate previous state for event dispatch.
        // previousConnectionName will be null (initial state for the manager instance), which is fine for LFM activation.

        $this->eventDispatcherMock->shouldReceive('dispatch')->once()->with(Mockery::on(function ($event) {
            return $event instanceof LimitedFunctionalityModeActivatedEvent &&
                   $event->connectionName === $this->blockingName;
        }));

        $activeConnection = $this->manager->determineAndSetConnection();
        $this->assertEquals($this->blockingName, $activeConnection);
    }

    public function test_determine_and_set_connection_defaults_to_primary_if_cache_unavailable_scenario()
    {
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->primaryName)->andReturn(ConnectionStatus::UNKNOWN);
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->failoverName)->andReturn(ConnectionStatus::UNKNOWN);
        $this->stateManagerMock->shouldReceive('getFailureCount')->with($this->primaryName)->andReturn(0);
        $this->stateManagerMock->shouldReceive('getFailureCount')->with($this->failoverName)->andReturn(0);

        $this->eventDispatcherMock->shouldReceive('dispatch')->once()->with(Mockery::on(function ($event) {
            return $event instanceof SwitchedToPrimaryConnectionEvent &&
                   $event->previousConnectionName === null &&
                   $event->newConnectionName === $this->primaryName;
        }));

        $activeConnection = $this->manager->determineAndSetConnection();
        $this->assertEquals($this->primaryName, $activeConnection);
    }

    public function test_force_switch_to_primary_sets_primary_and_dispatches_event()
    {
        // Step 1: Simulate being on Failover connection initially.
        // To do this, we first make primary DOWN and failover HEALTHY, then call determineAndSetConnection.
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->primaryName)
            ->andReturn(ConnectionStatus::DOWN);
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->failoverName)
            ->andReturn(ConnectionStatus::HEALTHY);
        // $this->stateManagerMock->shouldReceive('getFailureCount')->with($this->failoverName)->andReturn(0); // For determineAndSetConnection if it hits UNKNOWN path

        // Expect the initial switch to failover by determineAndSetConnection.
        // previousConnectionName will be null.
        $this->eventDispatcherMock->shouldReceive('dispatch')
            ->once()
            ->ordered()
            ->with(Mockery::on(function ($event) {
                return $event instanceof SwitchedToFailoverConnectionEvent &&
                       $event->previousConnectionName === null &&
                       $event->newConnectionName === $this->failoverName;
            }));

        $this->manager->determineAndSetConnection(); // Manager's currentActiveConnectionName is now $this->failoverName

        // Step 2: Call forceSwitchToPrimary.
        // It should set connection statuses via stateManager and dispatch the SwitchedToPrimaryConnectionEvent.
        $this->stateManagerMock->shouldReceive('setConnectionStatus')->with($this->primaryName, ConnectionStatus::HEALTHY, 0)->once();
        $this->stateManagerMock->shouldReceive('setConnectionStatus')->with($this->failoverName, ConnectionStatus::HEALTHY, 0)->once();

        $this->eventDispatcherMock->shouldReceive('dispatch')
            ->once()
            ->ordered()
            ->with(Mockery::on(function ($event) {
                return $event instanceof SwitchedToPrimaryConnectionEvent &&
                       $event->previousConnectionName === $this->failoverName && // This should now be correct
                       $event->newConnectionName === $this->primaryName;
            }));

        $this->manager->forceSwitchToPrimary();
        $this->assertEquals($this->primaryName, $this->manager->getCurrentActiveConnectionName(), "Manager should report primary as current active connection after force switch.");
    }

    public function test_force_switch_to_primary_from_blocking_dispatches_exit_event()
    {
        // Step 1: Simulate being on Blocking connection initially.
        // To do this, make both Primary and Failover DOWN, then call determineAndSetConnection.
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->primaryName)
            ->andReturn(ConnectionStatus::DOWN);
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->failoverName)
            ->andReturn(ConnectionStatus::DOWN);

        // Expect the initial switch to blocking by determineAndSetConnection.
        // This should dispatch LimitedFunctionalityModeActivatedEvent.
        // The manager's internal currentActiveConnectionName will be set to blockingName.
        $this->eventDispatcherMock->shouldReceive('dispatch')
            ->once() // For LimitedFunctionalityModeActivatedEvent
            ->ordered() // Maintain order for all dispatched events in this test
            ->with(Mockery::on(function($event) {
                return $event instanceof LimitedFunctionalityModeActivatedEvent &&
                       $event->connectionName === $this->blockingName;
            }));

        $this->manager->determineAndSetConnection(); // Manager's currentActiveConnectionName is now $this->blockingName

        // Step 2: Call forceSwitchToPrimary.
        // This should set statuses and dispatch SwitchedToPrimaryConnectionEvent then ExitedLimitedFunctionalityModeEvent.
        $this->stateManagerMock->shouldReceive('setConnectionStatus')->with($this->primaryName, ConnectionStatus::HEALTHY, 0)->once();
        $this->stateManagerMock->shouldReceive('setConnectionStatus')->with($this->failoverName, ConnectionStatus::HEALTHY, 0)->once();

        // Expect SwitchedToPrimaryConnectionEvent
        $this->eventDispatcherMock->shouldReceive('dispatch')
            ->once() // For SwitchedToPrimaryConnectionEvent
            ->ordered()
            ->with(Mockery::on(function ($event) {
                return $event instanceof SwitchedToPrimaryConnectionEvent &&
                       $event->previousConnectionName === $this->blockingName && // This should now be correct
                       $event->newConnectionName === $this->primaryName;
            }));

        // Expect ExitedLimitedFunctionalityModeEvent
        $this->eventDispatcherMock->shouldReceive('dispatch')
            ->once() // For ExitedLimitedFunctionalityModeEvent
            ->ordered()
            ->with(Mockery::on(function ($event) {
                return $event instanceof ExitedLimitedFunctionalityModeEvent &&
                       $event->restoredToConnectionName === $this->primaryName;
            }));

        $this->manager->forceSwitchToPrimary();
        $this->assertEquals($this->primaryName, $this->manager->getCurrentActiveConnectionName(), "Manager should report primary as current active connection after force switch from blocking.");
    }
}
