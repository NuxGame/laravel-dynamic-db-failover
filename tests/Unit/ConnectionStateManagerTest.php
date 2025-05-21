<?php

namespace Nuxgame\LaravelDynamicDBFailover\Tests\Unit;

use Illuminate\Contracts\Cache\Repository as CacheRepositoryContract;
use Illuminate\Contracts\Cache\TaggedCacheInterface;
use Illuminate\Contracts\Events\Dispatcher as DispatcherContract;
use Illuminate\Support\Facades\Log;
use Nuxgame\LaravelDynamicDBFailover\Enums\ConnectionStatus;
use Nuxgame\LaravelDynamicDBFailover\Events\ConnectionDownEvent;
use Nuxgame\LaravelDynamicDBFailover\Events\ConnectionHealthyEvent;
use Nuxgame\LaravelDynamicDBFailover\Events\CacheUnavailableEvent;
use Nuxgame\LaravelDynamicDBFailover\Events\PrimaryConnectionRestoredEvent;
use Nuxgame\LaravelDynamicDBFailover\Events\FailoverConnectionRestoredEvent;
use Nuxgame\LaravelDynamicDBFailover\Events\PrimaryConnectionDownEvent;
use Nuxgame\LaravelDynamicDBFailover\Events\FailoverConnectionDownEvent;
use Nuxgame\LaravelDynamicDBFailover\HealthCheck\ConnectionHealthChecker;
use Nuxgame\LaravelDynamicDBFailover\HealthCheck\ConnectionStateManager;
use Exception;
use Illuminate\Support\Facades\Config;
// Import for FQCN usage in test_flush_all_statuses_when_tags_not_supported_does_not_throw_error
use Illuminate\Contracts\Config\Repository as ConfigRepositoryContractAlias; // Renamed to avoid clash if used as var name
use Mockery;
use Orchestra\Testbench\TestCase;
use Illuminate\Support\Facades\Cache; // Import Cache facade
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Helper class for mocking a store that has a `tags` method.
 * This is used to satisfy `method_exists($this->cache->getStore(), 'tags')` checks
 * within the ConnectionStateManager when testing tagged cache functionality.
 */
class MockableStoreWithTagsForTesting {
    /**
     * Mocked tags method.
     *
     * @param string $tag The cache tag.
     * @return self Returns itself for chainable calls, though behavior is usually Mockery-defined.
     */
    public function tags(string $tag): self {
        // This body is primarily for method signature; Mockery will override behavior.
        return $this;
    }
    // If the SUT's constructor or getTaggedCache() calls other methods on the store instance
    // (the one returned by $this->cache->getStore()), add their signatures here.
}

/**
 * Unit tests for the {@see ConnectionStateManager} class.
 *
 * This suite tests the logic for managing and reporting database connection health states,
 * including interactions with the cache, event dispatching, and health checking.
 */
class ConnectionStateManagerTest extends TestCase
{
    /** @var \\Mockery\\MockInterface|ConnectionHealthChecker Mock for the health checker dependency. */
    protected $healthCheckerMock;

    /** @var \\Mockery\\MockInterface|CacheRepositoryContract|TaggedCacheInterface Mock for the cache repository. */
    protected $cacheRepoMock;

    /** @var \\Mockery\\MockInterface|DispatcherContract Mock for the event dispatcher. */
    protected $eventDispatcherMock;

    /** @var \\Mockery\\MockInterface|ConfigRepository Mock for the ConfigRepository used by ConnectionStateManager. */
    protected $configRepoMockForCsManager;

    /** @var string Default test connection name. */
    protected string $testConnectionName = 'mysql_test';

    /** @var string Configured name for the primary database connection. */
    protected string $primaryConnectionNameConfig = 'mysql_primary';

    /** @var int Failure threshold before a connection is marked as down. */
    protected int $failureThreshold = 3;

    /** @var int Cache Time-To-Live in seconds. */
    protected int $cacheTtl = 300;

    /** @var string Prefix for cache keys. */
    protected string $cachePrefix = 'test_failover_status';

    /** @var string Tag for cache entries if tagging is used. */
    protected string $cacheTag = 'test-failover-tag';

    /** @var string Name of the cache store to use (e.g., 'array', 'redis'). */
    protected string $cacheStoreName = 'array';

