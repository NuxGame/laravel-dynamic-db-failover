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

class FullFailoverCycleTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected array $originalPrimaryDbConfig = [];
    protected array $originalFailoverDbConfig = [];

    protected string $primaryConnectionName = 'mysql_primary_test';
    protected string $failoverConnectionName = 'mysql_failover_test';
    protected string $blockingConnectionName = 'blocking_test';


    protected function getPackageProviders($app): array
    {
        return [DynamicDBFailoverServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Configure package settings
        Config::set('dynamic_db_failover.enabled', true);
        Config::set('dynamic_db_failover.connections.primary', $this->primaryConnectionName);
        Config::set('dynamic_db_failover.connections.failover', $this->failoverConnectionName);
        Config::set('dynamic_db_failover.connections.blocking', $this->blockingConnectionName);
        Config::set('dynamic_db_failover.health_check.failure_threshold', 1); // Lower threshold for faster testing
        Config::set('dynamic_db_failover.health_check.query', 'SELECT 1');
        Config::set('dynamic_db_failover.cache.ttl_seconds', 60);
        Config::set('dynamic_db_failover.cache.prefix', 'db_failover_status_');
        Config::set('dynamic_db_failover.cache.store', 'redis');

        // Configure database connections
        Config::set('database.default', $this->primaryConnectionName);
        Config::set('database.connections.' . $this->primaryConnectionName, [
            'driver' => 'mysql',
            'host' => env('DB_HOST_PRIMARY_TEST', 'db_primary'),
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
                \PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
                \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ]) : [\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true],
        ]);
        Config::set('database.connections.' . $this->failoverConnectionName, [
            'driver' => 'mysql',
            'host' => env('DB_HOST_FAILOVER_TEST', 'db_failover'),
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
            'driver' => 'blocking',
            'name' => $this->blockingConnectionName,
            'database' => 'fake_blocking_db',
            'prefix' => '',
        ]);

        // Configure cache
        // Ensure a Redis client is specified, prefer phpredis if available
        if (extension_loaded('redis')) {
            Config::set('database.redis.client', 'phpredis');
        } elseif (class_exists('\\Predis\\Client')) {
            Config::set('database.redis.client', 'predis');
        } else {
            // If neither is found, tests relying on Redis might fail or be skipped.
            // This setup assumes one will be available in the test environment.
            // Consider adding a clear warning or failing fast if a Redis client is mandatory.
            Log::warning('PHP Redis extension (phpredis) or Predis library not found. Redis cache may not function correctly in tests.');
        }
        Config::set('cache.default', 'redis');
        Config::set('cache.stores.redis.connection', 'default_redis_testing');
        Config::set('database.redis.default_redis_testing', [
            'host' => env('REDIS_HOST', 'redis_cache'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', 6379),
            'database' => 0,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp(); // App boots, services get real dispatcher initially

        Event::fake([
            DatabaseConnectionSwitchedEvent::class,
            LimitedFunctionalityModeActivatedEvent::class,
            PrimaryConnectionRestoredEvent::class,
        ]);

        // Re-bind critical services to ensure they use the faked EventDispatcher
        // DatabaseFailoverManager
        $this->app->singleton(DatabaseFailoverManager::class, function ($app) {
            return new DatabaseFailoverManager(
                $app->make(ConfigRepositoryContract::class),
                $app->make(ConnectionStateManager::class), // This will use the re-bound CSM if it was already re-bound
                $app->make(IlluminateDBManager::class),
                $app->make(EventDispatcherContract::class) // This will resolve to EventFake
            );
        });

        // ConnectionStateManager (as it also uses EventDispatcher)
        $this->app->singleton(ConnectionStateManager::class, function ($app) {
            return new ConnectionStateManager(
                $app->make(ConnectionHealthChecker::class),
                $app->make(ConfigRepositoryContract::class),
                $app->make(EventDispatcherContract::class), // This will resolve to EventFake
                $app->make(CacheFactoryContract::class)
            );
        });

        // The line below from previous attempt is not needed if re-binding singletons
        // $this->app->instance(\Illuminate\Contracts\Events\Dispatcher::class, $this->app['events']);

        // Make Log facade permissive
        Log::shouldReceive('critical')->zeroOrMoreTimes()->andReturnNull();
        Log::shouldReceive('error')->zeroOrMoreTimes()->andReturnNull();
        Log::shouldReceive('warning')->zeroOrMoreTimes()->andReturnNull();
        Log::shouldReceive('info')->zeroOrMoreTimes()->andReturnNull();
        Log::shouldReceive('debug')->zeroOrMoreTimes()->andReturnNull();

        $this->originalPrimaryDbConfig = Config::get('database.connections.' . $this->primaryConnectionName);
        $this->originalFailoverDbConfig = Config::get('database.connections.' . $this->failoverConnectionName);

        $this->runMigrationsForConnection($this->primaryConnectionName);
        $this->runMigrationsForConnection($this->failoverConnectionName);

        Cache::store(Config::get('dynamic_db_failover.cache.store'))->flush();
        DB::purge($this->primaryConnectionName);
        DB::purge($this->failoverConnectionName);
        DB::purge($this->blockingConnectionName);
    }

    protected function tearDown(): void
    {
        Config::set('database.connections.' . $this->primaryConnectionName, $this->originalPrimaryDbConfig);
        Config::set('database.connections.' . $this->failoverConnectionName, $this->originalFailoverDbConfig);
        DB::purge($this->primaryConnectionName);
        DB::purge($this->failoverConnectionName);
        DB::purge($this->blockingConnectionName); // Purge blocking just in case

        parent::tearDown();
    }

    protected function runMigrationsForConnection(string $connectionName): void
    {
        $migrationsPath = realpath(base_path('database/migrations'));
        if (!$migrationsPath || !is_dir($migrationsPath)) {
            Artisan::call('vendor:publish', ['--provider' => 'Illuminate\Foundation\Providers\FoundationServiceProvider', '--tag' => 'migrations']);
            $migrationsPath = realpath(base_path('database/migrations'));
        }

        if ($migrationsPath && is_dir($migrationsPath)) {
            Artisan::call('migrate:fresh', [
                '--database' => $connectionName,
                '--path' => $migrationsPath,
                '--realpath' => true,
                '--quiet' => true,
            ]);
        } else {
            echo "Warning: Migrations path not found or not a directory ('{$migrationsPath}') for connection {$connectionName}. Skipping migrations.\n";
        }
    }

    protected function simulateConnectionDown(string $connectionName): void
    {
        $configKey = 'database.connections.' . $connectionName;
        $currentConfig = Config::get($configKey);
        $faultyConfig = array_merge($currentConfig, ['port' => 9999]); // Use a non-listening port
        Config::set($configKey, $faultyConfig);
        DB::purge($connectionName);
    }

    protected function simulateConnectionUp(string $connectionName): void
    {
        $configKey = 'database.connections.' . $connectionName;
        if ($connectionName === $this->primaryConnectionName) {
            Config::set($configKey, $this->originalPrimaryDbConfig);
        } elseif ($connectionName === $this->failoverConnectionName) {
            Config::set($configKey, $this->originalFailoverDbConfig);
        }
        DB::purge($connectionName);
    }

    #[Test]
    public function it_runs_full_failover_cycle_and_recovery(): void
    {
        /** @var ConnectionStateManager $stateManager */
        $stateManager = $this->app->make(ConnectionStateManager::class);
        /** @var DatabaseFailoverManager $failoverManager */
        $failoverManager = $this->app->make(DatabaseFailoverManager::class);

        // Helper to trigger health check and then determine connection
        $checkAndUpdate = function (string $connectionToManuallyCheck = null) use ($stateManager, $failoverManager) {
            if ($connectionToManuallyCheck) {
                $stateManager->updateConnectionStatus($connectionToManuallyCheck);
            } else {
                $stateManager->updateConnectionStatus($this->primaryConnectionName);
                $stateManager->updateConnectionStatus($this->failoverConnectionName);
            }
            $failoverManager->determineAndSetConnection();
        };

        // 0. Clear initial statuses from boot & ensure cache is clean
        // The service provider boot() already ran determineAndSetConnection.
        // We reset the state here to have a clean slate for step 1.
        Cache::store(Config::get('dynamic_db_failover.cache.store'))->flush();
        $stateManager->setConnectionStatus($this->primaryConnectionName, ConnectionStatus::UNKNOWN, 0);
        $stateManager->setConnectionStatus($this->failoverConnectionName, ConnectionStatus::UNKNOWN, 0);


        // 1. Initial state: Primary UP.
        Log::info("TEST STEP 1: Initial state, primary should be healthy.");
        $checkAndUpdate(); // Checks both, primary should become HEALTHY.
        $this->assertEquals($this->primaryConnectionName, DB::getDefaultConnection(), "Step 1 Failed: Default connection should be primary.");
        DB::purge($this->primaryConnectionName);
        DB::connection($this->primaryConnectionName)->select('SELECT 1');
        // Event::assertDispatched(DatabaseConnectionSwitchedEvent::class); // REMOVED - Event not guaranteed here if already on primary

        // 2. Primary goes DOWN, Failover should become active.
        Log::info("TEST STEP 2: Primary goes down, should switch to failover.");
        $this->simulateConnectionDown($this->primaryConnectionName);
        $checkAndUpdate($this->primaryConnectionName); // Check primary, it will fail (threshold 1)
        $this->assertEquals($this->failoverConnectionName, DB::getDefaultConnection(), "Step 2 Failed: Default connection should be failover.");
        DB::purge($this->failoverConnectionName);
        DB::connection($this->failoverConnectionName)->select('SELECT 1');
        Event::assertDispatched(DatabaseConnectionSwitchedEvent::class);

        // 3. Failover also goes DOWN, Blocking connection should become active.
        Log::info("TEST STEP 3: Failover also goes down, should switch to blocking.");
        $this->simulateConnectionDown($this->failoverConnectionName);
        $checkAndUpdate($this->failoverConnectionName); // Check failover, it will fail
        $this->assertEquals($this->blockingConnectionName, DB::getDefaultConnection(), "Step 3 Failed: Default connection should be blocking.");
        Event::assertDispatched(LimitedFunctionalityModeActivatedEvent::class);

        try {
            DB::connection($this->blockingConnectionName)->select('SELECT 1');
            $this->fail("Step 3 Failed: AllDatabaseConnectionsUnavailableException was not thrown for blocking connection.");
        } catch (AllDatabaseConnectionsUnavailableException $e) {
            // Expected exception
        }

        // 4. Primary comes back UP, should switch back to Primary.
        Log::info("TEST STEP 4: Primary comes back online, should switch back to primary.");
        $this->simulateConnectionUp($this->primaryConnectionName);
        $checkAndUpdate($this->primaryConnectionName);
        $this->assertEquals($this->primaryConnectionName, DB::getDefaultConnection(), "Step 4 Failed: Default connection should be restored to primary.");
        DB::purge($this->primaryConnectionName);
        DB::connection($this->primaryConnectionName)->select('SELECT 1');
        Event::assertDispatched(PrimaryConnectionRestoredEvent::class);
        Event::assertDispatched(DatabaseConnectionSwitchedEvent::class);
    }
}
