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
use Nuxgame\LaravelDynamicDBFailover\HealthCheck\ConnectionHealthChecker;
use Nuxgame\LaravelDynamicDBFailover\HealthCheck\ConnectionStateManager;
use Exception;
use Illuminate\Support\Facades\Config;
// Import for FQCN usage in test_flush_all_statuses_when_tags_not_supported_does_not_throw_error
use Illuminate\Contracts\Config\Repository as ConfigRepositoryContractAlias; // Renamed to avoid clash if used as var name
use Mockery;
use Orchestra\Testbench\TestCase;
use Illuminate\Support\Facades\Cache; // Import Cache facade

// Helper class for mocking a store that has a tags method for method_exists checks
class MockableStoreWithTagsForTesting {
    public function tags(string $tag): self {
        // This body is mostly for signature, Mockery will override behavior
        return $this;
    }
    // If the SUT's constructor or getTaggedCache() calls other methods on the store instance
    // (the one returned by $this->cache->getStore()), add their signatures here.
}

class ConnectionStateManagerTest extends TestCase
{
    protected $healthCheckerMock;
    protected $cacheRepoMock; // Unified cache mock
    protected $eventDispatcherMock;

    protected string $testConnectionName = 'mysql_test';
    protected string $primaryConnectionNameConfig = 'mysql_primary';
    protected int $failureThreshold = 3;
    protected int $cacheTtl = 300;
    protected string $cachePrefix = 'test_failover_status';
    protected string $cacheTag = 'test-failover-tag';
    protected string $cacheStoreName = 'array'; // This is the key for config('cache.stores.array')

    protected function getEnvironmentSetUp($app)
    {
        Config::set('dynamic_db_failover.cache.prefix', $this->cachePrefix);
        Config::set('dynamic_db_failover.health_check.failure_threshold', $this->failureThreshold);
        Config::set('dynamic_db_failover.cache.ttl_seconds', $this->cacheTtl);
        Config::set('dynamic_db_failover.cache.tag', $this->cacheTag);
        Config::set('dynamic_db_failover.cache.store', $this->cacheStoreName); // SUT will use this name to get store
        Config::set('dynamic_db_failover.connections.primary', $this->primaryConnectionNameConfig);

        $this->healthCheckerMock = Mockery::mock(ConnectionHealthChecker::class);
        $app->instance(ConnectionHealthChecker::class, $this->healthCheckerMock);

        $this->eventDispatcherMock = Mockery::mock(DispatcherContract::class);
        $app->instance(DispatcherContract::class, $this->eventDispatcherMock);
        $this->eventDispatcherMock->shouldReceive('dispatch')->byDefault();

        // Configure Laravel's cache system for the test (used by real Cache facade if not fully mocked)
        $app['config']->set('cache.default', $this->cacheStoreName);
        $app['config']->set("cache.stores.{$this->cacheStoreName}.driver", 'array');

        // This is the mock that ConnectionStateManager should end up using.
        $this->cacheRepoMock = Mockery::mock(CacheRepositoryContract::class, TaggedCacheInterface::class);

        // Mock for the actual store object that $this->cache->getStore() returns in SUT constructor
        // This object must actually have a tags() method for `method_exists` to work as expected in SUT.
        $mockActualStoreWithTags = Mockery::mock(MockableStoreWithTagsForTesting::class);
        // Tell the mock what to return when its tags() method is called with the specific tag.
        $mockActualStoreWithTags->shouldReceive('tags')->with($this->cacheTag)->andReturn($this->cacheRepoMock);

        $this->cacheRepoMock->shouldReceive('getStore')->byDefault()->andReturn($mockActualStoreWithTags);

        $this->cacheRepoMock
            ->shouldReceive('tags')
            ->with($this->cacheTag)
            ->byDefault()
            ->andReturn($this->cacheRepoMock);

        Cache::shouldReceive('store')
            ->with($this->cacheStoreName)
            ->byDefault()
            ->andReturn($this->cacheRepoMock);

        Cache::shouldReceive('store')
            ->with(null)
            ->byDefault()
            ->andReturn($this->cacheRepoMock);

        Cache::shouldReceive('store')
            ->withNoArgs()
            ->byDefault()
            ->andReturn($this->cacheRepoMock);
    }

    protected function setUp(): void
    {
        parent::setUp(); // This calls refreshApplication() which calls getEnvironmentSetUp()
        Log::shouldReceive('info')->andReturnNull()->byDefault();
        Log::shouldReceive('debug')->andReturnNull()->byDefault();
        Log::shouldReceive('warning')->andReturnNull()->byDefault();
        Log::shouldReceive('error')->andReturnNull()->byDefault();
        Log::shouldReceive('critical')->andReturnNull()->byDefault();
    }