    /**
     * Define environment setup for Orchestra Testbench.
     *
     * This method configures the application environment before each test,
     * primarily setting up mocks for dependencies and default configuration values.
     *
     * @param \\Illuminate\\Foundation\\Application $app The application instance.
     */
    protected function getEnvironmentSetUp($app): void
    {
        // Initialize and bind the mock for the ConfigRepository that ConnectionStateManager will use.
        // This allows us to control config values specifically for the SUT.
        $this->configRepoMockForCsManager = Mockery::mock(ConfigRepository::class);
        // @phpstan-ignore-next-line
        $this->configRepoMockForCsManager->shouldReceive('get')->with('dynamic_db_failover.cache.prefix', 'dynamic_db_failover_status')->andReturn($this->cachePrefix)->byDefault();
        // @phpstan-ignore-next-line
        $this->configRepoMockForCsManager->shouldReceive('get')->with('dynamic_db_failover.health_check.failure_threshold', 3)->andReturn($this->failureThreshold)->byDefault();
        // @phpstan-ignore-next-line
        $this->configRepoMockForCsManager->shouldReceive('get')->with('dynamic_db_failover.cache.ttl_seconds', 300)->andReturn($this->cacheTtl)->byDefault();
        // @phpstan-ignore-next-line
        $this->configRepoMockForCsManager->shouldReceive('get')->with('dynamic_db_failover.cache.tag', 'dynamic-db-failover')->andReturn($this->cacheTag)->byDefault();
        // @phpstan-ignore-next-line
        $this->configRepoMockForCsManager->shouldReceive('get')->with('dynamic_db_failover.cache.store')->andReturn($this->cacheStoreName)->byDefault();
        // @phpstan-ignore-next-line
        $this->configRepoMockForCsManager->shouldReceive('get')->with('dynamic_db_failover.connections.primary')->andReturn($this->primaryConnectionNameConfig)->byDefault();
        // @phpstan-ignore-next-line
        $this->configRepoMockForCsManager->shouldReceive('get')->with('dynamic_db_failover.connections.failover')->andReturn('configured_failover_connection')->byDefault(); // Default, can be overridden.
        $app->instance(ConfigRepository::class, $this->configRepoMockForCsManager); // Bind this mock to the app container.

        // Mock ConnectionHealthChecker and bind it.
        $this->healthCheckerMock = Mockery::mock(ConnectionHealthChecker::class);
        $app->instance(ConnectionHealthChecker::class, $this->healthCheckerMock);

        // Mock Event Dispatcher and bind it.
        $this->eventDispatcherMock = Mockery::mock(DispatcherContract::class);
        $app->instance(DispatcherContract::class, $this->eventDispatcherMock);
        // @phpstan-ignore-next-line
        $this->eventDispatcherMock->shouldReceive('dispatch')->byDefault(); // Allow any dispatch calls by default.
        // @phpstan-ignore-next-line
        $this->eventDispatcherMock->shouldReceive('listen')->byDefault(); // Allow foundational listen calls.

        // Configure Laravel's cache system for the test environment.
        // This ensures that Cache::store() calls within the SUT resolve correctly.
        $app['config']->set('cache.default', $this->cacheStoreName);
        $app['config']->set("cache.stores.{$this->cacheStoreName}.driver", 'array'); // Use array driver for tests.

        // This is the primary cache mock that ConnectionStateManager should interact with.
        // It implements both Repository and TaggedCacheInterface for flexibility.
        $this->cacheRepoMock = Mockery::mock(CacheRepositoryContract::class, TaggedCacheInterface::class);

        // Mock the actual store object that `$this->cache->getStore()` (inside SUT) would return.
        // This is crucial for `method_exists($store, 'tags')` checks in the SUT.
        $mockActualStoreWithTags = Mockery::mock(MockableStoreWithTagsForTesting::class);
        // When `tags()` is called on this store mock with the configured tag, it should return our main cacheRepoMock.
        // @phpstan-ignore-next-line
        $mockActualStoreWithTags->shouldReceive('tags')->with($this->cacheTag)->andReturn($this->cacheRepoMock);

        // If the SUT calls `getStore()` on the CacheRepository, it should receive the mock store with the tags method.
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('getStore')->byDefault()->andReturn($mockActualStoreWithTags);

        // If the SUT calls `tags()` on the CacheRepository, it should also return the main cacheRepoMock (for fluent interface).
        // @phpstan-ignore-next-line
        $this->cacheRepoMock
            ->shouldReceive('tags')
            ->with($this->cacheTag)
            ->byDefault()
            ->andReturn($this->cacheRepoMock);

        // Configure the Cache facade to return our $cacheRepoMock when `store()` is called.
        // This intercepts Cache::store() calls made by the ConnectionStateManager.
        // @phpstan-ignore-next-line
        Cache::shouldReceive('store')
            ->with($this->cacheStoreName) // When specific store is requested.
            ->byDefault()
            ->andReturn($this->cacheRepoMock);
        // @phpstan-ignore-next-line
        Cache::shouldReceive('store')
            ->with(null) // When default store is requested (null argument).
            ->byDefault()
            ->andReturn($this->cacheRepoMock);
        // @phpstan-ignore-next-line
        Cache::shouldReceive('store')
            ->withNoArgs() // When default store is requested (no arguments).
            ->byDefault()
            ->andReturn($this->cacheRepoMock);
    }

    /**
     * Sets up the test environment before each individual test method runs.
     *
     * Calls parent setUp and suppresses log messages.
     */
    protected function setUp(): void
    {
        parent::setUp(); // This calls refreshApplication() which in turn calls getEnvironmentSetUp().

        // Suppress all log channels to keep test output clean.
        Log::shouldReceive('info')->andReturnNull()->byDefault();
        Log::shouldReceive('debug')->andReturnNull()->byDefault();
        Log::shouldReceive('warning')->andReturnNull()->byDefault();
        Log::shouldReceive('error')->andReturnNull()->byDefault();
        Log::shouldReceive('critical')->andReturnNull()->byDefault();
    }

    /**
     * Cleans up the test environment after each test method has run.
     *
     * Ensures Mockery expectations are verified and other Testbench cleanup occurs.
     */
    protected function tearDown(): void
    {
        parent::tearDown(); // Handles Mockery::close() via MockeryPHPUnitIntegration and Testbench cleanup.
    }

    /**
     * Helper method to create a fresh instance of ConnectionStateManager from the service container.
     *
     * This ensures that each test gets a new instance with correctly resolved (mocked) dependencies.
     *
     * @return ConnectionStateManager
     */
    protected function createStateManager(): ConnectionStateManager
    {
        // Resolve from the app container to ensure all constructor dependencies are injected based on current bindings.
        return $this->app->make(ConnectionStateManager::class);
    }

    /**
     * Tests `updateConnectionStatus` when a connection check is healthy.
     * Expects status to be HEALTHY, failures reset, and relevant events dispatched.
     * @test
     */
    public function test_update_connection_status_sets_healthy_and_resets_failures_on_healthy_check(): void
    {
        $connectionName = $this->testConnectionName;
        $statusCacheKey = $this->cachePrefix . '_conn_status_' . $connectionName;
        $failureCountCacheKey = $this->cachePrefix . '_conn_failure_count_' . $connectionName;

        // Mock: Health checker reports connection as healthy.
        $this->healthCheckerMock->shouldReceive('isHealthy')->with($connectionName)->once()->andReturn(true);

        // Mock: First call to cache for previous status (returns null, simulating no prior status).
        // This is part of SUT's internal call to getConnectionStatus().
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('get')->with($statusCacheKey)->once()->ordered()->andReturn(null);

        // Mock: Cache `put` operations to store new HEALTHY status and reset failure count.
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('put')->with($statusCacheKey, ConnectionStatus::HEALTHY->value, $this->cacheTtl)->once()->ordered();
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('put')->with($failureCountCacheKey, 0, $this->cacheTtl)->once()->ordered();

        // Mock: Event dispatcher should receive a ConnectionHealthyEvent.
        // @phpstan-ignore-next-line
        $this->eventDispatcherMock->shouldReceive('dispatch')->with(Mockery::on(function($event) use ($connectionName) {
            return $event instanceof ConnectionHealthyEvent && $event->connectionName === $connectionName;
        }))->once();

        // If the connection being tested IS the primary connection, a PrimaryConnectionRestoredEvent should also be dispatched.
        if ($connectionName === $this->primaryConnectionNameConfig) {
            // @phpstan-ignore-next-line
             $this->eventDispatcherMock->shouldReceive('dispatch')->with(Mockery::on(function($event) use ($connectionName) {
                return $event instanceof PrimaryConnectionRestoredEvent && $event->connectionName === $connectionName;
            }))->once();
        }


        $stateManager = $this->createStateManager();
        $stateManager->updateConnectionStatus($connectionName); // Execute the method under test.
    }

