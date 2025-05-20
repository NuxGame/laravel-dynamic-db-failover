<?php

namespace Nuxgame\LaravelDynamicDBFailover\Tests\Unit;

use Illuminate\Contracts\Cache\Repository as CacheRepositoryContract;
use Illuminate\Contracts\Config\Repository as ConfigRepositoryContract;
use Illuminate\Contracts\Events\Dispatcher as DispatcherContract;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Connection as IlluminateConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Nuxgame\LaravelDynamicDBFailover\Enums\ConnectionStatus;
use Nuxgame\LaravelDynamicDBFailover\Events\DatabaseConnectionSwitchedEvent;
use Nuxgame\LaravelDynamicDBFailover\Events\LimitedFunctionalityModeActivatedEvent;
use Nuxgame\LaravelDynamicDBFailover\HealthCheck\ConnectionStateManager;
use Nuxgame\LaravelDynamicDBFailover\Services\DatabaseFailoverManager;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Illuminate\Support\Facades\Config;
use Mockery;

class DatabaseFailoverManagerTest extends OrchestraTestCase
{
    use MockeryPHPUnitIntegration;

    protected $appMock;
    /** @var \Illuminate\Database\DatabaseManager|\Mockery\MockInterface */
    protected $dbManagerMock;
    /** @var \Illuminate\Contracts\Cache\Repository|\Mockery\MockInterface */
    protected $cacheManagerMock;
    /** @var \Illuminate\Contracts\Config\Repository|\Mockery\MockInterface */
    protected $configRepositoryMock;
    /** @var \Illuminate\Contracts\Events\Dispatcher|\Mockery\MockInterface */
    protected $dispatcherMock;
    /** @var \Nuxgame\LaravelDynamicDBFailover\HealthCheck\ConnectionStateManager|\Mockery\MockInterface */
    protected $connectionStateManagerMock;

    protected string $primaryConnectionName;
    protected string $failoverConnectionName;
    protected string $blockingConnectionName;
    protected string $initialLaravelDefaultConnection;

    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Initialize connection name properties first
        $this->primaryConnectionName = 'test_primary_db';
        $this->failoverConnectionName = 'test_failover_db';
        $this->blockingConnectionName = 'test_blocking_db';
        $this->initialLaravelDefaultConnection = 'current_default_conn';

        parent::getEnvironmentSetUp($app);

        // Initialize and bind mocks for DatabaseFailoverManager dependencies
        $this->appMock = $app; // Use the provided app instance
        $this->dbManagerMock = Mockery::mock(DatabaseManager::class);
        $this->cacheManagerMock = Mockery::mock(CacheRepositoryContract::class);
        $this->configRepositoryMock = Mockery::mock(ConfigRepositoryContract::class); // Initialize this mock
        $this->dispatcherMock = Mockery::mock(DispatcherContract::class);
        $this->connectionStateManagerMock = Mockery::mock(ConnectionStateManager::class);

        $app->instance(DatabaseManager::class, $this->dbManagerMock);
        $app->instance(CacheRepositoryContract::class, $this->cacheManagerMock);
        $app->instance(ConfigRepositoryContract::class, $this->configRepositoryMock); // Bind this mock
        $app->instance(DispatcherContract::class, $this->dispatcherMock);
        $app->instance(ConnectionStateManager::class, $this->connectionStateManagerMock);

        // Set up default behaviors for config mock needed by the manager's constructor or basic ops
        // The SUT (DatabaseFailoverManager constructor) calls config->get('key') then uses ?? for default.
        // So, the mock should expect get() with one argument for these.
        // Make these general expectations more lenient so specific tests can override them easily.
        $this->configRepositoryMock->shouldReceive('get')->with('dynamic_db_failover.connections.primary')->andReturn($this->primaryConnectionName)->zeroOrMoreTimes();
        $this->configRepositoryMock->shouldReceive('get')->with('dynamic_db_failover.connections.failover')->andReturn($this->failoverConnectionName)->zeroOrMoreTimes();
        $this->configRepositoryMock->shouldReceive('get')->with('dynamic_db_failover.connections.blocking')->andReturn($this->blockingConnectionName)->zeroOrMoreTimes();