    protected function createStateManager(): ConnectionStateManager
    {
        // Ensure a fresh instance from the app container for each test.
        // Its dependencies (Config, Dispatcher) are resolved by $app.
        // Its Cache dependency is now handled by mocking the Cache facade.
        return $this->app->make(ConnectionStateManager::class);
    }

    public function test_update_connection_status_sets_healthy_and_resets_failures_on_healthy_check(): void
    {
        $connectionName = $this->testConnectionName;
        $statusCacheKey = $this->cachePrefix . '_conn_status_' . $connectionName;
        $failureCountCacheKey = $this->cachePrefix . '_conn_failure_count_' . $connectionName;

        $this->healthCheckerMock->shouldReceive('isHealthy')->with($connectionName)->once()->andReturn(true);

        // SUT's updateConnectionStatus calls getConnectionStatus internally for $previousStatus
        // getConnectionStatus calls $this->getTaggedCache()->get()
        // $this->getTaggedCache() should now be working with $this->cacheRepoMock
        $this->cacheRepoMock->shouldReceive('get')->with($statusCacheKey)->once()->ordered()->andReturn(null); // For previousStatus
        // This expectation is on the $this->cacheRepoMock which should be returned by Cache::store()

        $this->cacheRepoMock->shouldReceive('put')->with($statusCacheKey, ConnectionStatus::HEALTHY->value, $this->cacheTtl)->once()->ordered();
        $this->cacheRepoMock->shouldReceive('put')->with($failureCountCacheKey, 0, $this->cacheTtl)->once()->ordered();

        $this->eventDispatcherMock->shouldReceive('dispatch')->with(Mockery::on(function($event) use ($connectionName) {
            return $event instanceof ConnectionHealthyEvent && $event->connectionName === $connectionName;
        }))->once();

        // This event is dispatched if primary was DOWN and becomes HEALTHY
        // For this generic testConnectionName, it won't be primary unless $testConnectionName === $primaryConnectionNameConfig
        if ($connectionName === $this->primaryConnectionNameConfig) {
             $this->eventDispatcherMock->shouldReceive('dispatch')->with(Mockery::on(function($event) use ($connectionName) {
                return $event instanceof PrimaryConnectionRestoredEvent && $event->connectionName === $connectionName;
            }))->once();
        }


        $stateManager = $this->createStateManager();
        $stateManager->updateConnectionStatus($connectionName);
    }

    public function test_update_connection_status_increments_failures_on_unhealthy_check_below_threshold(): void
    {
        $connectionName = $this->testConnectionName;
        $statusCacheKey = $this->cachePrefix . '_conn_status_' . $connectionName;
        $failureCountCacheKey = $this->cachePrefix . '_conn_failure_count_' . $connectionName;
        $newFailures = 1;

        $this->healthCheckerMock->shouldReceive('isHealthy')->with($connectionName)->once()->andReturn(false);

        // SUT calls getConnectionStatus for $previousStatus -> $this->cacheRepoMock->get()
        $this->cacheRepoMock->shouldReceive('get')->with($statusCacheKey)->once()->ordered()->andReturn(ConnectionStatus::UNKNOWN->value);

        // SUT calls $this->cache->increment()
        // This call in SUT is on $this->cache directly, which is Cache::store(...)
        // So the expectation should be on $this->cacheRepoMock
        $this->cacheRepoMock->shouldReceive('increment')->with($failureCountCacheKey)->once()->ordered()->andReturn($newFailures);
        // SUT then calls $cache->put() for failure count where $cache is getTaggedCache()
        $this->cacheRepoMock->shouldReceive('put')->with($failureCountCacheKey, $newFailures, $this->cacheTtl)->once()->ordered();

        // Status should not be set to DOWN yet
        $this->cacheRepoMock->shouldNotReceive('put')->with($statusCacheKey, ConnectionStatus::DOWN->value, $this->cacheTtl);
        $this->eventDispatcherMock->shouldNotReceive('dispatch')->with(Mockery::type(ConnectionDownEvent::class));

        $stateManager = $this->createStateManager();
        $stateManager->updateConnectionStatus($connectionName);
    }