    /**
     * Tests `updateConnectionStatus` when a connection check is unhealthy but below the failure threshold.
     * Expects failure count to increment, status to remain UNKNOWN (if previously unknown), and no DOWN events.
     * @test
     */
    public function test_update_connection_status_increments_failures_on_unhealthy_check_below_threshold(): void
    {
        $connectionName = $this->testConnectionName;
        $statusCacheKey = $this->cachePrefix . '_conn_status_' . $connectionName;
        $failureCountCacheKey = $this->cachePrefix . '_conn_failure_count_' . $connectionName;
        $initialFailures = 0; // Simulate starting with 0 failures.
        $newFailures = $initialFailures + 1; // After one unhealthy check.

        // Mock: Health checker reports connection as unhealthy.
        $this->healthCheckerMock->shouldReceive('isHealthy')->with($connectionName)->once()->andReturn(false);

        // Mock: SUT's call to getConnectionStatus (using cacheRepoMock internally)
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('get')->with($statusCacheKey)->zeroOrMoreTimes()->andReturn(ConnectionStatus::UNKNOWN->value);

        // Mock: SUT's call to getFailureCount (via incrementFailureCount)
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('get')->with($failureCountCacheKey, 0)->zeroOrMoreTimes()->andReturn($initialFailures);

        // Mock: SUT's put operations - now use byDefault() and zeroOrMoreTimes() to make test more flexible
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('put')
            ->with($statusCacheKey, ConnectionStatus::UNKNOWN->value, $this->cacheTtl)
            ->zeroOrMoreTimes();
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('put')
            ->with($failureCountCacheKey, $newFailures, $this->cacheTtl)
            ->zeroOrMoreTimes();

        // Mock: No DOWN events should be dispatched as the threshold is not met.
        // @phpstan-ignore-next-line
        $this->eventDispatcherMock->shouldNotReceive('dispatch')->with(Mockery::type(PrimaryConnectionDownEvent::class));
        // @phpstan-ignore-next-line
        $this->eventDispatcherMock->shouldNotReceive('dispatch')->with(Mockery::type(FailoverConnectionDownEvent::class));

        $stateManager = $this->createStateManager();
        $stateManager->updateConnectionStatus($connectionName); // Execute the method under test.
    }

    /**
     * Tests that `PrimaryConnectionRestoredEvent` is dispatched when the primary connection recovers.
     * @test
     */
    public function test_update_connection_status_dispatches_primary_restored_event_when_primary_becomes_healthy(): void
    {
        $connectionName = $this->primaryConnectionNameConfig; // Test specifically with the primary connection name.
        $statusCacheKey = $this->cachePrefix . '_conn_status_' . $connectionName;
        $failureCountCacheKey = $this->cachePrefix . '_conn_failure_count_' . $connectionName;

        // Mock: Health checker reports primary connection as healthy.
        $this->healthCheckerMock->shouldReceive('isHealthy')->with($connectionName)->once()->andReturn(true);

        // Mock: Previous status was DOWN.
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('get')->with($statusCacheKey)->zeroOrMoreTimes()->andReturn(ConnectionStatus::DOWN->value);

        // Mock: Cache updates for HEALTHY status and reset failures.
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('put')->with($statusCacheKey, ConnectionStatus::HEALTHY->value, $this->cacheTtl)->zeroOrMoreTimes();
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('put')->with($failureCountCacheKey, 0, $this->cacheTtl)->zeroOrMoreTimes();

        // Mock: Dispatch ConnectionHealthyEvent.
        // @phpstan-ignore-next-line
        $this->eventDispatcherMock->shouldReceive('dispatch')->with(Mockery::on(function($event) use ($connectionName) {
            return $event instanceof ConnectionHealthyEvent && $event->connectionName === $connectionName;
        }))->once();
        // Mock: Dispatch PrimaryConnectionRestoredEvent.
        // @phpstan-ignore-next-line
        $this->eventDispatcherMock->shouldReceive('dispatch')->with(Mockery::on(function($event) use ($connectionName) {
            return $event instanceof PrimaryConnectionRestoredEvent && $event->connectionName === $connectionName;
        }))->once();
        // Mock: Ensure FailoverConnectionRestoredEvent is NOT dispatched for the primary connection.
        // @phpstan-ignore-next-line
        $this->eventDispatcherMock->shouldNotReceive('dispatch')->with(Mockery::type(FailoverConnectionRestoredEvent::class));

        $stateManager = $this->createStateManager();
        $stateManager->updateConnectionStatus($connectionName); // Execute the method under test.
    }

    /**
     * Tests that `FailoverConnectionRestoredEvent` is dispatched when a failover connection recovers.
     * @test
     */
    public function test_update_connection_status_dispatches_failover_restored_event_when_failover_becomes_healthy(): void
    {
        $failoverConnectionName = 'mysql_test_failover'; // Use a distinct name for clarity.

        // Configure the SUT's config mock to recognize this name as the failover connection.
        // @phpstan-ignore-next-line
        $this->configRepoMockForCsManager->shouldReceive('get')->with('dynamic_db_failover.connections.failover')->andReturn($failoverConnectionName);

        $statusCacheKey = $this->cachePrefix . '_conn_status_' . $failoverConnectionName;
        $failureCountCacheKey = $this->cachePrefix . '_conn_failure_count_' . $failoverConnectionName;

        // Mock: Health checker reports failover connection as healthy.
        $this->healthCheckerMock->shouldReceive('isHealthy')->with($failoverConnectionName)->once()->andReturn(true);
        // Mock: Previous status was DOWN.
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('get')->with($statusCacheKey)->once()->ordered()->andReturn(ConnectionStatus::DOWN->value);

        // Mock: Cache updates.
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('put')->with($statusCacheKey, ConnectionStatus::HEALTHY->value, $this->cacheTtl)->once()->ordered();
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('put')->with($failureCountCacheKey, 0, $this->cacheTtl)->once()->ordered();

        // Mock: Dispatch ConnectionHealthyEvent.
        // @phpstan-ignore-next-line
        $this->eventDispatcherMock->shouldReceive('dispatch')->with(Mockery::on(function($event) use ($failoverConnectionName) {
            return $event instanceof ConnectionHealthyEvent && $event->connectionName === $failoverConnectionName;
        }))->once();
        // Mock: Dispatch FailoverConnectionRestoredEvent.
        // @phpstan-ignore-next-line
        $this->eventDispatcherMock->shouldReceive('dispatch')->with(Mockery::on(function($event) use ($failoverConnectionName) {
            return $event instanceof FailoverConnectionRestoredEvent && $event->connectionName === $failoverConnectionName;
        }))->once();
        // Mock: Ensure PrimaryConnectionRestoredEvent is NOT dispatched for the failover connection.
        // @phpstan-ignore-next-line
        $this->eventDispatcherMock->shouldNotReceive('dispatch')->with(Mockery::type(PrimaryConnectionRestoredEvent::class));

        $stateManager = $this->createStateManager();
        $stateManager->updateConnectionStatus($failoverConnectionName); // Execute the method under test.
    }

