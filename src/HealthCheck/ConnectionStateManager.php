<?php

namespace Nuxgame\LaravelDynamicDBFailover\HealthCheck;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Nuxgame\LaravelDynamicDBFailover\Constants\ConnectionStatus;
use Nuxgame\LaravelDynamicDBFailover\Events\ConnectionDownEvent;
use Nuxgame\LaravelDynamicDBFailover\Events\ConnectionHealthyEvent;
use Nuxgame\LaravelDynamicDBFailover\Events\CacheUnavailableEvent;
use Nuxgame\LaravelDynamicDBFailover\Events\PrimaryConnectionRestoredEvent;

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
        $isCurrentlyHealthy = $this->healthChecker->isHealthy($connectionName);
        $statusCacheKey = $this->getStatusCacheKey($connectionName);
        $failureCountCacheKey = $this->getFailureCountCacheKey($connectionName);
        $previousStatus = $this->getConnectionStatus($connectionName); // Get status before update

        try {
            $cache = $this->getTaggedCache();
            if ($isCurrentlyHealthy) {
                $cache->put($statusCacheKey, ConnectionStatus::HEALTHY->value, $this->cacheTtlSeconds);
                $cache->put($failureCountCacheKey, 0, $this->cacheTtlSeconds);
                Log::info("Connection '{$connectionName}' is HEALTHY. Status updated in cache.");
                $this->events->dispatch(new ConnectionHealthyEvent($connectionName));

                // If the primary connection was previously down and is now healthy
                if ($connectionName === $this->config->get('dynamic_db_failover.connections.primary') && $previousStatus === ConnectionStatus::DOWN) {
                    $this->events->dispatch(new PrimaryConnectionRestoredEvent($connectionName));
                }

            } else {
                $currentFailures = (int)$this->cache->increment($failureCountCacheKey);
                $cache->put($failureCountCacheKey, $currentFailures, $this->cacheTtlSeconds);

                Log::warning("Health check failed for connection '{$connectionName}'. Current failure count: {$currentFailures}.");

                if ($currentFailures >= $this->failureThreshold) {
                    if ($previousStatus !== ConnectionStatus::DOWN) { // Dispatch event only on transition to DOWN
                        $cache->put($statusCacheKey, ConnectionStatus::DOWN->value, $this->cacheTtlSeconds);
                        Log::error("Connection '{$connectionName}' marked as DOWN after reaching failure threshold ({$this->failureThreshold}). Status updated in cache.");
                        $this->events->dispatch(new ConnectionDownEvent($connectionName));
                    } else {
                        $cache->put($statusCacheKey, ConnectionStatus::DOWN->value, $this->cacheTtlSeconds);
                    }
                } else {
                    // If status was HEALTHY and we didn't reach threshold for DOWN, it remains HEALTHY in cache until TTL
                    // or explicitly set to UNKNOWN if we want to reflect uncertainty sooner.
                    // Current logic: if not DOWN, its previous status (HEALTHY/UNKNOWN) remains until next update or TTL.
                    // If it was UNKNOWN, it remains UNKNOWN.
                    // If it was HEALTHY, it remains HEALTHY but failures increment.
                }
            }
        } catch (\Exception $e) {
            Log::critical("Failed to update connection status for '{$connectionName}' in cache: " . $e->getMessage());
            $this->events->dispatch(new CacheUnavailableEvent($e));
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

            $currentPersistedStatus = $this->getConnectionStatus($connectionName); // Re-fetch to confirm and for event logic

            if ($status === ConnectionStatus::HEALTHY) {
                $cache->put($failureCountCacheKey, 0, $this->cacheTtlSeconds);
                if ($currentPersistedStatus !== ConnectionStatus::HEALTHY) { // Dispatch only if status changed effectively
                   $this->events->dispatch(new ConnectionHealthyEvent($connectionName));
                }
            } elseif ($status === ConnectionStatus::DOWN) {
                if ($failureCount !== null) {
                    $cache->put($failureCountCacheKey, $failureCount, $this->cacheTtlSeconds);
                } else {
                    $cache->put($failureCountCacheKey, $this->failureThreshold, $this->cacheTtlSeconds);
                }
                if ($currentPersistedStatus !== ConnectionStatus::DOWN) { // Dispatch only if status changed effectively
                    $this->events->dispatch(new ConnectionDownEvent($connectionName));
                }
            } elseif ($status === ConnectionStatus::UNKNOWN) {
                $cache->put($failureCountCacheKey, 0, $this->cacheTtlSeconds);
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

}