    public function test_update_connection_status_sets_down_and_dispatches_event_on_unhealthy_check_reaching_threshold(): void
    {
        $connectionName = $this->testConnectionName;
        $statusCacheKey = $this->cachePrefix . '_conn_status_' . $connectionName;
        $failureCountCacheKey = $this->cachePrefix . '_conn_failure_count_' . $connectionName;
        $failuresAtThreshold = $this->failureThreshold;

        $this->healthCheckerMock->shouldReceive('isHealthy')->with($connectionName)->once()->andReturn(false);

        $this->cacheRepoMock->shouldReceive('get')->with($statusCacheKey)->once()->ordered()->andReturn(ConnectionStatus::UNKNOWN->value); // For $previousStatus
        $this->cacheRepoMock->shouldReceive('increment')->with($failureCountCacheKey)->once()->ordered()->andReturn($failuresAtThreshold);
        $this->cacheRepoMock->shouldReceive('put')->with($failureCountCacheKey, $failuresAtThreshold, $this->cacheTtl)->once()->ordered();
        // This put is for the status being set to DOWN
        $this->cacheRepoMock->shouldReceive('put')->with($statusCacheKey, ConnectionStatus::DOWN->value, $this->cacheTtl)->once()->ordered();

        $this->eventDispatcherMock->shouldReceive('dispatch')->with(Mockery::on(function($event) use ($connectionName) {
            return $event instanceof ConnectionDownEvent && $event->connectionName === $connectionName;
        }))->once();

        $stateManager = $this->createStateManager();
        $stateManager->updateConnectionStatus($connectionName);
    }

    public function test_update_connection_status_dispatches_primary_restored_event_when_primary_becomes_healthy(): void
    {
        $connectionName = $this->primaryConnectionNameConfig; // Test with the primary connection
        $statusCacheKey = $this->cachePrefix . '_conn_status_' . $connectionName;
        $failureCountCacheKey = $this->cachePrefix . '_conn_failure_count_' . $connectionName;

        $this->healthCheckerMock->shouldReceive('isHealthy')->with($connectionName)->once()->andReturn(true);

        // SUT's updateConnectionStatus calls getConnectionStatus for $previousStatus
        $this->cacheRepoMock->shouldReceive('get')->with($statusCacheKey)->once()->ordered()->andReturn(ConnectionStatus::DOWN->value); // Primary was DOWN

        // SUT updates status to HEALTHY and resets failures
        $this->cacheRepoMock->shouldReceive('put')->with($statusCacheKey, ConnectionStatus::HEALTHY->value, $this->cacheTtl)->once()->ordered();
        $this->cacheRepoMock->shouldReceive('put')->with($failureCountCacheKey, 0, $this->cacheTtl)->once()->ordered();

        // Dispatch ConnectionHealthyEvent
        $this->eventDispatcherMock->shouldReceive('dispatch')->with(Mockery::on(function($event) use ($connectionName) {
            return $event instanceof ConnectionHealthyEvent && $event->connectionName === $connectionName;
        }))->once();
        // Dispatch PrimaryConnectionRestoredEvent
        $this->eventDispatcherMock->shouldReceive('dispatch')->with(Mockery::on(function($event) use ($connectionName) {
            return $event instanceof PrimaryConnectionRestoredEvent && $event->connectionName === $connectionName;
        }))->once();

        $stateManager = $this->createStateManager();
        $stateManager->updateConnectionStatus($connectionName);
    }

    public function test_get_connection_status_returns_status_from_cache(): void
    {
        $connectionName = $this->testConnectionName;
        $statusCacheKey = $this->cachePrefix . '_conn_status_' . $connectionName;
        $this->cacheRepoMock->shouldReceive('get')->with($statusCacheKey)->once()->andReturn(ConnectionStatus::HEALTHY->value);
        $stateManager = $this->createStateManager();
        $this->assertEquals(ConnectionStatus::HEALTHY, $stateManager->getConnectionStatus($connectionName));
    }

    public function test_get_connection_status_returns_unknown_if_not_in_cache(): void
    {
        $connectionName = $this->testConnectionName;
        $statusCacheKey = $this->cachePrefix . '_conn_status_' . $connectionName;
        $this->cacheRepoMock->shouldReceive('get')->with($statusCacheKey)->once()->andReturn(null);
        $stateManager = $this->createStateManager();
        $this->assertEquals(ConnectionStatus::UNKNOWN, $stateManager->getConnectionStatus($connectionName));
    }

    public function test_get_connection_status_returns_unknown_and_dispatches_event_on_cache_exception(): void
    {
        $connectionName = $this->testConnectionName;
        $statusCacheKey = $this->cachePrefix . '_conn_status_' . $connectionName;
        $exception = new Exception('Cache down');

        $this->cacheRepoMock->shouldReceive('get')->with($statusCacheKey)->once()->andThrow($exception);

        $this->eventDispatcherMock->shouldReceive('dispatch')->with(Mockery::on(function($event) use ($exception) {
            return $event instanceof CacheUnavailableEvent && $event->exception === $exception;
        }))->once();

        $stateManager = $this->createStateManager();
        $this->assertEquals(ConnectionStatus::UNKNOWN, $stateManager->getConnectionStatus($connectionName));
    }