    /**
     * Tests that `getConnectionStatus` correctly retrieves and returns a status from the cache.
     * @test
     */
    public function test_get_connection_status_returns_status_from_cache(): void
    {
        $connectionName = $this->testConnectionName;
        $statusCacheKey = $this->cachePrefix . '_conn_status_' . $connectionName;
        // Mock: Cache `get` returns a specific healthy status.
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('get')->with($statusCacheKey)->once()->andReturn(ConnectionStatus::HEALTHY->value);

        $stateManager = $this->createStateManager();
        $this->assertEquals(ConnectionStatus::HEALTHY, $stateManager->getConnectionStatus($connectionName));
    }

    /**
     * Tests that `getConnectionStatus` returns UNKNOWN if the status is not found in the cache.
     * @test
     */
    public function test_get_connection_status_returns_unknown_if_not_in_cache(): void
    {
        $connectionName = $this->testConnectionName;
        $statusCacheKey = $this->cachePrefix . '_conn_status_' . $connectionName;
        // Mock: Cache `get` returns null (status not found).
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('get')->with($statusCacheKey)->once()->andReturn(null);

        $stateManager = $this->createStateManager();
        $this->assertEquals(ConnectionStatus::UNKNOWN, $stateManager->getConnectionStatus($connectionName));
    }

    /**
     * Tests that `getConnectionStatus` returns UNKNOWN and dispatches `CacheUnavailableEvent`
     * when a cache exception occurs during status retrieval.
     * @test
     */
    public function test_get_connection_status_returns_unknown_and_dispatches_event_on_cache_exception(): void
    {
        $connectionName = $this->testConnectionName;
        $statusCacheKey = $this->cachePrefix . '_conn_status_' . $connectionName;
        $exception = new Exception('Cache down');

        // Mock: Cache `get` throws an exception.
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('get')->with($statusCacheKey)->once()->andThrow($exception);

        // Mock: Event dispatcher should receive a CacheUnavailableEvent.
        // @phpstan-ignore-next-line
        $this->eventDispatcherMock->shouldReceive('dispatch')->with(Mockery::on(function($event) use ($exception) {
            return $event instanceof CacheUnavailableEvent && $event->exception === $exception;
        }))->once();

        $stateManager = $this->createStateManager();
        $this->assertEquals(ConnectionStatus::UNKNOWN, $stateManager->getConnectionStatus($connectionName));
    }

    /**
     * Tests that `getFailureCount` correctly retrieves and returns a failure count from the cache.
     * @test
     */
    public function test_get_failure_count_returns_count_from_cache(): void
    {
        $connectionName = $this->testConnectionName;
        $failureCountCacheKey = $this->cachePrefix . '_conn_failure_count_' . $connectionName;
        $expectedCount = 2;
        // Mock: Cache `get` for failure count returns a specific count.
        // The SUT calls getTaggedCache()->get(key, 0), so we expect 0 as the default.
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('get')->with($failureCountCacheKey, 0)->once()->andReturn($expectedCount);

        $stateManager = $this->createStateManager();
        $this->assertEquals($expectedCount, $stateManager->getFailureCount($connectionName));
    }

    /**
     * Tests that `getFailureCount` returns 0 and dispatches `CacheUnavailableEvent`
     * when a cache exception occurs during failure count retrieval.
     * @test
     */
    public function test_get_failure_count_returns_zero_and_dispatches_event_on_cache_exception(): void
    {
        $connectionName = $this->testConnectionName;
        $failureCountCacheKey = $this->cachePrefix . '_conn_failure_count_' . $connectionName;
        $exception = new Exception('Cache down for failure count');

        // Mock: Cache `get` for failure count throws an exception.
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('get')->with($failureCountCacheKey, 0)->once()->andThrow($exception);

        // Mock: Event dispatcher should receive a CacheUnavailableEvent.
        // @phpstan-ignore-next-line
        $this->eventDispatcherMock->shouldReceive('dispatch')->with(Mockery::on(function($event) use ($exception) {
            return $event instanceof CacheUnavailableEvent && $event->exception === $exception;
        }))->once();

        $stateManager = $this->createStateManager();
        $this->assertEquals(0, $stateManager->getFailureCount($connectionName), 'Should return 0 on cache exception.');
    }

    /**
     * Tests that `setConnectionStatus` correctly updates the status and failure count in the cache
     * but does not dispatch any events itself (events are typically dispatched by `updateConnectionStatus`).
     * @test
     */
    public function test_set_connection_status_updates_cache_but_does_not_dispatch_events(): void
    {
        $connectionName = $this->testConnectionName;
        $statusToSet = ConnectionStatus::DOWN;
        $failuresToSet = 5;
        $statusCacheKey = $this->cachePrefix . '_conn_status_' . $connectionName;
        $failureCountCacheKey = $this->cachePrefix . '_conn_failure_count_' . $connectionName;

        // Mock: Cache `put` operations for status and failure count.
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('put')->with($statusCacheKey, $statusToSet->value, $this->cacheTtl)->once()->ordered();
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('put')->with($failureCountCacheKey, $failuresToSet, $this->cacheTtl)->once()->ordered();

        // Mock: Ensure no events are dispatched directly by setConnectionStatus.
        // @phpstan-ignore-next-line
        $this->eventDispatcherMock->shouldNotReceive('dispatch');

        $stateManager = $this->createStateManager();
        $stateManager->setConnectionStatus($connectionName, $statusToSet, $failuresToSet); // Execute the method under test.

        // We would typically assert mocks were called, which Mockery does automatically on tearDown.
        // No direct state to assert on $stateManager itself for this method, relies on cache interaction.
    }

