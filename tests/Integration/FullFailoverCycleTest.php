<?php

namespace Nuxgame\LaravelDynamicDBFailover\Tests\Integration;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Nuxgame\LaravelDynamicDBFailover\DynamicDBFailoverServiceProvider;
use Nuxgame\LaravelDynamicDBFailover\Enums\ConnectionStatus;
use Nuxgame\LaravelDynamicDBFailover\Events\DatabaseConnectionSwitchedEvent;
use Nuxgame\LaravelDynamicDBFailover\Events\LimitedFunctionalityModeActivatedEvent;
use Nuxgame\LaravelDynamicDBFailover\Events\PrimaryConnectionRestoredEvent;
use Nuxgame\LaravelDynamicDBFailover\Exceptions\AllDatabaseConnectionsUnavailableException;
use Nuxgame\LaravelDynamicDBFailover\HealthCheck\ConnectionStateManager;
use Nuxgame\LaravelDynamicDBFailover\Services\DatabaseFailoverManager;
use Orchestra\Testbench\TestCase;
use Illuminate\Contracts\Config\Repository as ConfigRepositoryContract;
use Illuminate\Database\DatabaseManager as IlluminateDBManager;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcherContract;
use Nuxgame\LaravelDynamicDBFailover\HealthCheck\ConnectionHealthChecker;
use Illuminate\Contracts\Cache\Factory as CacheFactoryContract;
use PHPUnit\Framework\Attributes\Test;
use Nuxgame\LaravelDynamicDBFailover\Events\SwitchedToPrimaryConnectionEvent;
use Nuxgame\LaravelDynamicDBFailover\Events\SwitchedToFailoverConnectionEvent;
use Nuxgame\LaravelDynamicDBFailover\Events\ExitedLimitedFunctionalityModeEvent;

/**
 * Integration test for the full database failover and recovery cycle.
 *
 * This test simulates a sequence of events:
 * 1. Initial state: Primary connection is UP and active.
 * 2. Primary connection goes DOWN; system switches to Failover connection.
 * 3. Failover connection also goes DOWN; system switches to Blocking connection (Limited Functionality Mode).
 * 4. Primary connection comes back UP; system switches back to Primary connection and exits Limited Functionality Mode.
 *
 * It verifies that the correct database connection is active at each stage and that
 * appropriate events are dispatched.
 *
 * This test class aims to provide comprehensive coverage of the failover logic
 * by simulating a complete cycle of connection failures and recoveries.
 */
class FullFailoverCycleTest extends TestCase
{
    use MockeryPHPUnitIntegration; // Trait to integrate Mockery with PHPUnit for easier mocking.

    /** @var array Stores the original configuration for the primary database connection. Used to restore state in tearDown. */
    protected array $originalPrimaryDbConfig = [];
    /** @var array Stores the original configuration for the failover database connection. Used to restore state in tearDown. */
    protected array $originalFailoverDbConfig = [];

    /** @var string Name of the primary test database connection, used throughout the test for clarity and consistency. */
    protected string $primaryConnectionName = 'mysql_primary_test';
    /** @var string Name of the failover test database connection, used throughout the test for clarity and consistency. */
    protected string $failoverConnectionName = 'mysql_failover_test';
    /** @var string Name of the blocking test database connection, used for Limited Functionality Mode. */
    protected string $blockingConnectionName = 'blocking_test';


    /**
     * Get package providers. Used by Orchestra Testbench.
     *
     * This method is required by Orchestra Testbench to load the package's service provider
     * during the application bootstrapping process for testing.
     *
     * @param \Illuminate\Foundation\Application $app The application instance.
     * @return array An array containing the service provider class.
     */
    protected function getPackageProviders($app): array
    {
        return [DynamicDBFailoverServiceProvider::class];
    }