    public function test_get_failure_count_returns_count_from_cache(): void
    {
        $connectionName = $this->testConnectionName;
        $failureCountCacheKey = $this->cachePrefix . '_conn_failure_count_' . $connectionName;
        $expectedCount = 2;
        // SUT calls getTaggedCache()->get(key, 0)
        $this->cacheRepoMock->shouldReceive('get')->with($failureCountCacheKey, 0)->once()->andReturn($expectedCount);
        $stateManager = $this->createStateManager();
        $this->assertEquals($expectedCount, $stateManager->getFailureCount($connectionName));
    }

    public function test_get_failure_count_returns_zero_and_dispatches_event_on_cache_exception(): void
    {
        $connectionName = $this->testConnectionName;
        $failureCountCacheKey = $this->cachePrefix . '_conn_failure_count_' . $connectionName;
        $exception = new Exception('Cache down for failure count');

        $this->cacheRepoMock->shouldReceive('get')->with($failureCountCacheKey, 0)->once()->andThrow($exception);

        $this->eventDispatcherMock->shouldReceive('dispatch')->with(Mockery::on(function($event) use ($exception) {
            return $event instanceof CacheUnavailableEvent && $event->exception === $exception;
        }))->once();
        $stateManager = $this->createStateManager();
        $this->assertEquals(0, $stateManager->getFailureCount($connectionName));
    }

    public function test_set_connection_status_updates_cache_and_dispatches_healthy_event(): void
    {
        $connectionName = $this->testConnectionName;
        $status = ConnectionStatus::HEALTHY;
        $statusCacheKey = $this->cachePrefix . '_conn_status_' . $connectionName;
        $failureCountCacheKey = $this->cachePrefix . '_conn_failure_count_' . $connectionName;

        $localCacheRepoMock = Mockery::mock(CacheRepositoryContract::class, TaggedCacheInterface::class);
        $mockActualStore = Mockery::mock(MockableStoreWithTagsForTesting::class);
        $mockActualStore->shouldReceive('tags')->with($this->cacheTag)->andReturn($localCacheRepoMock);
        $localCacheRepoMock->shouldReceive('getStore')->andReturn($mockActualStore);
        $localCacheRepoMock->shouldReceive('tags')->with($this->cacheTag)->andReturn($localCacheRepoMock);

        Cache::shouldReceive('store')->withAnyArgs()->andReturn($localCacheRepoMock);

        $localCacheRepoMock->shouldReceive('put')->with($statusCacheKey, $status->value, $this->cacheTtl)->once()->ordered();
        $localCacheRepoMock->shouldReceive('get')->with($statusCacheKey)->once()->ordered()->andReturn(ConnectionStatus::DOWN->value);
        $localCacheRepoMock->shouldReceive('put')->with($failureCountCacheKey, 0, $this->cacheTtl)->once()->ordered();

        $this->eventDispatcherMock->shouldReceive('dispatch')->with(Mockery::on(function ($event) use ($connectionName) {
            return $event instanceof ConnectionHealthyEvent && $event->connectionName === $connectionName;
        }))->once();

        // Ensure SUT is instantiated directly here
        $stateManager = new ConnectionStateManager(
            $this->healthCheckerMock,
            $this->app['config'],
            $this->eventDispatcherMock
        );
        $stateManager->setConnectionStatus($connectionName, $status, 0);
    }

    public function test_set_connection_status_updates_cache_and_dispatches_down_event(): void
    {
        $connectionName = $this->primaryConnectionNameConfig;
        $status = ConnectionStatus::DOWN;
        $failureCount = $this->failureThreshold;
        $statusCacheKey = $this->cachePrefix . '_conn_status_' . $connectionName;
        $failureCountCacheKey = $this->cachePrefix . '_conn_failure_count_' . $connectionName;

        $localCacheRepoMock = Mockery::mock(CacheRepositoryContract::class, TaggedCacheInterface::class);
        $mockActualStore = Mockery::mock(MockableStoreWithTagsForTesting::class);
        $mockActualStore->shouldReceive('tags')->with($this->cacheTag)->andReturn($localCacheRepoMock);
        $localCacheRepoMock->shouldReceive('getStore')->andReturn($mockActualStore);
        $localCacheRepoMock->shouldReceive('tags')->with($this->cacheTag)->andReturn($localCacheRepoMock);

        Cache::shouldReceive('store')->withAnyArgs()->andReturn($localCacheRepoMock);

        $localCacheRepoMock->shouldReceive('put')->with($statusCacheKey, $status->value, $this->cacheTtl)->once()->ordered();
        $localCacheRepoMock->shouldReceive('get')->with($statusCacheKey)->once()->ordered()->andReturn(ConnectionStatus::HEALTHY->value);
        $localCacheRepoMock->shouldReceive('put')->with($failureCountCacheKey, $failureCount, $this->cacheTtl)->once()->ordered();

        $this->eventDispatcherMock->shouldReceive('dispatch')->with(Mockery::on(function ($event) use ($connectionName) {
            return $event instanceof ConnectionDownEvent && $event->connectionName === $connectionName;
        }))->once();

        // Ensure SUT is instantiated directly here
        $stateManager = new ConnectionStateManager(
            $this->healthCheckerMock,
            $this->app['config'],
            $this->eventDispatcherMock
        );
        $stateManager->setConnectionStatus($connectionName, $status, $failureCount);
    }

