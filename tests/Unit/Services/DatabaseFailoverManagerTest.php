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

/**
 * Unit tests for the DatabaseFailoverManager class.
 *
 * This test suite focuses on verifying the logic of the DatabaseFailoverManager,
 * ensuring it correctly determines which database connection to use based on the
 * status of primary and failover connections, and that it dispatches the
 * appropriate events during connection switches or mode changes (e.g., LFM).
 * Mocks are used extensively to isolate the DatabaseFailoverManager from actual
 * database interactions, cache operations, and event listeners.
 */
class DatabaseFailoverManagerTest extends TestCase
{
    /** @var \Mockery\MockInterface&\Illuminate\Contracts\Config\Repository Mock for the Laravel Config repository. */
    protected $configMock;
    /** @var \Mockery\MockInterface&\Nuxgame\LaravelDynamicDBFailover\HealthCheck\ConnectionStateManager Mock for the ConnectionStateManager. */
    protected $stateManagerMock;
    /** @var \Mockery\MockInterface&\Illuminate\Database\DatabaseManager Mock for the Laravel Database Manager. */
    protected $dbManagerMock;
    /** @var \Mockery\MockInterface&\Illuminate\Contracts\Events\Dispatcher Mock for the Laravel Event Dispatcher. */
    protected $eventDispatcherMock;
    /** @var \Nuxgame\LaravelDynamicDBFailover\Services\DatabaseFailoverManager The instance of the manager being tested. */
    protected $manager;

    /** @var string The name configured for the primary database connection. */
    protected string $primaryName = 'test_primary_db';
    /** @var string The name configured for the failover database connection. */
    protected string $failoverName = 'test_failover_db';
    /** @var string The name configured for the blocking database connection (used in LFM). */
    protected string $blockingName = 'test_blocking_db';

    /**
     * Sets up the test environment before each test method.
     *
     * Initializes mocks for ConfigRepository, ConnectionStateManager, DBManager, and EventDispatcher.
     * Configures default mock behaviors for getting connection names and package enabled status.
     * Instantiates the DatabaseFailoverManager with these mocks.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Initialize mock objects for dependencies.
        $this->configMock = Mockery::mock(ConfigRepository::class);
        $this->stateManagerMock = Mockery::mock(ConnectionStateManager::class);
        $this->dbManagerMock = Mockery::mock(DBManager::class);
        $this->eventDispatcherMock = Mockery::mock(EventDispatcher::class);

        // Ensure the application uses the mocked EventDispatcher for these tests.
        // This is important for asserting event dispatches.
        $this->app->instance(EventDispatcher::class, $this->eventDispatcherMock);
        $this->app->instance(\Illuminate\Contracts\Events\Dispatcher::class, $this->eventDispatcherMock); // Bind against the contract too.

        // Configure mock ConfigRepository to return predefined connection names and enabled status.
        // This simulates the package's configuration settings.
        $this->configMock->shouldReceive('get')->with('dynamic_db_failover.connections.primary')->andReturn($this->primaryName);
        $this->configMock->shouldReceive('get')->with('dynamic_db_failover.connections.failover')->andReturn($this->failoverName);
        $this->configMock->shouldReceive('get')->with('dynamic_db_failover.connections.blocking')->andReturn($this->blockingName);
        $this->configMock->shouldReceive('get')->with('dynamic_db_failover.enabled')->andReturn(true);

        // Configure mock DBManager for common operations.
        // These mocks prevent actual database operations and allow verification of calls.
        $this->dbManagerMock->shouldReceive('connection')->with($this->primaryName)->andReturnSelf();
        $this->dbManagerMock->shouldReceive('connection')->with($this->failoverName)->andReturnSelf();
        $this->dbManagerMock->shouldReceive('connection')->with($this->blockingName)->andReturnSelf();
        $this->dbManagerMock->shouldReceive('reconnect')->withAnyArgs(); // Allow reconnect calls.
        $this->dbManagerMock->shouldReceive('disconnect')->withAnyArgs(); // Allow disconnect calls.
        $this->dbManagerMock->shouldReceive('setDefaultConnection')->withAnyArgs(); // Allow setting default connection.

        // Instantiate the DatabaseFailoverManager with the mocked dependencies.
        $this->manager = new DatabaseFailoverManager(
            $this->configMock,
            $this->stateManagerMock,
            $this->dbManagerMock,
            $this->eventDispatcherMock
        );
    }

    /**
     * Tears down the test environment after each test method.
     *
     * Restores error/exception handlers (if changed by tests) and closes Mockery
     * to verify expectations and clean up mock objects.
     */
    protected function tearDown(): void
    {
        restore_error_handler(); // Restore PHP's default error handler.
        restore_exception_handler(); // Restore PHP's default exception handler.
        Mockery::close(); // Perform Mockery assertions and cleanup.
        parent::tearDown();
    }

