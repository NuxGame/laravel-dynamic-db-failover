<?php

namespace Nuxgame\LaravelDynamicDBFailover\HealthCheck;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Nuxgame\LaravelDynamicDBFailover\Enums\ConnectionStatus;
use Nuxgame\LaravelDynamicDBFailover\Events\PrimaryConnectionDownEvent;
use Nuxgame\LaravelDynamicDBFailover\Events\FailoverConnectionDownEvent;
use Nuxgame\LaravelDynamicDBFailover\Events\ConnectionHealthyEvent;
use Nuxgame\LaravelDynamicDBFailover\Events\PrimaryConnectionRestoredEvent;
use Nuxgame\LaravelDynamicDBFailover\Events\FailoverConnectionRestoredEvent;
use Nuxgame\LaravelDynamicDBFailover\Events\CacheUnavailableEvent;

class ConnectionStateManager
{
    protected ConnectionHealthChecker $healthChecker;
    protected CacheRepository $cache;
    protected ConfigRepository $config;
    protected Dispatcher $events;

    protected string $cachePrefix;
    protected int $failureThreshold;
    protected int $cacheTtlSeconds;
    protected string $cacheTag;

    public function __construct(
        ConnectionHealthChecker $healthChecker,
        ConfigRepository $config,
        Dispatcher $events
    )
    {
        $this->healthChecker = $healthChecker;
        $this->config = $config;
        $this->events = $events;

        $this->cachePrefix = $this->config->get('dynamic_db_failover.cache.prefix', 'dynamic_db_failover_status');
        $this->failureThreshold = $this->config->get('dynamic_db_failover.health_check.failure_threshold', 3);
        $this->cacheTtlSeconds = $this->config->get('dynamic_db_failover.cache.ttl_seconds', 300);
        $this->cacheTag = $this->config->get('dynamic_db_failover.cache.tag', 'dynamic-db-failover');

        $cacheStoreName = $this->config->get('dynamic_db_failover.cache.store');
        $this->cache = $cacheStoreName ? Cache::store($cacheStoreName) : Cache::store();

        // Check if the selected cache store supports tags
        if (!method_exists($this->cache->getStore(), 'tags')) {
            Log::warning("The configured cache store '{$cacheStoreName}' does not support tags. Tagging functionality will be disabled for ConnectionStateManager.");
            $this->cacheTag = ''; // Disable tagging if not supported
        }
    }

    protected function getTaggedCache(): CacheRepository
    {
        if (!empty($this->cacheTag) && method_exists($this->cache->getStore(), 'tags')) {
            return $this->cache->tags($this->cacheTag);
        }
        return $this->cache;
    }

    protected function getStatusCacheKey(string $connectionName): string
    {
        return $this->cachePrefix . '_conn_status_' . $connectionName;
    }

    protected function getFailureCountCacheKey(string $connectionName): string
    {
        return $this->cachePrefix . '_conn_failure_count_' . $connectionName;
    }

    /**
     * Updates the health status of the given connection based on a new check.
     */
    public function updateConnectionStatus(string $connectionName): void
    {
        $isHealthy = $this->healthChecker->isHealthy($connectionName);
        $previousStatus = $this->getConnectionStatus($connectionName);

        if ($isHealthy) {
            $this->setConnectionStatus($connectionName, ConnectionStatus::HEALTHY, 0);
            $this->events->dispatch(new ConnectionHealthyEvent($connectionName));

            if ($previousStatus === ConnectionStatus::DOWN) {
                if ($connectionName === $this->config->get('dynamic_db_failover.connections.primary')) {
                    Log::info("Primary connection '{$connectionName}' restored.");
                    $this->events->dispatch(new PrimaryConnectionRestoredEvent($connectionName));
                } elseif ($connectionName === $this->config->get('dynamic_db_failover.connections.failover')) {
                    Log::info("Failover connection '{$connectionName}' restored.");
                    $this->events->dispatch(new FailoverConnectionRestoredEvent($connectionName));
                }
            }
        } else {
            $failureCount = $this->incrementFailureCount($connectionName);
            if ($failureCount >= $this->failureThreshold && $previousStatus !== ConnectionStatus::DOWN) {
                $this->setConnectionStatus($connectionName, ConnectionStatus::DOWN, $failureCount);
                Log::warning("Connection '{$connectionName}' marked as DOWN after {$failureCount} failures.");
                // Dispatch specific down event
                if ($connectionName === $this->config->get('dynamic_db_failover.connections.primary')) {
                    $this->events->dispatch(new PrimaryConnectionDownEvent($connectionName));
                } elseif ($connectionName === $this->config->get('dynamic_db_failover.connections.failover')) {
                    $this->events->dispatch(new FailoverConnectionDownEvent($connectionName));
                } else {
                    // Fallback for unknown connection names, though health checks are typically specific
                    // Log this case as it might be unexpected
                    Log::warning("Generic ConnectionDownEvent dispatched for an unexpected connection: {$connectionName}");
                    // If we decide ConnectionDownEvent is still needed for other cases, dispatch it here.
                    // For now, we only dispatch specific events for primary/failover.
                    // $this->events->dispatch(new ConnectionDownEvent($connectionName));
                }
            } else {
                // Still UNKNOWN or already DOWN, just update failure count if not already marked DOWN
                // If status is already DOWN, failure count is not primary info, status is.
                // If status is UNKNOWN, we update it with the new failure count.
                if ($previousStatus === ConnectionStatus::UNKNOWN) {
                     $this->setConnectionStatus($connectionName, ConnectionStatus::UNKNOWN, $failureCount);
                }
                Log::debug("Connection '{$connectionName}' unhealthy, failure count: {$failureCount}. Status: {$this->getConnectionStatus($connectionName)->value}");
            }
        }
    }