    public function test_is_connection_healthy_returns_true_for_healthy_status(): void
    {
        $connectionName = $this->testConnectionName;
        $statusCacheKey = $this->cachePrefix . '_conn_status_' . $connectionName;
        $this->cacheRepoMock->shouldReceive('get')->with($statusCacheKey)->once()->andReturn(ConnectionStatus::HEALTHY->value);
        $stateManager = $this->createStateManager();
        $this->assertTrue($stateManager->isConnectionHealthy($connectionName));
    }

    public function test_is_connection_healthy_returns_false_for_non_healthy_status(): void
    {
        $connectionName = $this->testConnectionName;
        $statusCacheKey = $this->cachePrefix . '_conn_status_' . $connectionName;

        $this->cacheRepoMock->shouldReceive('get')->with($statusCacheKey)->once()->andReturn(ConnectionStatus::DOWN->value);
        $stateManager = $this->createStateManager(); // Create new SM for new mock expectation
        $this->assertFalse($stateManager->isConnectionHealthy($connectionName));

        // Need to re-mock Cache facade for the next call if createStateManager makes a new SUT instance
        // that resolves Cache::store() again. This might be tricky if $this->cacheRepoMock is a class property.
        // Let's ensure expectations are set on the same mock SUT will use.
        // This re-mocking within a test is problematic. getEnvironmentSetUp should provide a consistent mock.
        // For now, we assume $this->cacheRepoMock is consistently used if Cache::shouldReceive is in getEnvironmentSetUp.

        $this->cacheRepoMock->shouldReceive('get')->with($statusCacheKey)->once()->andReturn(ConnectionStatus::UNKNOWN->value);
        // $stateManager = $this->createStateManager(); // Potentially not needed if SUT instance persists with old mock reference
        $this->assertFalse($this->app->make(ConnectionStateManager::class)->isConnectionHealthy($connectionName));


        $this->cacheRepoMock->shouldReceive('get')->with($statusCacheKey)->once()->andReturn(null);
        $this->assertFalse($this->app->make(ConnectionStateManager::class)->isConnectionHealthy($connectionName));
    }

    public function test_is_connection_down_returns_true_for_down_status(): void
    {
        $connectionName = $this->testConnectionName;
        $statusCacheKey = $this->cachePrefix . '_conn_status_' . $connectionName;
        $this->cacheRepoMock->shouldReceive('get')->with($statusCacheKey)->once()->andReturn(ConnectionStatus::DOWN->value);
        $stateManager = $this->createStateManager();
        $this->assertTrue($stateManager->isConnectionDown($connectionName));
    }

    public function test_is_connection_down_returns_false_for_non_down_status(): void
    {
        $connectionName = $this->testConnectionName;
        $statusCacheKey = $this->cachePrefix . '_conn_status_' . $connectionName;

        $this->cacheRepoMock->shouldReceive('get')->with($statusCacheKey)->once()->andReturn(ConnectionStatus::HEALTHY->value);
        $this->assertFalse($this->app->make(ConnectionStateManager::class)->isConnectionDown($connectionName));

        $this->cacheRepoMock->shouldReceive('get')->with($statusCacheKey)->once()->andReturn(ConnectionStatus::UNKNOWN->value);
        $this->assertFalse($this->app->make(ConnectionStateManager::class)->isConnectionDown($connectionName));

        $this->cacheRepoMock->shouldReceive('get')->with($statusCacheKey)->once()->andReturn(null);
        $this->assertFalse($this->app->make(ConnectionStateManager::class)->isConnectionDown($connectionName));
    }