    /**
     * Test that the primary connection is used on the initial call if it's healthy.
     *
     * Verifies that:
     * - `determineAndSetConnection` returns the primary connection name.
     * - A `SwitchedToPrimaryConnectionEvent` is dispatched with previousConnectionName as null.
     */
    public function test_determine_and_set_connection_uses_primary_on_initial_call_if_healthy()
    {
        // Arrange: Mock ConnectionStateManager to report both primary and failover as healthy.
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->primaryName)->andReturn(ConnectionStatus::HEALTHY);
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->failoverName)->andReturn(ConnectionStatus::HEALTHY);
        // Mock failure counts to be 0, reinforcing their healthy status.
        $this->stateManagerMock->shouldReceive('getFailureCount')->with($this->primaryName)->andReturn(0);
        $this->stateManagerMock->shouldReceive('getFailureCount')->with($this->failoverName)->andReturn(0);

        // Assert: Expect SwitchedToPrimaryConnectionEvent because it's the initial determination.
        // The previousConnectionName should be null as no connection was active before this call.
        $this->eventDispatcherMock->shouldReceive('dispatch')->once()->with(Mockery::on(function ($event) {
            return $event instanceof SwitchedToPrimaryConnectionEvent &&
                   $event->previousConnectionName === null &&
                   $event->newConnectionName === $this->primaryName;
        }));

        // Act: Call the method under test.
        $activeConnection = $this->manager->determineAndSetConnection();

        // Assert: Verify the active connection is the primary.
        $this->assertEquals($this->primaryName, $activeConnection);
    }

    /**
     * Test that no event is dispatched if the primary connection is already active and healthy.
     *
     * Verifies that `determineAndSetConnection` does not dispatch a `SwitchedToPrimaryConnectionEvent`
     * if the system is already on the primary connection and it remains healthy.
     */
    public function test_determine_and_set_connection_uses_primary_when_already_primary_and_healthy_no_event()
    {
        // Arrange: Simulate that the manager believes it's already on primary.
        // This is done by directly setting the internal currentActiveConnectionName state of the manager.
        // This focuses the test on the logic that prevents re-dispatching events if already on the target connection.
        $reflection = new \ReflectionObject($this->manager);
        $property = $reflection->getProperty('currentActiveConnectionName');
        $property->setAccessible(true);
        $property->setValue($this->manager, $this->primaryName);

        // Arrange: Mock ConnectionStateManager to report primary as healthy.
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->primaryName)->andReturn(ConnectionStatus::HEALTHY);
        // Failover status doesn't strictly matter if primary is healthy and chosen, but mock it for completeness of resolveActiveConnection.
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->failoverName)->andReturn(ConnectionStatus::HEALTHY)->byDefault();

        // Assert: No event should be dispatched if the connection doesn't change.
        $this->eventDispatcherMock->shouldNotReceive('dispatch');

        // Act: Call the method under test.
        $activeConnection = $this->manager->determineAndSetConnection();

        // Assert: Verify the active connection remains primary.
        $this->assertEquals($this->primaryName, $activeConnection);
    }

    /**
     * Test switching from failover back to primary when primary becomes healthy.
     *
     * This test simulates a scenario where:
     * 1. The system is initially on the failover connection (e.g., primary was down).
     * 2. The primary connection recovers and becomes healthy.
     * 3. The manager should switch back to the primary connection and dispatch the `SwitchedToPrimaryConnectionEvent`.
     */
    public function test_determine_and_set_connection_switches_to_primary_from_failover()
    {
        // Arrange Step 1: Simulate system is initially on failover.
        // Mock primary as DOWN and failover as HEALTHY for the first call to determineAndSetConnection.
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->primaryName)
            ->once() // This expectation is for the first call to resolveActiveConnection.
            ->andReturn(ConnectionStatus::DOWN); // Initially Primary is DOWN.
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->failoverName)
            ->once() // This expectation is for the first call to resolveActiveConnection.
            ->andReturn(ConnectionStatus::HEALTHY); // Failover is HEALTHY.

        // Assert Step 1: Expect a switch to failover first.
        // Previous connection is null as it's the manager's first determination.
        $this->eventDispatcherMock->shouldReceive('dispatch')
            ->once()
            ->ordered() // Ensures events are dispatched in the expected order.
            ->with(Mockery::on(function ($event) {
                return $event instanceof SwitchedToFailoverConnectionEvent &&
                       $event->previousConnectionName === null && // Initial state, so no previous active connection.
                       $event->newConnectionName === $this->failoverName;
            }));

        // Act Step 1: First call to determineAndSetConnection. This should switch to failover.
        // This call consumes the ->once() expectations for getConnectionStatus set above.
        // The manager's internal currentActiveConnectionName will become $this->failoverName.
        $this->manager->determineAndSetConnection();

        // Arrange Step 2: Simulate primary connection recovering.
        // Mock primary as HEALTHY for the second call to determineAndSetConnection.
        // These are new expectations for the second call to resolveActiveConnection.
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->primaryName)
            ->andReturn(ConnectionStatus::HEALTHY); // Now Primary is HEALTHY.
        // Failover also needs to be mocked for the second call, can remain HEALTHY.
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->failoverName)
            ->andReturn(ConnectionStatus::HEALTHY);

        // Assert Step 2: Expect the switch back to primary.
        // Previous connection should now be the failover connection.
        $this->eventDispatcherMock->shouldReceive('dispatch')
            ->once()
            ->ordered()
            ->with(Mockery::on(function ($event) {
                return $event instanceof SwitchedToPrimaryConnectionEvent &&
                       $event->previousConnectionName === $this->failoverName &&
                       $event->newConnectionName === $this->primaryName;
            }));

        // Act Step 2: Second call to determineAndSetConnection.
        $activeConnection = $this->manager->determineAndSetConnection();

        // Assert Step 2: Verify the active connection is now primary.
        $this->assertEquals($this->primaryName, $activeConnection);
    }

    /**
     * Test that the system uses the primary connection when it's healthy.
     *
     * This is similar to `test_determine_and_set_connection_switches_to_primary_from_failover`
     * but structured to clearly show the transition from an initial failover state to primary.
     * It ensures that if the primary is available, it is preferred.
     */
    public function test_determine_and_set_connection_uses_primary_when_healthy()
    {
        // This test verifies switching to Primary when it's healthy,
        // assuming the system was previously on Failover.

        // Arrange Step 1: Simulate being on Failover by making Primary DOWN and Failover HEALTHY.
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->primaryName)
            ->once() // For the first call to resolveActiveConnection.
            ->andReturn(ConnectionStatus::DOWN);
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->failoverName)
            ->once() // For the first call to resolveActiveConnection.
            ->andReturn(ConnectionStatus::HEALTHY);

        // Assert Step 1: Expect an initial switch to Failover.
        $this->eventDispatcherMock->shouldReceive('dispatch')
            ->once()
            ->ordered()
            ->with(Mockery::on(function ($event) {
                return $event instanceof SwitchedToFailoverConnectionEvent &&
                       $event->previousConnectionName === null && // Initial state.
                       $event->newConnectionName === $this->failoverName;
            }));
        // Act Step 1: Call determineAndSetConnection. The manager is now conceptually on failover.
        // $this->manager->currentActiveConnectionName is internally set to $this->failoverName.
        $this->manager->determineAndSetConnection();

        // Arrange Step 2: Primary becomes HEALTHY. Failover also remains HEALTHY.
        // The system should now prefer the primary connection.
        // These are new expectations for the second call to resolveActiveConnection.
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->primaryName)
            ->andReturn(ConnectionStatus::HEALTHY);
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->failoverName)
            ->andReturn(ConnectionStatus::HEALTHY);

        // Assert Step 2: Expect a switch from Failover to Primary.
        $this->eventDispatcherMock->shouldReceive('dispatch')
            ->once()
            ->ordered()
            ->with(Mockery::on(function ($event) {
                return $event instanceof SwitchedToPrimaryConnectionEvent &&
                       $event->previousConnectionName === $this->failoverName &&
                       $event->newConnectionName === $this->primaryName;
            }));

        // Act Step 2: Call determineAndSetConnection again.
        $activeConnection = $this->manager->determineAndSetConnection();

        // Assert Step 2: Verify the active connection is now primary.
        $this->assertEquals($this->primaryName, $activeConnection);
    }

    /**
     * Test that forcing a switch to primary when already on primary dispatches no event.
     *
     * Verifies that `forceSwitchToPrimary` correctly sets connection statuses (primary and failover to HEALTHY)
     * via the ConnectionStateManager but does not dispatch a `SwitchedToPrimaryConnectionEvent`
     * if the system is already using the primary connection. It also ensures that an
     * `ExitedLimitedFunctionalityModeEvent` is not dispatched if not in LFM.
     */
    public function test_force_switch_to_primary_when_already_primary_no_event()
    {
        // Arrange: Simulate manager already being on the primary connection by setting its internal state.
        $reflection = new \ReflectionObject($this->manager);
        $property = $reflection->getProperty('currentActiveConnectionName');
        $property->setAccessible(true);
        $property->setValue($this->manager, $this->primaryName);

        // Arrange: Mock ConnectionStateManager to expect calls for setting statuses to HEALTHY.
        // `forceSwitchToPrimary` should always attempt to set these, regardless of event dispatch.
        $this->stateManagerMock->shouldReceive('setConnectionStatus')->with($this->primaryName, ConnectionStatus::HEALTHY, 0)->once();
        $this->stateManagerMock->shouldReceive('setConnectionStatus')->with($this->failoverName, ConnectionStatus::HEALTHY, 0)->once();

        // Assert: No SwitchedToPrimaryConnectionEvent or ExitedLimitedFunctionalityModeEvent should be dispatched
        // because currentActiveConnectionName is already primary and we are not in LFM.
        $this->eventDispatcherMock->shouldNotReceive('dispatch');

        // Act: Call the method under test.
        $this->manager->forceSwitchToPrimary();

        // Assert: Verify that the connection indeed remains primary after the call.
        $this->assertEquals($this->primaryName, $this->manager->getCurrentActiveConnectionName(), "Manager should still report primary as current after no-op force switch.");
    }

    /**
     * Test switching from primary to failover when primary becomes unhealthy.
     *
     * This test simulates a scenario where:
     * 1. The system is initially on the primary connection and it's healthy.
     * 2. The primary connection goes down.
     * 3. The failover connection is healthy.
     * 4. The manager should switch to the failover connection and dispatch `SwitchedToFailoverConnectionEvent`.
     */
    public function test_determine_and_set_connection_switches_to_failover()
    {
        // Arrange Step 1: Ensure current connection is Primary and it's HEALTHY.
        // Mock primary and failover as HEALTHY for the first call to determineAndSetConnection.
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->primaryName)
            ->once() // For the first call to resolveActiveConnection.
            ->andReturn(ConnectionStatus::HEALTHY);
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->failoverName)
            ->once() // For the first call to resolveActiveConnection.
            ->andReturn(ConnectionStatus::HEALTHY); // Failover also HEALTHY initially.

        // Assert Step 1: Expect an initial switch to Primary (previous connection will be null).
        $this->eventDispatcherMock->shouldReceive('dispatch')
            ->once()
            ->ordered()
            ->with(Mockery::on(function ($event) {
                return $event instanceof SwitchedToPrimaryConnectionEvent &&
                       $event->previousConnectionName === null &&
                       $event->newConnectionName === $this->primaryName;
            }));
        // Act Step 1: Call determineAndSetConnection. The manager is now conceptually on primary.
        // This call consumes the ->once() expectations for getConnectionStatus.
        // $this->manager->currentActiveConnectionName is internally set to $this->primaryName.
        $this->manager->determineAndSetConnection();

        // Arrange Step 2: Primary goes DOWN, Failover remains HEALTHY.
        // The system should now switch from Primary to Failover.
        // These are new expectations for the second call to resolveActiveConnection.
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->primaryName)
            ->andReturn(ConnectionStatus::DOWN); // Primary is now DOWN.
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->failoverName)
            ->andReturn(ConnectionStatus::HEALTHY); // Failover remains HEALTHY.

        // Note: getFailureCount mock for failover is not strictly necessary for the P-DOWN, F-HEALTHY path,
        // as the decision primarily relies on statuses. It would be relevant if failover was UNKNOWN.

        // Assert Step 2: Expect the switch to failover from primary.
        $this->eventDispatcherMock->shouldReceive('dispatch')
            ->once()
            ->ordered()
            ->with(Mockery::on(function ($event) {
                return $event instanceof SwitchedToFailoverConnectionEvent &&
                       $event->previousConnectionName === $this->primaryName &&
                       $event->newConnectionName === $this->failoverName;
            }));

        // Act Step 2: Call determineAndSetConnection again.
        $activeConnection = $this->manager->determineAndSetConnection();

        // Assert Step 2: Verify the active connection is now failover.
        $this->assertEquals($this->failoverName, $activeConnection);
    }

    /**
     * Test switching to the blocking connection (Limited Functionality Mode) when both primary and failover are down.
     *
     * Verifies that:
     * - `determineAndSetConnection` returns the blocking connection name (`test_blocking_db`).
     * - A `LimitedFunctionalityModeActivatedEvent` is dispatched, indicating the system has entered LFM.
     */
    public function test_determine_and_set_connection_switches_to_blocking()
    {
        // Arrange: Mock ConnectionStateManager to report both primary and failover as DOWN.
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->primaryName)->andReturn(ConnectionStatus::DOWN);
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->failoverName)->andReturn(ConnectionStatus::DOWN);

        // Note: The dbManagerMock->getName() is not used by determineAndSetConnection to evaluate
        // the previous state for event dispatch in this specific LFM activation scenario.
        // The previousConnectionName for the LFM event will be derived from the manager's internal state
        // (which would be null if this is the first determination, or the last active connection).

        // Assert: Expect LimitedFunctionalityModeActivatedEvent to be dispatched.
        $this->eventDispatcherMock->shouldReceive('dispatch')->once()->with(Mockery::on(function ($event) {
            return $event instanceof LimitedFunctionalityModeActivatedEvent &&
                   $event->connectionName === $this->blockingName;
        }));

        // Act: Call the method under test.
        $activeConnection = $this->manager->determineAndSetConnection();

        // Assert: Verify the active connection is the blocking connection.
        $this->assertEquals($this->blockingName, $activeConnection);
    }

    /**
     * Test that the system defaults to the primary connection if cache is unavailable (statuses are UNKNOWN).
     *
     * This scenario simulates a situation where the ConnectionStateManager cannot determine the actual
     * status of connections (e.g., cache is down), returning UNKNOWN. In such cases, the system
     * should optimistically attempt to use the primary connection.
     *
     * Verifies that:
     * - `determineAndSetConnection` returns the primary connection name.
     * - A `SwitchedToPrimaryConnectionEvent` is dispatched.
     */
    public function test_determine_and_set_connection_defaults_to_primary_if_cache_unavailable_scenario()
    {
        // Arrange: Mock ConnectionStateManager to report both connections as UNKNOWN (simulating cache failure).
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->primaryName)->andReturn(ConnectionStatus::UNKNOWN);
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->failoverName)->andReturn(ConnectionStatus::UNKNOWN);
        // Mock failure counts to be 0, as this is typically the default/reset state when status is UNKNOWN.
        $this->stateManagerMock->shouldReceive('getFailureCount')->with($this->primaryName)->andReturn(0);
        $this->stateManagerMock->shouldReceive('getFailureCount')->with($this->failoverName)->andReturn(0);

        // Assert: Expect SwitchedToPrimaryConnectionEvent as the system defaults to primary.
        // Previous connection is null as it's the first determination in this state.
        $this->eventDispatcherMock->shouldReceive('dispatch')->once()->with(Mockery::on(function ($event) {
            return $event instanceof SwitchedToPrimaryConnectionEvent &&
                   $event->previousConnectionName === null &&
                   $event->newConnectionName === $this->primaryName;
        }));

        // Act: Call the method under test.
        $activeConnection = $this->manager->determineAndSetConnection();

        // Assert: Verify the active connection is primary.
        $this->assertEquals($this->primaryName, $activeConnection);
    }

    /**
     * Test that forcing a switch to primary correctly sets the primary connection and dispatches the event.
     *
     * This test simulates a scenario where the system might be on the failover connection,
     * and `forceSwitchToPrimary` is called. It should:
     * 1. Set both primary and failover connection statuses to HEALTHY via ConnectionStateManager.
     * 2. Set the primary connection as the active one.
     * 3. Dispatch a `SwitchedToPrimaryConnectionEvent`.
     * It ensures `ExitedLimitedFunctionalityModeEvent` is NOT dispatched if not previously in LFM.
     */
    public function test_force_switch_to_primary_sets_primary_and_dispatches_event()
    {
        // Arrange Step 1: Simulate being on Failover connection initially.
        // To do this, first make primary DOWN and failover HEALTHY, then call determineAndSetConnection.
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->primaryName)
            ->andReturn(ConnectionStatus::DOWN); // Primary initially DOWN.
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->failoverName)
            ->andReturn(ConnectionStatus::HEALTHY); // Failover initially HEALTHY.
        // The getFailureCount mock for failoverName (commented out in original) might be relevant
        // if the initial status for failover was UNKNOWN. For HEALTHY, it's less critical.
        // $this->stateManagerMock->shouldReceive('getFailureCount')->with($this->failoverName)->andReturn(0);

        // Assert Step 1: Expect the initial switch to failover by determineAndSetConnection.
        // Previous connection name will be null as it's the first determination.
        $this->eventDispatcherMock->shouldReceive('dispatch')
            ->once()
            ->ordered()
            ->with(Mockery::on(function ($event) {
                return $event instanceof SwitchedToFailoverConnectionEvent &&
                       $event->previousConnectionName === null &&
                       $event->newConnectionName === $this->failoverName;
            }));

        // Act Step 1: This call sets the manager's internal currentActiveConnectionName to $this->failoverName.
        $this->manager->determineAndSetConnection();

        // Arrange Step 2: Setup for forceSwitchToPrimary.
        // Expect ConnectionStateManager to be told to set both connections to HEALTHY.
        $this->stateManagerMock->shouldReceive('setConnectionStatus')->with($this->primaryName, ConnectionStatus::HEALTHY, 0)->once();
        $this->stateManagerMock->shouldReceive('setConnectionStatus')->with($this->failoverName, ConnectionStatus::HEALTHY, 0)->once();

        // Assert Step 2: Expect SwitchedToPrimaryConnectionEvent.
        // The previousConnectionName should now be the failover connection name.
        $this->eventDispatcherMock->shouldReceive('dispatch')
            ->once()
            ->ordered()
            ->with(Mockery::on(function ($event) {
                return $event instanceof SwitchedToPrimaryConnectionEvent &&
                       $event->previousConnectionName === $this->failoverName && // Switched from failover.
                       $event->newConnectionName === $this->primaryName;
            }));
        // Crucially, do not expect ExitedLimitedFunctionalityModeEvent as we were not in LFM.
        $this->eventDispatcherMock->shouldNotReceive('dispatch')->with(Mockery::type(ExitedLimitedFunctionalityModeEvent::class));


        // Act Step 2: Call forceSwitchToPrimary.
        $this->manager->forceSwitchToPrimary();

        // Assert Step 2: Verify the manager now reports primary as the active connection.
        $this->assertEquals($this->primaryName, $this->manager->getCurrentActiveConnectionName(), "Manager should report primary as current active connection after force switch.");
    }

    /**
     * Test that forcing a switch to primary from blocking mode dispatches relevant exit events.
     *
     * This test simulates a scenario where the system is in Limited Functionality Mode (LFM),
     * using the blocking connection. When `forceSwitchToPrimary` is called:
     * 1. Both primary and failover statuses should be set to HEALTHY via ConnectionStateManager.
     * 2. The primary connection should become active.
     * 3. A `SwitchedToPrimaryConnectionEvent` should be dispatched (from blocking to primary).
     * 4. An `ExitedLimitedFunctionalityModeEvent` should be dispatched, signaling recovery to primary.
     */
    public function test_force_switch_to_primary_from_blocking_dispatches_exit_event()
    {
        // Arrange Step 1: Simulate being on Blocking connection initially.
        // Make both Primary and Failover DOWN, then call determineAndSetConnection.
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->primaryName)
            ->andReturn(ConnectionStatus::DOWN);
        $this->stateManagerMock->shouldReceive('getConnectionStatus')->with($this->failoverName)
            ->andReturn(ConnectionStatus::DOWN);

        // Assert Step 1: Expect the initial switch to blocking by determineAndSetConnection.
        // This should dispatch LimitedFunctionalityModeActivatedEvent.
        // The manager's internal currentActiveConnectionName will be set to blockingName.
        $this->eventDispatcherMock->shouldReceive('dispatch')
            ->once() // For LimitedFunctionalityModeActivatedEvent.
            ->ordered() // Maintain order for all dispatched events in this test.
            ->with(Mockery::on(function($event) {
                return $event instanceof LimitedFunctionalityModeActivatedEvent &&
                       $event->connectionName === $this->blockingName;
            }));

        // Act Step 1: This call sets the manager's currentActiveConnectionName to $this->blockingName.
        $this->manager->determineAndSetConnection();

        // Arrange Step 2: Setup for forceSwitchToPrimary.
        // Expect ConnectionStateManager to set statuses to HEALTHY.
        $this->stateManagerMock->shouldReceive('setConnectionStatus')->with($this->primaryName, ConnectionStatus::HEALTHY, 0)->once();
        $this->stateManagerMock->shouldReceive('setConnectionStatus')->with($this->failoverName, ConnectionStatus::HEALTHY, 0)->once();

        // Assert Step 2: Expect SwitchedToPrimaryConnectionEvent (from blocking to primary).
        $this->eventDispatcherMock->shouldReceive('dispatch')
            ->once() // For SwitchedToPrimaryConnectionEvent.
            ->ordered()
            ->with(Mockery::on(function ($event) {
                return $event instanceof SwitchedToPrimaryConnectionEvent &&
                       $event->previousConnectionName === $this->blockingName && // Switched from blocking.
                       $event->newConnectionName === $this->primaryName;
            }));

        // Assert Step 2: Expect ExitedLimitedFunctionalityModeEvent, restored to primary.
        $this->eventDispatcherMock->shouldReceive('dispatch')
            ->once() // For ExitedLimitedFunctionalityModeEvent.
            ->ordered()
            ->with(Mockery::on(function ($event) {
                return $event instanceof ExitedLimitedFunctionalityModeEvent &&
                       $event->restoredToConnectionName === $this->primaryName;
            }));

        // Act Step 2: Call forceSwitchToPrimary.
        $this->manager->forceSwitchToPrimary();

        // Assert Step 2: Verify the manager now reports primary as the active connection.
        $this->assertEquals($this->primaryName, $this->manager->getCurrentActiveConnectionName(), "Manager should report primary as current active connection after force switch from blocking.");
    }
}