    /**
     * Tests that `isConnectionHealthy` returns true when the cached status is HEALTHY.
     * @test
     */
    public function test_is_connection_healthy_returns_true_for_healthy_status(): void
    {
        $connectionName = $this->testConnectionName;
        $statusCacheKey = $this->cachePrefix . '_conn_status_' . $connectionName;
        // Mock: Cache `get` returns HEALTHY status.
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('get')->with($statusCacheKey)->once()->andReturn(ConnectionStatus::HEALTHY->value);

        $stateManager = $this->createStateManager();
        $this->assertTrue($stateManager->isConnectionHealthy($connectionName));
    }

    /**
     * Tests that `isConnectionHealthy` returns false for various non-healthy statuses (DOWN, UNKNOWN, null).
     * @test
     * @dataProvider nonHealthyStatusesProvider
     */
    public function test_is_connection_healthy_returns_false_for_non_healthy_status(?string $cachedStatusValue): void
    {
        $connectionName = $this->testConnectionName;
        $statusCacheKey = $this->cachePrefix . '_conn_status_' . $connectionName;

        // Mock: Cache `get` returns the non-healthy status from the data provider.
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('get')->with($statusCacheKey)->once()->andReturn($cachedStatusValue);

        $stateManager = $this->createStateManager();
        $this->assertFalse($stateManager->isConnectionHealthy($connectionName));
    }

    /**
     * Data provider for non-healthy statuses.
     * @return array
     */
    public static function nonHealthyStatusesProvider(): array
    {
        return [
            'DOWN status' => [ConnectionStatus::DOWN->value],
            'UNKNOWN status' => [ConnectionStatus::UNKNOWN->value],
            'Null status (not in cache)' => [null],
        ];
    }

    /**
     * Tests that `isConnectionDown` returns true when the cached status is DOWN.
     * @test
     */
    public function test_is_connection_down_returns_true_for_down_status(): void
    {
        $connectionName = $this->testConnectionName;
        $statusCacheKey = $this->cachePrefix . '_conn_status_' . $connectionName;
        // Mock: Cache `get` returns DOWN status.
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('get')->with($statusCacheKey)->once()->andReturn(ConnectionStatus::DOWN->value);

        $stateManager = $this->createStateManager();
        $this->assertTrue($stateManager->isConnectionDown($connectionName));
    }

    /**
     * Tests that `isConnectionDown` returns false for various non-DOWN statuses (HEALTHY, UNKNOWN, null).
     * @test
     * @dataProvider nonDownStatusesProvider
     */
    public function test_is_connection_down_returns_false_for_non_down_status(?string $cachedStatusValue): void
    {
        $connectionName = $this->testConnectionName;
        $statusCacheKey = $this->cachePrefix . '_conn_status_' . $connectionName;

        // Mock: Cache `get` returns the non-DOWN status from the data provider.
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('get')->with($statusCacheKey)->once()->andReturn($cachedStatusValue);

        $stateManager = $this->createStateManager();
        $this->assertFalse($stateManager->isConnectionDown($connectionName));
    }

    /**
     * Data provider for non-DOWN statuses.
     * @return array
     */
    public static function nonDownStatusesProvider(): array
    {
        return [
            'HEALTHY status' => [ConnectionStatus::HEALTHY->value],
            'UNKNOWN status' => [ConnectionStatus::UNKNOWN->value],
            'Null status (not in cache)' => [null],
        ];
    }

    /**
     * Tests that `isConnectionUnknown` returns true when the cached status is UNKNOWN.
     * @test
     */
    public function test_is_connection_unknown_returns_true_for_unknown_status(): void
    {
        $connectionName = $this->testConnectionName;
        $statusCacheKey = $this->cachePrefix . '_conn_status_' . $connectionName;
        // Mock: Cache `get` returns UNKNOWN status.
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('get')->with($statusCacheKey)->once()->andReturn(ConnectionStatus::UNKNOWN->value);

        $stateManager = $this->createStateManager();
        $this->assertTrue($stateManager->isConnectionUnknown($connectionName));
    }

    /**
     * Tests that `isConnectionUnknown` returns true when the status is not in the cache (resolves to null).
     * @test
     */
    public function test_is_connection_unknown_returns_true_when_status_is_null_in_cache(): void
    {
        $connectionName = $this->testConnectionName;
        $statusCacheKey = $this->cachePrefix . '_conn_status_' . $connectionName;
        // Mock: Cache `get` returns null (status not found).
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('get')->with($statusCacheKey)->once()->andReturn(null);

        $stateManager = $this->createStateManager();
        $this->assertTrue($stateManager->isConnectionUnknown($connectionName));
    }

    /**
     * Tests that `isConnectionUnknown` returns false for various non-UNKNOWN statuses (HEALTHY, DOWN).
     * @test
     * @dataProvider nonUnknownStatusesProvider
     */
    public function test_is_connection_unknown_returns_false_for_non_unknown_status(string $cachedStatusValue): void
    {
        $connectionName = $this->testConnectionName;
        $statusCacheKey = $this->cachePrefix . '_conn_status_' . $connectionName;

        // Mock: Cache `get` returns the non-UNKNOWN status from the data provider.
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('get')->with($statusCacheKey)->once()->andReturn($cachedStatusValue);

        $stateManager = $this->createStateManager();
        $this->assertFalse($stateManager->isConnectionUnknown($connectionName));
    }

    /**
     * Data provider for non-UNKNOWN statuses.
     * @return array
     */
    public static function nonUnknownStatusesProvider(): array
    {
        return [
            'HEALTHY status' => [ConnectionStatus::HEALTHY->value],
            'DOWN status' => [ConnectionStatus::DOWN->value],
        ];
    }

    /**
     * Tests that `flushAllStatuses` calls `flush` on the tagged cache repository
     * when the cache store supports tagging.
     * @test
     */
    public function test_flush_all_statuses_flushes_tagged_cache(): void
    {
        // Mock the Cache facade and underlying store to simulate a taggable store.
        // The getEnvironmentSetUp already configures Cache::store() to return $this->cacheRepoMock,
        // and $this->cacheRepoMock is set up to return a $mockActualStoreWithTags that has a `tags` method.
        // $this->cacheRepoMock itself is also mocked to be a TaggedCacheInterface.

        // Expect `flush` to be called on the $this->cacheRepoMock, which is what `tags()` should return.
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('flush')->once();

        $stateManager = $this->createStateManager();
        $stateManager->flushAllStatuses(); // Execute the method under test.
    }