    public function test_is_connection_unknown_returns_true_for_unknown_status(): void
    {
        $connectionName = $this->testConnectionName;
        $statusCacheKey = $this->cachePrefix . '_conn_status_' . $connectionName;
        $this->cacheRepoMock->shouldReceive('get')->with($statusCacheKey)->once()->andReturn(ConnectionStatus::UNKNOWN->value);
        $stateManager = $this->createStateManager();
        $this->assertTrue($stateManager->isConnectionUnknown($connectionName));
    }

    public function test_is_connection_unknown_returns_true_when_status_is_null_in_cache(): void
    {
        $connectionName = $this->testConnectionName;
        $statusCacheKey = $this->cachePrefix . '_conn_status_' . $connectionName;
        $this->cacheRepoMock->shouldReceive('get')->with($statusCacheKey)->once()->andReturn(null);
        $stateManager = $this->createStateManager();
        $this->assertTrue($stateManager->isConnectionUnknown($connectionName));
    }

    public function test_is_connection_unknown_returns_false_for_non_unknown_status(): void
    {
        $connectionName = $this->testConnectionName;
        $statusCacheKey = $this->cachePrefix . '_conn_status_' . $connectionName;

        $this->cacheRepoMock->shouldReceive('get')->with($statusCacheKey)->once()->andReturn(ConnectionStatus::HEALTHY->value);
        $this->assertFalse($this->app->make(ConnectionStateManager::class)->isConnectionUnknown($connectionName));

        $this->cacheRepoMock->shouldReceive('get')->with($statusCacheKey)->once()->andReturn(ConnectionStatus::DOWN->value);
        $this->assertFalse($this->app->make(ConnectionStateManager::class)->isConnectionUnknown($connectionName));
    }

    public function test_flush_all_statuses_flushes_tagged_cache(): void
    {
        // SUT: if (!empty($this->cacheTag) && method_exists($this->cache->getStore(), 'tags')) {
        // SUT:    $this->cache->tags($this->cacheTag)->flush();
        // $this->cache is $this->cacheRepoMock.
        // $this->cacheRepoMock->tags($this->cacheTag) returns $this->cacheRepoMock.
        // So we expect $this->cacheRepoMock->flush()

        // This test assumes getStore()->tags() path is taken.
        // Need to ensure the SUT's $this->cache (our $this->cacheRepoMock) has getStore() that returns an object with tags()
        // Re-doing part of getEnvironmentSetUp specific to this expectation for clarity, then will consolidate
        $mockStoreWithTags = Mockery::mock('StdClass');
        $mockStoreWithTags->shouldReceive('tags')->with($this->cacheTag)->andReturn($this->cacheRepoMock); // This is not quite right
                                                                                                    // SUT calls $this->cache->tags() not $this->cache->getStore()->tags()

        // Simpler: getTaggedCache() will return $this->cache->tags() if conditions met.
        // $this->cache is $this->cacheRepoMock.
        // $this->cacheRepoMock->tags($this->cacheTag) is mocked to return $this->cacheRepoMock.
        // So we expect $this->cacheRepoMock->flush() to be called.
        $this->cacheRepoMock->shouldReceive('flush')->once();

        $stateManager = $this->createStateManager();
        $stateManager->flushAllStatuses();
    }


    public function test_flush_all_statuses_when_tags_not_supported_does_not_throw_error(): void
    {
        // To simulate tags not supported, SUT's constructor checks:
        // if (!method_exists($this->cache->getStore(), 'tags')) { $this->cacheTag = ''; }
        // So, we need Cache::store() to return a mock whose getStore() returns an object without 'tags' method.

        $cacheRepoWithoutStoreTags = Mockery::mock(CacheRepositoryContract::class, TaggedCacheInterface::class);
        $storeWithoutTagsMethod = Mockery::mock(\Illuminate\Cache\FileStore::class); // FileStore doesn't have tags itself, but its Repository wrapper would
                                                                    // Let's use a simpler stdClass for the store itself
        $actualStoreObjectWithoutTags = Mockery::mock('stdClass'); // No 'tags' method here

        $cacheRepoWithoutStoreTags->shouldReceive('getStore')->andReturn($actualStoreObjectWithoutTags);
        $cacheRepoWithoutStoreTags->shouldNotReceive('tags'); // Should not be called
        $cacheRepoWithoutStoreTags->shouldNotReceive('flush'); // Flush on the repo should not be called if tags() path not taken

        // Override Cache facade for this specific test case
        Cache::shouldReceive('store')->withAnyArgs()->andReturn($cacheRepoWithoutStoreTags);

        Log::shouldReceive('warning')->with("The configured cache store '{$this->cacheStoreName}' does not support tags. Tagging functionality will be disabled for ConnectionStateManager.")->once();
        Log::shouldReceive('warning')->with("Cache store does not support tags or no tag configured. Attempting to flush known keys individually (might be incomplete).")->once();
        Log::shouldReceive('warning')->with("FlushAllStatuses without tags is not fully supported for all cache drivers. Please use a taggable cache store for reliable flushing or clear cache manually.")->once();

        // When SUT is created, it will use the above $cacheRepoWithoutStoreTags.
        // Its constructor will find no 'tags' method on $actualStoreObjectWithoutTags, and set SUT's $this->cacheTag = ''
        // Then flushAllStatuses will not call $this->cache->tags(...)->flush()

        $stateManager = $this->app->make(ConnectionStateManager::class); // Re-make to use the new Cache mock for this test
        $stateManager->flushAllStatuses();
        // Assert no exceptions were thrown (implicitly done by test passing)
    }