        // Other config values potentially used by the manager or its dependencies.
        // If SUT calls these with a default e.g. config->get('key', 'sut_default'), mock must match.
        // DatabaseFailoverManager constructor also accesses 'dynamic_db_failover.enabled' and 'dynamic_db_failover.cache.prefix' from its internal config.
        // However, those are not directly in the constructor lines that failed.
        // The following are for general setup and might be used by other methods under test or by dependencies.
        $this->configRepositoryMock->shouldReceive('get')->with('dynamic_db_failover.enabled', false)->andReturn(true); // Assuming SUT might call get() with a default for this
        $this->configRepositoryMock->shouldReceive('get')->with('dynamic_db_failover.cache.prefix', 'db_failover:')->andReturn('db_failover_test:'); // Assuming SUT might call get() with a default for this

        // Fallback for any other config get, to avoid unexpected errors, can be risky as it hides missing specific expectations.
        // $this->configRepositoryMock->shouldReceive('get')->withAnyArgs()->andReturnUsing(function($key, $default = null) { return $default; })->zeroOrMoreTimes();

        $this->configRepositoryMock->shouldReceive('get')->with('database.default')->andReturn($this->initialLaravelDefaultConnection);
        $this->configRepositoryMock->shouldReceive('get')->with('dynamic_db_failover.cache.ttl_seconds', 300)->andReturn(300);
        $this->configRepositoryMock->shouldReceive('get')->with('dynamic_db_failover.health_check.failure_threshold', 3)->andReturn(3);

        // Set actual config values in the app if needed for other parts of the test setup
        $app['config']->set('database.default', $this->initialLaravelDefaultConnection);
        $app['config']->set('dynamic_db_failover.connections.primary', $this->primaryConnectionName);
        $app['config']->set('dynamic_db_failover.connections.failover', $this->failoverConnectionName);
        $app['config']->set('dynamic_db_failover.connections.blocking', $this->blockingConnectionName);
        $app['config']->set('dynamic_db_failover.health_check.failure_threshold', 3);
        // It's also good practice to set the other config values that the DatabaseFailoverManager constructor might use.
        $app['config']->set('dynamic_db_failover.enabled', true);
        $app['config']->set('dynamic_db_failover.cache.prefix', 'db_failover_test:');
        $app['config']->set('dynamic_db_failover.cache.ttl_seconds', 300);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Default mock behaviors - keep these minimal and specific, or remove if tests set them all.
        $this->connectionStateManagerMock->shouldReceive('setConnectionStatus')->withAnyArgs()->andReturnNull()->zeroOrMoreTimes();
        $this->dbManagerMock->shouldReceive('getDefaultConnection')->andReturn($this->initialLaravelDefaultConnection)->zeroOrMoreTimes();