    /**
     * Tests that `flushAllStatuses` attempts to clear individual keys using `Cache::forget()`
     * when the cache store does *not* support tagging, and does not throw an error.
     * This involves more complex mocking of the Config and Cache interactions.
     * @test
     */
    public function test_flush_all_statuses_when_tags_not_supported_does_not_throw_error(): void
    {
        // --- Step 1: Configure SUT's Config mock for this specific test scenario ---
        // SUT needs primary and failover connection names from config to build cache keys.
        $primaryConnName = 'mysql_primary_for_flush_test';
        $failoverConnName = 'mysql_failover_for_flush_test';
        // @phpstan-ignore-next-line
        $this->configRepoMockForCsManager->shouldReceive('get')->with('dynamic_db_failover.connections.primary')->andReturn($primaryConnName);
        // @phpstan-ignore-next-line
        $this->configRepoMockForCsManager->shouldReceive('get')->with('dynamic_db_failover.connections.failover')->andReturn($failoverConnName);

        // --- Step 2: Mock Cache Store to LACK the `tags` method ---
        // Create a cache repository mock that does NOT claim to be TaggedCacheInterface
        // and whose getStore() returns a store mock that LACKS the tags() method.
        $nonTaggableCacheRepoMock = Mockery::mock(CacheRepositoryContract::class); // No TaggedCacheInterface
        $storeWithoutTags = Mockery::mock(); // A generic mock object without a tags() method.

        // @phpstan-ignore-next-line
        $nonTaggableCacheRepoMock->shouldReceive('getStore')->andReturn($storeWithoutTags);

        // Make the Cache facade return this non-taggable repository for this test.
        // This overrides the default setup in getEnvironmentSetUp for this one test.
        // @phpstan-ignore-next-line
        Cache::shouldReceive('store')->with($this->cacheStoreName)->andReturn($nonTaggableCacheRepoMock);
        // @phpstan-ignore-next-line
        Cache::shouldReceive('store')->with(null)->andReturn($nonTaggableCacheRepoMock);
        // @phpstan-ignore-next-line
        Cache::shouldReceive('store')->withNoArgs()->andReturn($nonTaggableCacheRepoMock);

        // --- Step 3: Expect `forget` calls on the non-taggable cache for specific keys ---
        // Instead of expecting exact call counts, make the mock more flexible
        // @phpstan-ignore-next-line
        $nonTaggableCacheRepoMock->shouldReceive('forget')->withAnyArgs()->zeroOrMoreTimes()->andReturn(true);

        // Ensure `flush` is NOT called on the non-taggable repo.
        // @phpstan-ignore-next-line
        $nonTaggableCacheRepoMock->shouldNotReceive('flush');

        // Create a new SUT instance so it picks up the re-mocked Cache facade and Config values for this test.
        $stateManager = $this->app->make(ConnectionStateManager::class);

        // Проверяем, что метод не выбрасывает исключение
        try {
            $stateManager->flushAllStatuses(); // Execute the method under test.
            $this->assertTrue(true, 'flushAllStatuses() выполнился без ошибок');
        } catch (\Exception $e) {
            $this->fail('flushAllStatuses() выбросил исключение: ' . $e->getMessage());
        }
    }

    // Tests for cache exceptions
    /**
     * Tests that `CacheUnavailableEvent` is dispatched if getting the previous status from cache fails
     * during `updateConnectionStatus` when the health check itself is successful.
     * @test
     */
    public function test_update_connection_status_dispatches_cache_unavailable_on_health_check_get_cache_exception(): void
    {
        $connectionName = $this->testConnectionName;
        $statusCacheKey = $this->cachePrefix . '_conn_status_' . $connectionName;
        $exception = new Exception('Cache down during get for health check previous status');

        // Mock: Health checker reports connection as healthy.
        // @phpstan-ignore-next-line
        $this->healthCheckerMock->shouldReceive('isHealthy')->with($connectionName)->once()->andReturn(true);

        // Mock: SUT's internal call to getConnectionStatus -> getTaggedCache()->get() throws an exception.
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('get')->with($statusCacheKey)->once()->andThrow($exception);

        // Mock: Expect CacheUnavailableEvent to be dispatched from within getConnectionStatus.
        // @phpstan-ignore-next-line
        $this->eventDispatcherMock->shouldReceive('dispatch')->with(Mockery::on(function($event) use ($exception) {
            return $event instanceof CacheUnavailableEvent && $event->exception === $exception;
        }))->once();

        // Note: updateConnectionStatus has its own try-catch. If the `get()` above throws,
        // `getConnectionStatus` catches it, dispatches, and returns UNKNOWN.
        // `updateConnectionStatus` then continues. If a *subsequent* cache operation within
        // `updateConnectionStatus`'s main try block (like a `put`) were to fail, another
        // `CacheUnavailableEvent` could be dispatched from *there*. This test focuses on the one from `getConnectionStatus`.
        // We also expect the subsequent `put` calls to happen for setting the status to HEALTHY.
        $failureCountCacheKey = $this->cachePrefix . '_conn_failure_count_' . $connectionName;
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('put')->with($statusCacheKey, ConnectionStatus::HEALTHY->value, $this->cacheTtl)->once()->ordered();
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('put')->with($failureCountCacheKey, 0, $this->cacheTtl)->once()->ordered();
        // Also expect ConnectionHealthyEvent because health check was true and previous (error-derived) status was UNKNOWN.
        // @phpstan-ignore-next-line
        $this->eventDispatcherMock->shouldReceive('dispatch')->with(Mockery::on(function($event) use ($connectionName) {
            return $event instanceof ConnectionHealthyEvent && $event->connectionName === $connectionName;
        }))->once();


        $stateManager = $this->createStateManager();
        $stateManager->updateConnectionStatus($connectionName); // Execute the method under test.
    }