    // Tests for cache exceptions
    public function test_update_connection_status_dispatches_cache_unavailable_on_health_check_get_cache_exception(): void
    {
        $connectionName = $this->testConnectionName;
        $statusCacheKey = $this->cachePrefix . '_conn_status_' . $connectionName;
        $exception = new Exception('Cache down during get for health check previous status');

        $this->healthCheckerMock->shouldReceive('isHealthy')->with($connectionName)->once()->andReturn(true);

        // SUT's updateConnectionStatus -> getConnectionStatus -> $this->getTaggedCache()->get()
        $this->cacheRepoMock->shouldReceive('get')->with($statusCacheKey)->once()->andThrow($exception);

        $this->eventDispatcherMock->shouldReceive('dispatch')->with(Mockery::on(function($event) use ($exception) {
            return $event instanceof CacheUnavailableEvent && $event->exception === $exception;
        }))->once();

        // SUT's updateConnectionStatus has its own try-catch. If the ->get() above throws,
        // getConnectionStatus catches it, dispatches CacheUnavailableEvent, and returns UNKNOWN.
        // updateConnectionStatus then proceeds. If a *subsequent* cache op in updateConnectionStatus's
        // main try block fails, another CacheUnavailableEvent would be dispatched from *there*.
        // This test focuses on the event from getConnectionStatus.
        // The SUT will then try ->put(), so don't add shouldNotReceive('put') for $this->cacheRepoMock globally.

        $stateManager = $this->createStateManager();
        $stateManager->updateConnectionStatus($connectionName);
    }

    public function test_update_connection_status_dispatches_cache_unavailable_on_health_check_put_cache_exception(): void
    {
        $connectionName = $this->testConnectionName;
        $statusCacheKey = $this->cachePrefix . '_conn_status_' . $connectionName;
        // $failureCountCacheKey = $this->cachePrefix . '_conn_failure_count_' . $connectionName; // Not used for this specific path
        $exception = new Exception('Cache down during put for health check');

        $this->healthCheckerMock->shouldReceive('isHealthy')->with($connectionName)->once()->andReturn(true);

        // SUT's updateConnectionStatus:
        // 1. Calls getConnectionStatus (previousStatus). Assume this works and returns UNKNOWN.
        $this->cacheRepoMock->shouldReceive('get')->with($statusCacheKey)->once()->ordered()->andReturn(ConnectionStatus::UNKNOWN->value);
        // 2. Tries to $cache->put($statusCacheKey, ...), this is where we throw.
        $this->cacheRepoMock->shouldReceive('put')->with($statusCacheKey, ConnectionStatus::HEALTHY->value, $this->cacheTtl)->once()->ordered()->andThrow($exception);

        // No further 'put' for failure count should happen if the status 'put' fails
        // $this->cacheRepoMock->shouldNotReceive('put')->with($failureCountCacheKey, Mockery::any(), $this->cacheTtl);

        $this->eventDispatcherMock->shouldReceive('dispatch')->with(Mockery::on(function($event) use ($exception) {
            return $event instanceof CacheUnavailableEvent && $event->exception === $exception;
        }))->once();

        $stateManager = $this->createStateManager();
        $stateManager->updateConnectionStatus($connectionName);
    }