    /**
     * Retrieves the current status of the connection from cache.
     * @return ConnectionStatus Enum case
     */
    public function getConnectionStatus(string $connectionName): ConnectionStatus
    {
        $statusCacheKey = $this->getStatusCacheKey($connectionName);
        try {
            $statusValue = $this->getTaggedCache()->get($statusCacheKey);
            if ($statusValue === null) {
                Log::debug("No status found in cache for '{$connectionName}'. Returning UNKNOWN.");
                return ConnectionStatus::UNKNOWN;
            }

            $status = ConnectionStatus::tryFrom($statusValue);
            if ($status === null) {
                Log::warning("Invalid status value '{$statusValue}' found in cache for '{$connectionName}'. Returning UNKNOWN.");
                return ConnectionStatus::UNKNOWN;
            }
            return $status;
        } catch (\Exception $e) {
            Log::critical("Failed to retrieve connection status for '{$connectionName}' from cache: " . $e->getMessage());
            $this->events->dispatch(new CacheUnavailableEvent($e));
            return ConnectionStatus::UNKNOWN;
        }
    }

    /**
     * Retrieves the current failure count for the connection from cache.
     */
    public function getFailureCount(string $connectionName): int
    {
        $failureCountCacheKey = $this->getFailureCountCacheKey($connectionName);
        try {
            return (int)$this->getTaggedCache()->get($failureCountCacheKey, 0);
        } catch (\Exception $e) {
            Log::critical("Failed to retrieve failure count for '{$connectionName}' from cache: " . $e->getMessage());
            $this->events->dispatch(new CacheUnavailableEvent($e));
            return 0; // Return 0 as a safe default if cache is inaccessible
        }
    }

    /**
     * Explicitly sets the status of a connection.
     * Useful for manual overrides or when a connection is known to be down/up without a health check.
     */
    public function setConnectionStatus(string $connectionName, ConnectionStatus $status, ?int $failureCount = null): void
    {
        $statusCacheKey = $this->getStatusCacheKey($connectionName);
        $failureCountCacheKey = $this->getFailureCountCacheKey($connectionName);

        try {
            $cache = $this->getTaggedCache();
            $cache->put($statusCacheKey, $status->value, $this->cacheTtlSeconds);
            Log::info("Connection '{$connectionName}' status explicitly set to '{$status->value}'.");

            if ($status === ConnectionStatus::HEALTHY) {
                $cache->put($failureCountCacheKey, 0, $this->cacheTtlSeconds);
            } elseif ($status === ConnectionStatus::DOWN) {
                if ($failureCount !== null) {
                    $cache->put($failureCountCacheKey, $failureCount, $this->cacheTtlSeconds);
                } else {
                    $cache->put($failureCountCacheKey, $this->failureThreshold, $this->cacheTtlSeconds);
                }
            } elseif ($status === ConnectionStatus::UNKNOWN) {
                if ($failureCount !== null) {
                    $cache->put($failureCountCacheKey, $failureCount, $this->cacheTtlSeconds);
                } else {
                    // Fallback if somehow called with UNKNOWN and no explicit failure count
                    $cache->put($failureCountCacheKey, 0, $this->cacheTtlSeconds);
                }
            }

        } catch (\Exception $e) {
            Log::critical("Failed to explicitly set connection status for '{$connectionName}' in cache: " . $e->getMessage());
            $this->events->dispatch(new CacheUnavailableEvent($e));
        }
    }

     /**
     * Flushes all cached data related to dynamic DB failover.
     */
    public function flushAllStatuses(): void
    {
        try {
            if (!empty($this->cacheTag) && method_exists($this->cache->getStore(), 'tags')) {
                $this->cache->tags($this->cacheTag)->flush();
                Log::info("All dynamic DB failover statuses flushed from cache using tag '{$this->cacheTag}'.");
            } else {
                // Fallback if tags are not supported or not configured: delete known keys individually.
                // This is less ideal and might miss keys if patterns change.
                // Consider requiring a taggable cache store or implementing pattern deletion if available.
                Log::warning("Cache store does not support tags or no tag configured. Attempting to flush known keys individually (might be incomplete).");
                // This part would need a way to list keys by prefix, which Cache repository doesn't universally support.
                // For Redis, one might use SCAN. For others, this is tricky.
                // For simplicity, we will state that for non-taggable caches, flush is a no-op or manual task.
                // Or, if we know the exact keys (e.g. primary, failover), delete them.
                // For now, we log a warning and don't delete individually to avoid complexity without a reliable method.
                 Log::warning("FlushAllStatuses without tags is not fully supported for all cache drivers. Please use a taggable cache store for reliable flushing or clear cache manually.");

            }
        } catch (\Exception $e) {
            Log::critical("Failed to flush connection statuses from cache: " . $e->getMessage());
            $this->events->dispatch(new CacheUnavailableEvent($e));
        }
    }

    public function isConnectionDown(string $connectionName): bool
    {
        return $this->getConnectionStatus($connectionName) === ConnectionStatus::DOWN;
    }

    public function isConnectionHealthy(string $connectionName): bool
    {
        return $this->getConnectionStatus($connectionName) === ConnectionStatus::HEALTHY;
    }

    public function isConnectionUnknown(string $connectionName): bool
    {
        return $this->getConnectionStatus($connectionName) === ConnectionStatus::UNKNOWN;
    }

    protected function incrementFailureCount(string $connectionName): int
    {
        $count = $this->getFailureCount($connectionName) + 1;
        // Status will be updated along with this count in the calling method if threshold is met or status is UNKNOWN
        // For now, just return the incremented count. setConnectionStatus will store it.
        return $count;
    }

}