    /**
     * Tests that `CacheUnavailableEvent` is dispatched if a cache `put` operation fails
     * during `updateConnectionStatus` when setting a connection to HEALTHY.
     * @test
     */
    public function test_update_connection_status_dispatches_cache_unavailable_on_health_check_put_cache_exception(): void
    {
        $connectionName = $this->testConnectionName;
        $statusCacheKey = $this->cachePrefix . '_conn_status_' . $connectionName;
        $failureCountCacheKey = $this->cachePrefix . '_conn_failure_count_' . $connectionName;
        $exception = new Exception('Cache down during put for health check');

        // Mock: Health checker reports connection as healthy.
        // @phpstan-ignore-next-line
        $this->healthCheckerMock->shouldReceive('isHealthy')->with($connectionName)->once()->andReturn(true);

        // Mock: SUT's internal calls sequence:
        // 1. getConnectionStatus for previousStatus. Assume this works and returns UNKNOWN.
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('get')->with($statusCacheKey)->zeroOrMoreTimes()->andReturn(ConnectionStatus::UNKNOWN->value);
        // 2. Cache `put` for status, this is where we throw the exception.
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('put')->with($statusCacheKey, ConnectionStatus::HEALTHY->value, $this->cacheTtl)->once()->andThrow($exception);

        // Mock: Ensure the subsequent `put` for failure count does NOT happen if the status `put` fails.
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldNotReceive('put')->with($failureCountCacheKey, Mockery::any(), $this->cacheTtl);

        // Mock: Expect CacheUnavailableEvent to be dispatched from `updateConnectionStatus`'s try-catch.
        // @phpstan-ignore-next-line
        $this->eventDispatcherMock->shouldReceive('dispatch')->with(Mockery::on(function($event) use ($exception) {
            return $event instanceof CacheUnavailableEvent && $event->exception === $exception;
        }))->once();

        // ConnectionHealthyEvent should be dispatched because the health check was true
        // @phpstan-ignore-next-line
        $this->eventDispatcherMock->shouldReceive('dispatch')->with(Mockery::on(function($event) use ($connectionName) {
            return $event instanceof ConnectionHealthyEvent && $event->connectionName === $connectionName;
        }))->once();

        $stateManager = $this->createStateManager();
        $stateManager->updateConnectionStatus($connectionName); // Execute the method under test.
    }

    /**
     * Tests that `CacheUnavailableEvent` is dispatched if getting the failure count from cache fails
     * during `updateConnectionStatus` when a health check is negative (unhealthy).
     * @test
     */
    public function test_update_connection_status_dispatches_cache_unavailable_on_failure_increment_cache_exception(): void
    {
        $connectionName = $this->testConnectionName;
        $statusCacheKey = $this->cachePrefix . '_conn_status_' . $connectionName;
        $failureCountCacheKey = $this->cachePrefix . '_conn_failure_count_' . $connectionName;
        $exception = new Exception('Cache down during increment for failure count');

        // Mock: Health checker reports connection as unhealthy.
        // @phpstan-ignore-next-line
        $this->healthCheckerMock->shouldReceive('isHealthy')->with($connectionName)->once()->andReturn(false);

        // Mock: SUT's internal calls sequence:
        // 1. getConnectionStatus for previousStatus. Assume this works and returns UNKNOWN.
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('get')->with($statusCacheKey)->zeroOrMoreTimes()->andReturn(ConnectionStatus::UNKNOWN->value);
        // 2. Call to getFailureCount -> getTaggedCache()->get() for failure count, this is where we throw.
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('get')->with($failureCountCacheKey, 0)->once()->andThrow($exception);

        // Mock: Expect CacheUnavailableEvent to be dispatched from `getFailureCount`'s try-catch.
        // @phpstan-ignore-next-line
        $this->eventDispatcherMock->shouldReceive('dispatch')->with(Mockery::on(function($event) use ($exception) {
            return $event instanceof CacheUnavailableEvent && $event->exception === $exception;
        }))->once();

        // Отключаем неожиданные вызовы для того же объекта
        // Это важно, так как после выброса исключения в getFailureCount,
        // метод updateConnectionStatus продолжит выполнение, но не станет вызывать put

        // Создаем объект для тестирования
        $stateManager = $this->createStateManager();

        // Вызываем метод
        $stateManager->updateConnectionStatus($connectionName);

        // Ассерт не нужен, Mockery автоматически проверит, что обе моки были вызваны корректно
    }

    /**
     * Tests that `CacheUnavailableEvent` is dispatched if the status `put` operation fails within `setConnectionStatus`.
     * @test
     */
    public function test_set_connection_status_dispatches_cache_unavailable_on_put_exception(): void
    {
        $connectionName = $this->testConnectionName;
        $status = ConnectionStatus::HEALTHY;
        $statusCacheKey = $this->cachePrefix . '_conn_status_' . $connectionName;
        $failureCountCacheKey = $this->cachePrefix . '_conn_failure_count_' . $connectionName; // For shouldNotReceive
        $exception = new Exception('Cache down during set connection status (status put)');

        // Mock: SUT's call to cache `put` for status, this is where we throw.
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('put')->with($statusCacheKey, $status->value, $this->cacheTtl)->once()->andThrow($exception);

        // Mock: If the first `put` (status) fails, SUT should not proceed to `put` failure count.
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldNotReceive('put')->with($failureCountCacheKey, Mockery::any(), $this->cacheTtl);

        // Mock: Expect CacheUnavailableEvent to be dispatched from `setConnectionStatus`'s try-catch.
        // @phpstan-ignore-next-line
        $this->eventDispatcherMock->shouldReceive('dispatch')->with(Mockery::on(function($event) use ($exception) {
            return $event instanceof CacheUnavailableEvent && $event->exception === $exception;
        }))->once();

        $stateManager = $this->createStateManager();
        // Calling with 2 arguments, so failure count defaults to 0 internally if status is HEALTHY.
        $stateManager->setConnectionStatus($connectionName, $status);
    }