    public function test_update_connection_status_dispatches_cache_unavailable_on_failure_increment_cache_exception(): void
    {
        $connectionName = $this->testConnectionName;
        $statusCacheKey = $this->cachePrefix . '_conn_status_' . $connectionName;
        $failureCountCacheKey = $this->cachePrefix . '_conn_failure_count_' . $connectionName;
        $exception = new Exception('Cache down during increment for failure count');

        $this->healthCheckerMock->shouldReceive('isHealthy')->with($connectionName)->once()->andReturn(false); // Make it fail health check

        // SUT's updateConnectionStatus:
        // 1. Calls getConnectionStatus (previousStatus). Assume this works and returns UNKNOWN.
        $this->cacheRepoMock->shouldReceive('get')->with($statusCacheKey)->once()->ordered()->andReturn(ConnectionStatus::UNKNOWN->value);
        // 2. Tries to $this->cache->increment($failureCountCacheKey), this is where we throw.
        //    Note: SUT uses $this->cache->increment, not $this->getTaggedCache()->increment.
        //    So the mock on Cache::store() should ensure $this->cache is $this->cacheRepoMock.
        $this->cacheRepoMock->shouldReceive('increment')->with($failureCountCacheKey)->once()->ordered()->andThrow($exception);

        // No 'put' for failure count or status update if increment fails.
        // $this->cacheRepoMock->shouldNotReceive('put')->with($failureCountCacheKey, Mockery::any(), $this->cacheTtl);
        // $this->cacheRepoMock->shouldNotReceive('put')->with($statusCacheKey, ConnectionStatus::DOWN->value, $this->cacheTtl);

        $this->eventDispatcherMock->shouldReceive('dispatch')->with(Mockery::on(function($event) use ($exception) {
            return $event instanceof CacheUnavailableEvent && $event->exception === $exception;
        }))->once();

        $stateManager = $this->createStateManager();
        $stateManager->updateConnectionStatus($connectionName);
    }

    public function test_set_connection_status_dispatches_cache_unavailable_on_put_exception(): void
    {
        $connectionName = $this->testConnectionName;
        $status = ConnectionStatus::HEALTHY;
        $statusCacheKey = $this->cachePrefix . '_conn_status_' . $connectionName;
        // $failureCountCacheKey = $this->cachePrefix . '_conn_failure_count_' . $connectionName; // Not used for this path
        $exception = new Exception('Cache down during set connection status (status put)');

        // SUT's setConnectionStatus -> $cache->put($statusCacheKey, ...)
        $this->cacheRepoMock->shouldReceive('put')->with($statusCacheKey, $status->value, $this->cacheTtl)->once()->andThrow($exception);

        // If first 'put' fails, SUT should not proceed to internal 'get' or failure count 'put'.
        // $this->cacheRepoMock->shouldNotReceive('get')->with($statusCacheKey);
        // $this->cacheRepoMock->shouldNotReceive('put')->with($failureCountCacheKey, Mockery::any(), $this->cacheTtl);

        $this->eventDispatcherMock->shouldReceive('dispatch')->with(Mockery::on(function($event) use ($exception) {
            return $event instanceof CacheUnavailableEvent && $event->exception === $exception;
        }))->once();

        $stateManager = $this->createStateManager();
        $stateManager->setConnectionStatus($connectionName, $status);
    }

    public function test_set_connection_status_dispatches_cache_unavailable_on_failure_count_put_exception(): void
    {
        $connectionName = $this->testConnectionName;
        $status = ConnectionStatus::HEALTHY;
        $statusCacheKey = $this->cachePrefix . '_conn_status_' . $connectionName;
        $failureCountCacheKey = $this->cachePrefix . '_conn_failure_count_' . $connectionName;
        $exception = new Exception('Cache down during set connection status (failure count put)');

        // SUT's setConnectionStatus:
        // 1. $cache->put($statusCacheKey, ...) - Assume this works.
        $this->cacheRepoMock->shouldReceive('put')->with($statusCacheKey, $status->value, $this->cacheTtl)->once()->ordered();
        // 2. Internal getConnectionStatus() -> $this->getTaggedCache()->get() - Assume this works.
        $this->cacheRepoMock->shouldReceive('get')->with($statusCacheKey)->once()->ordered()->andReturn($status->value); // To make currentPersistedStatus === status
        // 3. $cache->put($failureCountCacheKey, 0, ...) - This is where we throw.
        $this->cacheRepoMock->shouldReceive('put')->with($failureCountCacheKey, 0, $this->cacheTtl)->once()->ordered()->andThrow($exception);

        $this->eventDispatcherMock->shouldReceive('dispatch')->with(Mockery::on(function($event) use ($exception) {
            return $event instanceof CacheUnavailableEvent && $event->exception === $exception;
        }))->once();

        // SUT event logic: if ($status === ConnectionStatus::HEALTHY) { if ($currentPersistedStatus !== ConnectionStatus::HEALTHY) { dispatch healthy } }
        // Since $currentPersistedStatus is mocked to be HEALTHY, ConnectionHealthyEvent should NOT dispatch.
        $this->eventDispatcherMock->shouldNotReceive('dispatch')->with(Mockery::type(ConnectionHealthyEvent::class));

        $stateManager = $this->createStateManager();
        $stateManager->setConnectionStatus($connectionName, $status);
    }
}