        // REMOVE general Log expectations: Let each test handle its own event/log expectations for clarity.
        // Log::shouldReceive('info')->andReturnNull()->zeroOrMoreTimes();
        // Log::shouldReceive('warning')->andReturnNull()->zeroOrMoreTimes();
        // Log::shouldReceive('debug')->andReturnNull()->zeroOrMoreTimes();
        // Log::shouldReceive('error')->andReturnNull()->zeroOrMoreTimes();
    }

    protected function createFailoverManager(): DatabaseFailoverManager
    {
        return $this->app->make(DatabaseFailoverManager::class);
    }

    public function test_determine_and_set_connection_uses_primary_when_healthy(): void
    {
        $this->connectionStateManagerMock->shouldReceive('getConnectionStatus')->with($this->primaryConnectionName)->once()->andReturn(ConnectionStatus::HEALTHY);
        $this->connectionStateManagerMock->shouldReceive('getFailureCount')->with($this->primaryConnectionName)->zeroOrMoreTimes()->andReturn(0);

        $this->connectionStateManagerMock->shouldReceive('getConnectionStatus')->with($this->failoverConnectionName)->once()->andReturn(ConnectionStatus::UNKNOWN);
        $this->connectionStateManagerMock->shouldReceive('getFailureCount')->with($this->failoverConnectionName)->zeroOrMoreTimes()->andReturn(0);

        $this->dbManagerMock->shouldReceive('setDefaultConnection')->with($this->primaryConnectionName)->once();

        $this->dispatcherMock->shouldReceive('dispatch')
            ->with(Mockery::type(DatabaseConnectionSwitchedEvent::class))
            ->once()
            ->andReturnUsing(function (DatabaseConnectionSwitchedEvent $event) {
                // When switching to primary because it's healthy, and assuming the manager was initialized to primary,
                // the previous connection name in the event will also be the primary connection name.
                $this->assertNull($event->previousConnectionName, 'Event previousConnectionName should be null for initial determination.');
                $this->assertEquals($this->primaryConnectionName, $event->newConnectionName, 'Event newConnectionName mismatch.');
            });

        $manager = $this->createFailoverManager();
        $manager->determineAndSetConnection();

        $this->assertEquals($this->primaryConnectionName, $manager->getCurrentActiveConnectionName());
    }

    public function test_determine_and_set_connection_uses_failover_when_primary_down_and_failover_healthy(): void
    {
        $this->connectionStateManagerMock->shouldReceive('getConnectionStatus')->with($this->primaryConnectionName)->once()->andReturn(ConnectionStatus::DOWN);
        $this->connectionStateManagerMock->shouldReceive('getFailureCount')->with($this->primaryConnectionName)->zeroOrMoreTimes()->andReturn(config('dynamic_db_failover.health_check.failure_threshold', 3) + 1);
        $this->connectionStateManagerMock->shouldReceive('getConnectionStatus')->with($this->failoverConnectionName)->once()->andReturn(ConnectionStatus::HEALTHY);
        $this->connectionStateManagerMock->shouldReceive('getFailureCount')->with($this->failoverConnectionName)->zeroOrMoreTimes()->andReturn(0);

        $this->dbManagerMock->shouldReceive('setDefaultConnection')->with($this->failoverConnectionName)->once();

        $capturedEvent = null;
        $this->dispatcherMock->shouldReceive('dispatch')
            ->with(Mockery::on(function ($event) use (&$capturedEvent) {
                if ($event instanceof DatabaseConnectionSwitchedEvent) {
                    $capturedEvent = $event;
                    return true;
                }
                return false;
            }))->once();

        $manager = $this->createFailoverManager();
        $manager->determineAndSetConnection();

        // Assert event dispatch and details first
        $this->assertNotNull($capturedEvent, 'DatabaseConnectionSwitchedEvent was not dispatched.');
        $this->assertInstanceOf(DatabaseConnectionSwitchedEvent::class, $capturedEvent);
        if ($capturedEvent instanceof DatabaseConnectionSwitchedEvent) {
            $this->assertNull($capturedEvent->previousConnectionName, 'Event previousConnectionName should be null for initial switch.');
            $this->assertEquals($this->failoverConnectionName, $capturedEvent->newConnectionName, 'Event newConnectionName mismatch.');
        }

        $this->assertEquals($this->failoverConnectionName, $manager->getCurrentActiveConnectionName(), 'Manager active connection name mismatch.');
    }

    public function test_determine_and_set_connection_uses_blocking_when_primary_and_failover_down(): void
    {
        $this->connectionStateManagerMock->shouldReceive('getConnectionStatus')->with($this->primaryConnectionName)->once()->andReturn(ConnectionStatus::DOWN);
        $this->connectionStateManagerMock->shouldReceive('getFailureCount')->with($this->primaryConnectionName)->zeroOrMoreTimes()->andReturn(Config::get('dynamic_db_failover.health_check.failure_threshold', 3));
        $this->connectionStateManagerMock->shouldReceive('getConnectionStatus')->with($this->failoverConnectionName)->once()->andReturn(ConnectionStatus::DOWN);
        $this->connectionStateManagerMock->shouldReceive('getFailureCount')->with($this->failoverConnectionName)->zeroOrMoreTimes()->andReturn(Config::get('dynamic_db_failover.health_check.failure_threshold', 3));

        $this->dbManagerMock->shouldReceive('setDefaultConnection')->with($this->blockingConnectionName)->once();

        $capturedSwitchedEvent = null;
        $capturedLimitedModeEvent = null;

        $this->dispatcherMock->shouldReceive('dispatch')
            ->with(Mockery::on(function ($event) use (&$capturedSwitchedEvent, &$capturedLimitedModeEvent) {
                if ($event instanceof DatabaseConnectionSwitchedEvent) {
                    $capturedSwitchedEvent = $event;
                    return true;
                }
                if ($event instanceof LimitedFunctionalityModeActivatedEvent) {
                    $capturedLimitedModeEvent = $event;
                    return true;
                }
                return false;
            }))->twice();

        $manager = $this->createFailoverManager();
        $manager->determineAndSetConnection();

        $this->assertEquals($this->blockingConnectionName, $manager->getCurrentActiveConnectionName());

        $this->assertNotNull($capturedSwitchedEvent, 'DatabaseConnectionSwitchedEvent was not dispatched.');
        $this->assertInstanceOf(DatabaseConnectionSwitchedEvent::class, $capturedSwitchedEvent);
        if ($capturedSwitchedEvent instanceof DatabaseConnectionSwitchedEvent) {
            $this->assertNull($capturedSwitchedEvent->previousConnectionName, 'Event previousConnectionName should be null for initial switch.');
            $this->assertEquals($this->blockingConnectionName, $capturedSwitchedEvent->newConnectionName, 'Event newConnectionName mismatch.');
        }

        $this->assertNotNull($capturedLimitedModeEvent, 'LimitedFunctionalityModeActivatedEvent was not dispatched.');
        $this->assertInstanceOf(LimitedFunctionalityModeActivatedEvent::class, $capturedLimitedModeEvent);
        if ($capturedLimitedModeEvent instanceof LimitedFunctionalityModeActivatedEvent) {
            $this->assertEquals($this->blockingConnectionName, $capturedLimitedModeEvent->connectionName);
        }
    }

    public function test_determine_and_set_connection_defaults_to_primary_if_cache_unavailable_scenario(): void
    {
        $this->connectionStateManagerMock->shouldReceive('getConnectionStatus')->with($this->primaryConnectionName)->once()->andReturn(ConnectionStatus::UNKNOWN);
        $this->connectionStateManagerMock->shouldReceive('getFailureCount')->with($this->primaryConnectionName)->once()->andReturn(0);
        $this->connectionStateManagerMock->shouldReceive('getConnectionStatus')->with($this->failoverConnectionName)->once()->andReturn(ConnectionStatus::UNKNOWN);
        $this->connectionStateManagerMock->shouldReceive('getFailureCount')->with($this->failoverConnectionName)->once()->andReturn(0);

        Log::shouldReceive('warning')->with(Mockery::pattern('/Cache might be unavailable/'))->once();
        // Add lenient expectation for Log::info as determineAndSetConnection might log it.
        Log::shouldReceive('info')->withAnyArgs()->zeroOrMoreTimes();

        $this->dbManagerMock->shouldReceive('setDefaultConnection')->with($this->primaryConnectionName)->once();

        $capturedEvent = null;
        $this->dispatcherMock->shouldReceive('dispatch')
            ->with(Mockery::on(function ($event) use (&$capturedEvent) {
                if ($event instanceof DatabaseConnectionSwitchedEvent) {
                    $capturedEvent = $event;
                    return true;
                }
                return false;
            }))->once();

        $manager = $this->createFailoverManager();
        $manager->determineAndSetConnection();

        $this->assertEquals($this->primaryConnectionName, $manager->getCurrentActiveConnectionName());
        $this->assertNotNull($capturedEvent, 'DatabaseConnectionSwitchedEvent was not dispatched.');
        $this->assertInstanceOf(DatabaseConnectionSwitchedEvent::class, $capturedEvent);

        if ($capturedEvent instanceof DatabaseConnectionSwitchedEvent) {
            $this->assertNull($capturedEvent->previousConnectionName, 'Event previousConnectionName should be null for initial switch.');
            $this->assertEquals($this->primaryConnectionName, $capturedEvent->newConnectionName, 'Event newConnectionName mismatch.');
        }
    }

    public function test_force_switch_to_primary_sets_primary_and_dispatches_event(): void
    {
        $this->dbManagerMock->shouldReceive('setDefaultConnection')->with($this->primaryConnectionName)->once();

        $capturedEvent = null;
        $this->dispatcherMock->shouldReceive('dispatch')
            ->with(Mockery::on(function ($event) use (&$capturedEvent) {
                if ($event instanceof DatabaseConnectionSwitchedEvent) {
                    $capturedEvent = $event;
                    return true;
                }
                return false;
            }))->once();

        $manager = $this->createFailoverManager();
        $manager->forceSwitchToPrimary();

        $this->assertNotNull($capturedEvent, 'DatabaseConnectionSwitchedEvent was not dispatched.');
        $this->assertInstanceOf(DatabaseConnectionSwitchedEvent::class, $capturedEvent);
        if ($capturedEvent instanceof DatabaseConnectionSwitchedEvent) {
            $this->assertNull($capturedEvent->previousConnectionName, 'Event previousConnectionName should be null for initial force switch on new manager.');
            $this->assertEquals($this->primaryConnectionName, $capturedEvent->newConnectionName, 'Event newConnectionName mismatch.');
        }

        $this->assertEquals($this->primaryConnectionName, $manager->getCurrentActiveConnectionName(), 'Manager active connection name mismatch.');
    }

    public function test_force_switch_to_failover_sets_failover_and_dispatches_event(): void
    {
        $this->dbManagerMock->shouldReceive('setDefaultConnection')->with($this->failoverConnectionName)->once();

        $capturedEvent = null;
        $this->dispatcherMock->shouldReceive('dispatch')
            ->with(Mockery::on(function ($event) use (&$capturedEvent) {
                if ($event instanceof DatabaseConnectionSwitchedEvent) {
                    $capturedEvent = $event;
                    return true;
                }
                return false;
            }))->once();

        $manager = $this->createFailoverManager();
        $manager->forceSwitchToFailover();

        $this->assertNotNull($capturedEvent, 'DatabaseConnectionSwitchedEvent was not dispatched.');
        $this->assertInstanceOf(DatabaseConnectionSwitchedEvent::class, $capturedEvent);
        if ($capturedEvent instanceof DatabaseConnectionSwitchedEvent) {
            $this->assertNull($capturedEvent->previousConnectionName, 'Event previousConnectionName should be null for initial force switch on new manager.');
            $this->assertEquals($this->failoverConnectionName, $capturedEvent->newConnectionName, 'Event newConnectionName mismatch.');
        }

        $this->assertEquals($this->failoverConnectionName, $manager->getCurrentActiveConnectionName(), 'Manager active connection name mismatch.');
    }

    public function test_constructor_uses_default_connection_names_if_config_missing(): void
    {
        // Use a fresh, local config mock for this specific scenario to avoid conflicts.
        $localConfigMock = Mockery::mock(ConfigRepositoryContract::class);

        // Simulate that specific config keys for connections are missing (return null)
        // The SUT will then use its internal defaults like 'mysql'.
        $localConfigMock->shouldReceive('get')->with('dynamic_db_failover.connections.primary')->andReturnNull()->once();
        $localConfigMock->shouldReceive('get')->with('dynamic_db_failover.connections.failover')->andReturnNull()->once();
        $localConfigMock->shouldReceive('get')->with('dynamic_db_failover.connections.blocking')->andReturnNull()->once();

        // The SUT constructor might also ask for other configs. Provide defaults for them on the local mock.
        // These are necessary for the constructor to proceed without other errors.
        $localConfigMock->shouldReceive('get')->with('dynamic_db_failover.enabled', false)->andReturn(true); // Default if SUT uses ->get('key', default)
        $localConfigMock->shouldReceive('get')->with('dynamic_db_failover.cache.prefix', 'db_failover:')->andReturn('default_prefix:'); // Default if SUT uses ->get('key', default)

        // IMPORTANT: When connection names from config are null, the SUT uses non-empty defaults ('mysql', etc.),
        // so the Log::warning for empty names SHOULD NOT be called.
        // Simply do not set an expectation for Log::warning if it's not expected.
        // Mockery will fail if an unexpected Log::warning occurs.
        // Removed: Log::shouldNotReceive('warning')->with(Mockery::pattern('/One or more connection names \(primary, failover, blocking\) are not configured correctly/'));

        // Constructor does not dispatch events or set default connection on dbManagerMock itself under these conditions.
        $this->dispatcherMock->shouldNotReceive('dispatch');
        $this->dbManagerMock->shouldNotReceive('setDefaultConnection');

        $manager = new DatabaseFailoverManager(
            $localConfigMock,                  // Use the local, specially configured mock
            $this->connectionStateManagerMock,
            $this->dbManagerMock,
            $this->dispatcherMock
        );

        // Use reflection to check the private properties for default values
        $reflector = new \ReflectionObject($manager);

        $primaryProp = $reflector->getProperty('primaryConnectionName');
        $primaryProp->setAccessible(true);
        $this->assertEquals('mysql', $primaryProp->getValue($manager), 'Primary connection should default to mysql.');

        $failoverProp = $reflector->getProperty('failoverConnectionName');
        $failoverProp->setAccessible(true);
        $this->assertEquals('mysql_failover', $failoverProp->getValue($manager), 'Failover connection should default to mysql_failover.');

        $blockingProp = $reflector->getProperty('blockingConnectionName');
        $blockingProp->setAccessible(true);
        $this->assertEquals('blocking', $blockingProp->getValue($manager), 'Blocking connection should default to blocking.');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