    /**
     * Define environment setup. Used by Orchestra Testbench.
     *
     * Configures package settings (enabling failover, connection names, health check parameters, cache settings),
     * sets up dummy database connections (primary, failover, blocking) using environment variables for credentials,
     * and configures Redis for caching.
     *
     * This method is crucial for setting up a consistent and controlled testing environment.
     * It ensures that the package operates with specific configurations tailored for the tests,
     * isolating them from any global or development settings.
     *
     * @param \Illuminate\Foundation\Application $app The application instance.
     */
    protected function getEnvironmentSetUp($app): void
    {
        // Configure package settings
        Config::set('dynamic_db_failover.enabled', true);
        Config::set('dynamic_db_failover.connections.primary', $this->primaryConnectionName);
        Config::set('dynamic_db_failover.connections.failover', $this->failoverConnectionName);
        Config::set('dynamic_db_failover.connections.blocking', $this->blockingConnectionName);
        Config::set('dynamic_db_failover.health_check.failure_threshold', 1); // Lower threshold for faster testing cycles
        Config::set('dynamic_db_failover.health_check.query', 'SELECT 1');
        Config::set('dynamic_db_failover.cache.ttl_seconds', 60);
        Config::set('dynamic_db_failover.cache.prefix', 'db_failover_status_');
        Config::set('dynamic_db_failover.cache.store', 'redis'); // Using Redis for integration testing cache behavior

        // Configure database connections for testing
        Config::set('database.default', $this->primaryConnectionName);
        Config::set('database.connections.' . $this->primaryConnectionName, [
            'driver' => 'mysql',
            'host' => env('DB_HOST_PRIMARY_TEST', 'db_primary'), // Docker Compose service name or actual host
            'port' => env('DB_PORT_PRIMARY_TEST', 3306),
            'database' => env('DB_DATABASE_PRIMARY_TEST', 'laravel_primary'),
            'username' => env('DB_USERNAME_PRIMARY_TEST', 'user'),
            'password' => env('DB_PASSWORD_PRIMARY_TEST', 'password'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                \PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'), // For SSL connections if needed
                \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true, // Recommended for some drivers/setups
            ]) : [\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true],
        ]);
        Config::set('database.connections.' . $this->failoverConnectionName, [
            'driver' => 'mysql',
            'host' => env('DB_HOST_FAILOVER_TEST', 'db_failover'), // Docker Compose service name or actual host
            'port' => env('DB_PORT_FAILOVER_TEST', 3306),
            'database' => env('DB_DATABASE_FAILOVER_TEST', 'laravel_failover'),
            'username' => env('DB_USERNAME_FAILOVER_TEST', 'user'),
            'password' => env('DB_PASSWORD_FAILOVER_TEST', 'password'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                \PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
                \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ]) : [\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true],
        ]);
        Config::set('database.connections.' . $this->blockingConnectionName, [
            'driver' => 'blocking', // Uses the custom blocking driver
            'name' => $this->blockingConnectionName,
            'database' => 'fake_blocking_db', // Dummy value, not actually used
            'prefix' => '',
        ]);

        // Configure cache to use Redis for testing
        if (extension_loaded('redis')) {
            Config::set('database.redis.client', 'phpredis'); // Prefer phpredis if available
        } elseif (class_exists('\\Predis\\Client')) {
            Config::set('database.redis.client', 'predis');
        } else {
            Log::warning('PHP Redis extension (phpredis) or Predis library not found. Redis cache may not function correctly in tests.');
        }
        Config::set('cache.default', 'redis');
        Config::set('cache.stores.redis.connection', 'default_redis_testing'); // Use a dedicated Redis connection for tests
        Config::set('database.redis.default_redis_testing', [
            'host' => env('REDIS_HOST', 'redis_cache'), // Docker Compose service name or actual host
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', 6379),
            'database' => 0, // Use a specific Redis DB for testing to avoid conflicts
        ]);
    }

    /**
     * Sets up the test environment before each test method execution.
     *
     * This involves:
     * - Faking specific events to allow assertion of their dispatch.
     * - Re-binding `DatabaseFailoverManager` and `ConnectionStateManager` as singletons
     *   to ensure they use the faked EventDispatcher provided by `Event::fake()`.
     * - Setting up permissive logging mocks to prevent log output during tests.
     * - Storing original database configurations to be restored in `tearDown`.
     * - Running database migrations for both primary and failover test connections.
     * - Clearing application config and cache, and flushing the package-specific cache store.
     * - Purging database connections to ensure fresh states.
     */
    protected function setUp(): void
    {
        parent::setUp(); // Boots the application, initial services are registered.

        // Fake events that are relevant to the failover cycle and asserted in tests.
        // This allows us to assert that specific events are dispatched during the test execution
        // without them actually triggering their listeners (like notifications or further jobs).
        Event::fake([
            LimitedFunctionalityModeActivatedEvent::class,
            PrimaryConnectionRestoredEvent::class,
            SwitchedToPrimaryConnectionEvent::class,
            SwitchedToFailoverConnectionEvent::class,
            ExitedLimitedFunctionalityModeEvent::class,
        ]);

        // Re-bind core services as singletons, ensuring they use the application's
        // (now faked) EventDispatcher instance. This is crucial because the services
        // are resolved during application boot, potentially before Event::fake() is called.
        // Re-binding ensures that any events dispatched by these services are caught by Event::fake().
        $this->app->singleton(DatabaseFailoverManager::class, function ($app) {
            return new DatabaseFailoverManager(
                $app->make(ConfigRepositoryContract::class),
                $app->make(ConnectionStateManager::class), // Relies on ConnectionStateManager being re-bound if it also dispatches
                $app->make(IlluminateDBManager::class),
                $app->make(EventDispatcherContract::class) // This will resolve to EventFake
            );
        });

        $this->app->singleton(ConnectionStateManager::class, function ($app) {
            return new ConnectionStateManager(
                $app->make(ConnectionHealthChecker::class),
                $app->make(ConfigRepositoryContract::class),
                $app->make(EventDispatcherContract::class), // This will resolve to EventFake
                $app->make(CacheFactoryContract::class)
            );
        });

        // Suppress log messages to keep test output clean.
        // Mocking Log facade methods to prevent actual logging during test runs,
        // which helps in keeping the test console output focused on test results.
        // @phpstan-ignore-next-line
        Log::shouldReceive('critical')->zeroOrMoreTimes()->andReturnNull();
        // @phpstan-ignore-next-line
        Log::shouldReceive('error')->zeroOrMoreTimes()->andReturnNull();
        // @phpstan-ignore-next-line
        Log::shouldReceive('warning')->zeroOrMoreTimes()->andReturnNull();
        // @phpstan-ignore-next-line
        Log::shouldReceive('info')->zeroOrMoreTimes()->andReturnNull();
        // @phpstan-ignore-next-line
        Log::shouldReceive('debug')->zeroOrMoreTimes()->andReturnNull();

        // Store original DB configurations for restoration in tearDown.
        $this->originalPrimaryDbConfig = Config::get('database.connections.' . $this->primaryConnectionName);
        $this->originalFailoverDbConfig = Config::get('database.connections.' . $this->failoverConnectionName);

        // Run migrations on the test databases.
        $this->runMigrationsForConnection($this->primaryConnectionName);
        $this->runMigrationsForConnection($this->failoverConnectionName);

        // Clear Laravel's configuration and general cache.
        $this->artisan('config:clear');
        $this->artisan('cache:clear');

        // Flush the specific cache store used by the package and purge DB connections.
        /** @var \\Illuminate\\Contracts\\Cache\\Repository $cacheStore */
        $cacheStore = Cache::store(Config::get('dynamic_db_failover.cache.store'));
        $cacheStore->flush();
        DB::purge($this->primaryConnectionName);
        DB::purge($this->failoverConnectionName);
        DB::purge($this->blockingConnectionName);
    }

    /**
     * Cleans up the test environment after each test method execution.
     *
     * Restores original database configurations and purges connections.
     */
    protected function tearDown(): void
    {
        // Restore original database configurations.
        // This is important to prevent test pollution, ensuring that changes made to
        // configurations during one test do not affect subsequent tests.
        Config::set('database.connections.' . $this->primaryConnectionName, $this->originalPrimaryDbConfig);
        Config::set('database.connections.' . $this->failoverConnectionName, $this->originalFailoverDbConfig);

        // Purge connections to ensure a clean state for the next test.
        // This removes any cached connection instances, forcing Laravel to re-establish
        // connections based on the (potentially restored) configuration.
        DB::purge($this->primaryConnectionName);
        DB::purge($this->failoverConnectionName);
        DB::purge($this->blockingConnectionName);

        parent::tearDown(); // Handles Mockery::close, etc.
    }

    /**
     * Runs database migrations for a specified connection.
     *
     * Publishes migrations if not already present and then runs `migrate:fresh`.
     * This ensures that the test databases have the correct schema for the tests.
     * The `migrate:fresh` command drops all tables and re-runs all migrations.
     *
     * @param string $connectionName The name of the database connection to run migrations on.
     */
    protected function runMigrationsForConnection(string $connectionName): void
    {
        $migrationsPath = realpath(base_path('database/migrations'));
        // If standard migrations path doesn't exist, publish Laravel's default migrations.
        if (!$migrationsPath || !is_dir($migrationsPath)) {
            Artisan::call('vendor:publish', ['--provider' => 'Illuminate\\Foundation\\Providers\\FoundationServiceProvider', '--tag' => 'migrations']);
            $migrationsPath = realpath(base_path('database/migrations')); // Re-check path after publish
        }

        if ($migrationsPath && is_dir($migrationsPath)) {
            Artisan::call('migrate:fresh', [
                '--database' => $connectionName,
                '--path' => $migrationsPath, // Use the determined path
                '--realpath' => true,
                '--quiet' => true, // Suppress migration output
            ]);
        } else {
            // Output a warning if migrations cannot be run (should not happen in a typical setup).
            echo "Warning: Migrations path not found or not a directory ('{$migrationsPath}') for connection {$connectionName}. Skipping migrations.\n";
        }
    }

    /**
     * Simulates a database connection going down by changing its configuration to an invalid port.
     *
     * This method modifies the port number in the connection's configuration to a value
     * that is highly unlikely to have a listening service, effectively making the connection
     * unavailable. It then purges the connection from Laravel's manager to ensure the
     * new, faulty configuration is used on the next attempt.
     *
     * @param string $connectionName The name of the connection to simulate as down.
     */
    protected function simulateConnectionDown(string $connectionName): void
    {
        $configKey = 'database.connections.' . $connectionName;
        $currentConfig = Config::get($configKey);
        // Merge current config with a port that is unlikely to be listened on.
        $faultyConfig = array_merge($currentConfig, ['port' => 9999]);
        Config::set($configKey, $faultyConfig);
        DB::purge($connectionName); // Purge to force re-evaluation of connection details.
    }

    /**
     * Simulates a database connection coming back up by restoring its original configuration.
     *
     * This method restores the original, valid configuration for the specified connection.
     * It uses the configurations stored during the `setUp` phase. After restoring, it purges
     * the connection so that Laravel uses the restored configuration when the connection is next needed.
     *
     * @param string $connectionName The name of the connection to simulate as up.
     */
    protected function simulateConnectionUp(string $connectionName): void
    {
        $configKey = 'database.connections.' . $connectionName;
        // Restore the original configuration based on the connection name.
        if ($connectionName === $this->primaryConnectionName) {
            Config::set($configKey, $this->originalPrimaryDbConfig);
        } elseif ($connectionName === $this->failoverConnectionName) {
            Config::set($configKey, $this->originalFailoverDbConfig);
        }
        DB::purge($connectionName); // Purge to force re-evaluation.
    }

    /**
     * The main integration test for the full failover and recovery cycle.
     * It executes a sequence of steps simulating connection failures and recoveries,
     * asserting the active database connection and dispatched events at each stage.
     * @test
     */
    #[Test]
    public function it_runs_full_failover_cycle_and_recovery(): void
    {
        /** @var ConnectionStateManager $stateManager Instantiated from the app container. */
        $stateManager = $this->app->make(ConnectionStateManager::class);
        /** @var DatabaseFailoverManager $failoverManager Instantiated from the app container. */
        $failoverManager = $this->app->make(DatabaseFailoverManager::class);

        // Helper closure to encapsulate the common actions of updating connection status(es)
        // and then having the DatabaseFailoverManager determine and set the active connection.
        $checkAndUpdate = function (string $connectionToManuallyCheck = null) use ($stateManager, $failoverManager) {
            if ($connectionToManuallyCheck) {
                // If a specific connection is provided, only update its status.
                $stateManager->updateConnectionStatus($connectionToManuallyCheck);
            } else {
                // Otherwise, update status for both primary and failover.
                $stateManager->updateConnectionStatus($this->primaryConnectionName);
                $stateManager->updateConnectionStatus($this->failoverConnectionName);
            }
            $failoverManager->determineAndSetConnection(); // Trigger connection logic.
        };

        // --- Step 0: Initial cleanup and state reset ---
        // The service provider's boot() method (via parent::setUp() -> refreshApplication()) might have run
        // determineAndSetConnection. We flush cache and reset statuses to ensure a clean start for this test's sequence.
        Log::info("TEST STEP 0: Initializing state for failover cycle test.");
        /** @var \\Illuminate\\Contracts\\Cache\\Repository $cacheStore */
        $cacheStore = Cache::store(Config::get('dynamic_db_failover.cache.store'));
        $cacheStore->flush();
        $stateManager->setConnectionStatus($this->primaryConnectionName, ConnectionStatus::UNKNOWN, 0);
        $stateManager->setConnectionStatus($this->failoverConnectionName, ConnectionStatus::UNKNOWN, 0);


        // --- Step 1: Initial state - Primary UP and active ---
        Log::info("TEST STEP 1: Initial state, primary should be healthy and active.");
        $checkAndUpdate(); // Check both connections; primary should be found healthy.
        $this->assertEquals($this->primaryConnectionName, DB::getDefaultConnection(), "Step 1 Failed: Default connection should be primary.");
        DB::purge($this->primaryConnectionName); // Ensure next DB call uses fresh connection details.
        DB::connection($this->primaryConnectionName)->select('SELECT 1'); // Verify connectivity.
        // No SwitchedToPrimary/Failover event expected if it initializes to primary correctly.

        // --- Step 2: Primary goes DOWN - Failover should become active ---
        Log::info("TEST STEP 2: Primary goes down, system should switch to failover.");
        $this->simulateConnectionDown($this->primaryConnectionName);
        $checkAndUpdate($this->primaryConnectionName); // Check primary; it will fail (threshold is 1).
        $this->assertEquals($this->failoverConnectionName, DB::getDefaultConnection(), "Step 2 Failed: Default connection should be failover.");
        DB::purge($this->failoverConnectionName);
        DB::connection($this->failoverConnectionName)->select('SELECT 1'); // Verify failover connectivity.
        Event::assertDispatched(SwitchedToFailoverConnectionEvent::class, function ($event) {
            return $event->newConnectionName === $this->failoverConnectionName &&
                   $event->previousConnectionName === $this->primaryConnectionName;
        });
        Event::assertNotDispatched(ExitedLimitedFunctionalityModeEvent::class); // Should not exit LFM if not in it.

        // --- Step 3: Failover also goes DOWN - Blocking connection (LFM) should become active ---
        Log::info("TEST STEP 3: Failover also goes down, system should switch to blocking (LFM).");
        $this->simulateConnectionDown($this->failoverConnectionName);
        $checkAndUpdate($this->failoverConnectionName); // Check failover; it will also fail.
        $this->assertEquals($this->blockingConnectionName, DB::getDefaultConnection(), "Step 3 Failed: Default connection should be blocking.");
        Event::assertDispatched(LimitedFunctionalityModeActivatedEvent::class, function ($event) {
            return $event->connectionName === $this->blockingConnectionName;
        });
        // Ensure other switch events are not re-dispatched inappropriately.
        Event::assertNotDispatched(SwitchedToFailoverConnectionEvent::class, function ($event) {
            // Filter out the event from Step 2 to ensure this assertion is about Step 3 specifically.
            return $event->previousConnectionName !== $this->primaryConnectionName;
        });
        Event::assertNotDispatched(ExitedLimitedFunctionalityModeEvent::class);

        // Verify that using the blocking connection throws the expected exception.
        try {
            DB::connection($this->blockingConnectionName)->select('SELECT 1');
            $this->fail("Step 3 Failed: AllDatabaseConnectionsUnavailableException was not thrown for blocking connection.");
        } catch (AllDatabaseConnectionsUnavailableException $e) {
            // Expected behavior: exception is thrown.
            $this->assertTrue(true, "Correctly threw AllDatabaseConnectionsUnavailableException for blocking connection.");
        }

        // --- Step 4: Primary comes back UP - Should switch back to Primary and exit LFM ---
        Log::info("TEST STEP 4: Primary comes back online, system should switch back to primary and exit LFM.");
        $this->simulateConnectionUp($this->primaryConnectionName);
        $checkAndUpdate($this->primaryConnectionName); // Check primary; it should be found healthy.
        $this->assertEquals($this->primaryConnectionName, DB::getDefaultConnection(), "Step 4 Failed: Default connection should be restored to primary.");
        DB::purge($this->primaryConnectionName);
        DB::connection($this->primaryConnectionName)->select('SELECT 1'); // Verify primary connectivity.
        Event::assertDispatched(PrimaryConnectionRestoredEvent::class, function ($event) {
            return $event->connectionName === $this->primaryConnectionName;
        });
        Event::assertDispatched(SwitchedToPrimaryConnectionEvent::class, function ($event) {
            return $event->newConnectionName === $this->primaryConnectionName &&
                   $event->previousConnectionName === $this->blockingConnectionName; // Switched from LFM (blocking)
        });
        Event::assertDispatched(ExitedLimitedFunctionalityModeEvent::class, function ($event) {
            return $event->restoredToConnectionName === $this->primaryConnectionName;
        });
    }
}