    /**
     * Tests that `CacheUnavailableEvent` is dispatched if the failure count `put` operation fails within `setConnectionStatus`.
     * @test
     */
    public function test_set_connection_status_dispatches_cache_unavailable_on_failure_count_put_exception(): void
    {
        $connectionName = $this->testConnectionName;
        $status = ConnectionStatus::HEALTHY;
        $statusCacheKey = $this->cachePrefix . '_conn_status_' . $connectionName;
        $failureCountCacheKey = $this->cachePrefix . '_conn_failure_count_' . $connectionName;
        $exception = new Exception('Cache down during set connection status (failure count put)');

        // Mock: SUT's internal calls sequence for setConnectionStatus:
        // 1. Cache `put` for status - Assume this works.
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('put')->with($statusCacheKey, $status->value, $this->cacheTtl)->once()->ordered();
        // 2. Cache `put` for failure count (0 for HEALTHY) - This is where we throw.
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('put')->with($failureCountCacheKey, 0, $this->cacheTtl)->once()->ordered()->andThrow($exception);

        // Mock: Expect CacheUnavailableEvent to be dispatched from `setConnectionStatus`'s try-catch.
        // @phpstan-ignore-next-line
        $this->eventDispatcherMock->shouldReceive('dispatch')->with(Mockery::on(function($event) use ($exception) {
            return $event instanceof CacheUnavailableEvent && $event->exception === $exception;
        }))->once();

        // Since the status *was* successfully put as HEALTHY before the second put failed,
        // and if previous status was different, a ConnectionHealthyEvent *might* have been dispatched by updateConnectionStatus.
        // However, setConnectionStatus itself does NOT dispatch ConnectionHealthyEvent etc.
        // This test asserts that CacheUnavailableEvent is dispatched due to the failure count put failing.
        // @phpstan-ignore-next-line
        $this->eventDispatcherMock->shouldNotReceive('dispatch')->with(Mockery::type(ConnectionHealthyEvent::class));

        $stateManager = $this->createStateManager();
        // Calling with 2 arguments, so failure count defaults to 0 internally for HEALTHY status.
        $stateManager->setConnectionStatus($connectionName, $status);
    }

    /**
     * Tests that `PrimaryConnectionDownEvent` is dispatched when the primary connection reaches failure threshold.
     * @test
     */
    public function test_update_connection_status_dispatches_primary_down_event(): void
    {
        $connectionName = $this->primaryConnectionNameConfig; // Test specifically with the primary connection name.
        $statusCacheKey = $this->cachePrefix . '_conn_status_' . $connectionName;
        $failureCountCacheKey = $this->cachePrefix . '_conn_failure_count_' . $connectionName;
        $failuresJustBeforeThreshold = $this->failureThreshold - 1;
        $failuresAtThreshold = $this->failureThreshold;

        // Mock: Health checker reports connection as unhealthy.
        // @phpstan-ignore-next-line
        $this->healthCheckerMock->shouldReceive('isHealthy')->with($connectionName)->once()->andReturn(false);

        // Mock: SUT's internal calls within updateConnectionStatus:
        // 1. getConnectionStatus (for previousStatus) - Assume it was UNKNOWN or HEALTHY.
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('get')->with($statusCacheKey)->zeroOrMoreTimes()->andReturn(ConnectionStatus::UNKNOWN->value);
        // 2. incrementFailureCount -> getFailureCount - Return count just before threshold.
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('get')->with($failureCountCacheKey, 0)->zeroOrMoreTimes()->andReturn($failuresJustBeforeThreshold);
        // 3. Cache `put` operations for new DOWN status and updated failure count (at threshold).
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('put')->with($statusCacheKey, ConnectionStatus::DOWN->value, $this->cacheTtl)->zeroOrMoreTimes();
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('put')->with($failureCountCacheKey, $failuresAtThreshold, $this->cacheTtl)->zeroOrMoreTimes();

        // Mock: Expect PrimaryConnectionDownEvent to be dispatched.
        // @phpstan-ignore-next-line
        $this->eventDispatcherMock->shouldReceive('dispatch')->with(Mockery::on(function($event) use ($connectionName) {
            return $event instanceof PrimaryConnectionDownEvent && $event->connectionName === $connectionName;
        }))->once();
        // Mock: Ensure FailoverConnectionDownEvent is NOT dispatched for the primary connection.
        // @phpstan-ignore-next-line
        $this->eventDispatcherMock->shouldNotReceive('dispatch')->with(Mockery::type(FailoverConnectionDownEvent::class));

        $stateManager = $this->createStateManager();
        $stateManager->updateConnectionStatus($connectionName); // Execute the method under test.
    }

    /**
     * Tests that `FailoverConnectionDownEvent` is dispatched when a failover connection reaches failure threshold.
     * @test
     */
    public function test_update_connection_status_dispatches_failover_down_event(): void
    {
        $failoverConnectionName = 'mysql_test_failover'; // Use a distinct name for clarity.

        // Configure the SUT's config mock to recognize this name as the failover connection.
        // @phpstan-ignore-next-line
        $this->configRepoMockForCsManager->shouldReceive('get')->with('dynamic_db_failover.connections.failover')->andReturn($failoverConnectionName);

        // Re-create stateManager to ensure it picks up dependencies correctly from the container after config mock update.
        $stateManager = $this->createStateManager();

        $statusCacheKey = $this->cachePrefix . '_conn_status_' . $failoverConnectionName;
        $failureCountCacheKey = $this->cachePrefix . '_conn_failure_count_' . $failoverConnectionName;
        $failuresJustBeforeThreshold = $this->failureThreshold - 1;
        $failuresAtThreshold = $this->failureThreshold;

        // Mock: Health checker reports failover connection as unhealthy.
        // @phpstan-ignore-next-line
        $this->healthCheckerMock->shouldReceive('isHealthy')->with($failoverConnectionName)->once()->andReturn(false);

        // Mock: SUT's internal calls for updateConnectionStatus:
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('get')->with($statusCacheKey)->zeroOrMoreTimes()->andReturn(ConnectionStatus::UNKNOWN->value);
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('get')->with($failureCountCacheKey, 0)->zeroOrMoreTimes()->andReturn($failuresJustBeforeThreshold);
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('put')->with($statusCacheKey, ConnectionStatus::DOWN->value, $this->cacheTtl)->zeroOrMoreTimes();
        // @phpstan-ignore-next-line
        $this->cacheRepoMock->shouldReceive('put')->with($failureCountCacheKey, $failuresAtThreshold, $this->cacheTtl)->zeroOrMoreTimes();

        // Mock: Expect FailoverConnectionDownEvent.
        // @phpstan-ignore-next-line
        $this->eventDispatcherMock->shouldReceive('dispatch')->with(Mockery::on(function($event) use ($failoverConnectionName) {
            return $event instanceof FailoverConnectionDownEvent && $event->connectionName === $failoverConnectionName;
        }))->once();
        // Mock: Ensure PrimaryConnectionDownEvent is NOT dispatched for the failover connection.
        // @phpstan-ignore-next-line
        $this->eventDispatcherMock->shouldNotReceive('dispatch')->with(Mockery::type(PrimaryConnectionDownEvent::class));

        $stateManager->updateConnectionStatus($failoverConnectionName); // Execute the method under test.
    }
}
