<?php

namespace Nuxgame\LaravelDynamicDBFailover\HealthCheck;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Cache\Repository as CacheRepositoryContract;
use Illuminate\Contracts\Cache\Factory as CacheFactoryContract;
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

/**
 * Class ConnectionStateManager
 *
 * Manages the health state (status and failure counts) of monitored database connections.
 * It uses a cache to persist these states and dispatches events upon significant state changes
 * (e.g., connection down, connection healthy, cache unavailability).
 */
class ConnectionStateManager
{
    /** @var ConnectionHealthChecker Service to perform actual health checks on connections. */
    protected ConnectionHealthChecker $healthChecker;

    /** @var CacheRepositoryContract The resolved cache repository instance for storing states. */
    protected CacheRepositoryContract $cache;

    /** @var ConfigRepository Repository for accessing package and application configurations. */
    protected ConfigRepository $config;

    /** @var Dispatcher Service for dispatching events related to connection state changes. */
    protected Dispatcher $events;

    /** @var string Prefix for cache keys used by this manager. */
    protected string $cachePrefix;

    /** @var int Number of consecutive failures before a connection is marked as DOWN. */
    protected int $failureThreshold;

    /** @var int Time-to-live in seconds for cached connection states. */
    protected int $cacheTtlSeconds;

    /** @var string Cache tag used for grouping and flushing failover-related cache entries. */
    protected string $cacheTag;

    /**
     * ConnectionStateManager constructor.
     *
     * @param ConnectionHealthChecker $healthChecker Service for checking connection health.
     * @param ConfigRepository $config Repository for configuration values.
     * @param Dispatcher $events Service for dispatching events.
     * @param CacheFactoryContract $cacheFactory Factory to resolve the appropriate cache store.
     */
    public function __construct(
        ConnectionHealthChecker $healthChecker,
        ConfigRepository $config,
        Dispatcher $events,
        CacheFactoryContract $cacheFactory
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
        $this->cache = $cacheFactory->store($cacheStoreName);

        // Check if the selected cache store supports tags
        if (!method_exists($this->cache->getStore(), 'tags')) {
            Log::warning("The configured cache store '{$cacheStoreName}' does not support tags. Tagging functionality will be disabled for ConnectionStateManager.");
            $this->cacheTag = ''; // Disable tagging if not supported
        }
    }

    /**
     * Returns the configured cache repository, applying tags if supported and configured.
     *
     * @return CacheRepositoryContract The cache repository instance (possibly tagged).
     */
    protected function getTaggedCache(): CacheRepositoryContract
    {
        if (!empty($this->cacheTag) && method_exists($this->cache->getStore(), 'tags')) {
            /** @var \Illuminate\Cache\Repository $cache */
            $cache = $this->cache;
            return $cache->tags($this->cacheTag);
        }
        return $this->cache;
    }

    /**
     * Generates the cache key for storing a connection's status.
     *
     * @param string $connectionName The name of the database connection.
     * @return string The generated cache key.
     */
    protected function getStatusCacheKey(string $connectionName): string
    {
        return $this->cachePrefix . '_conn_status_' . $connectionName;
    }

    /**
     * Generates the cache key for storing a connection's failure count.
     *
     * @param string $connectionName The name of the database connection.
     * @return string The generated cache key.
     */
    protected function getFailureCountCacheKey(string $connectionName): string
    {
        return $this->cachePrefix . '_conn_failure_count_' . $connectionName;
    }

    /**
     * Updates the health status of the given connection based on a new health check.
     * If the connection is healthy, its status is set to HEALTHY and failure count reset.
     * If unhealthy, the failure count is incremented. If the threshold is met, status becomes DOWN.
     * Dispatches events like ConnectionHealthyEvent, PrimaryConnectionRestoredEvent,
     * FailoverConnectionRestoredEvent, PrimaryConnectionDownEvent, or FailoverConnectionDownEvent accordingly.
     *
     * @param string $connectionName The name of the database connection to update.
     * @return void
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
                    Log::info("Primary connection '{$connectionName}' restored after being down.");
                    $this->events->dispatch(new PrimaryConnectionRestoredEvent($connectionName));
                } elseif ($connectionName === $this->config->get('dynamic_db_failover.connections.failover')) {
                    Log::info("Failover connection '{$connectionName}' restored after being down.");
                    $this->events->dispatch(new FailoverConnectionRestoredEvent($connectionName));
                }
            }
        } else {
            $failureCount = $this->incrementFailureCount($connectionName);

            if ($failureCount >= $this->failureThreshold) {
                // Only mark as DOWN and dispatch event if it wasn't already DOWN.
                if ($previousStatus !== ConnectionStatus::DOWN) {
                    $this->setConnectionStatus($connectionName, ConnectionStatus::DOWN, $failureCount);
                    Log::warning("Connection '{$connectionName}' marked as DOWN after {$failureCount} failures.");

                    if ($connectionName === $this->config->get('dynamic_db_failover.connections.primary')) {
                        $this->events->dispatch(new PrimaryConnectionDownEvent($connectionName));
                    } elseif ($connectionName === $this->config->get('dynamic_db_failover.connections.failover')) {
                        $this->events->dispatch(new FailoverConnectionDownEvent($connectionName));
                    } else {
                        Log::warning("A non-primary/failover connection '{$connectionName}' reached failure threshold. Consider specific event or review configuration.");
                        // No generic ConnectionDownEvent dispatched here by design, focus on primary/failover state for now.
                    }
                } else {
                    // Already DOWN, ensure failure count in cache reflects the latest increment if it changed.
                    // This might happen if health checks continue on a DOWN connection.
                    $this->setConnectionStatus($connectionName, ConnectionStatus::DOWN, $failureCount); // Re-set to update TTL and count
                    Log::debug("Connection '{$connectionName}' remains DOWN. Failure count: {$failureCount}.");
                }
            } else {
                // Still under threshold, status remains UNKNOWN (or potentially HEALTHY if it flapped quickly, though previousStatus check handles that).
                // Update status to UNKNOWN with the new failure count.
                $this->setConnectionStatus($connectionName, ConnectionStatus::UNKNOWN, $failureCount);
                Log::debug("Connection '{$connectionName}' unhealthy, failure count: {$failureCount} (under threshold). Status: UNKNOWN.");
            }
        }
    }

    /**
     * Retrieves the current status of the connection from cache.
     * Returns ConnectionStatus::UNKNOWN if not found, invalid, or if cache is unavailable (dispatches CacheUnavailableEvent).
     *
     * @param string $connectionName The name of the database connection.
     * @return ConnectionStatus The current status of the connection.
     */
    public function getConnectionStatus(string $connectionName): ConnectionStatus
    {
        $statusCacheKey = $this->getStatusCacheKey($connectionName);
        try {
            $statusValue = $this->getTaggedCache()->get($statusCacheKey);
            if ($statusValue === null) {
                Log::debug("No status found in cache for '{$connectionName}'. Defaulting to UNKNOWN.");
                return ConnectionStatus::UNKNOWN;
            }

            $status = ConnectionStatus::tryFrom((string) $statusValue); // Cast to string for tryFrom
            if ($status === null) {
                Log::warning("Invalid status value '{$statusValue}' found in cache for '{$connectionName}'. Defaulting to UNKNOWN.");
                // Optionally, remove the invalid status from cache here
                // $this->getTaggedCache()->forget($statusCacheKey);
                return ConnectionStatus::UNKNOWN;
            }
            return $status;
        } catch (\Exception $e) {
            Log::critical("Cache Exception: Failed to retrieve connection status for '{$connectionName}': " . $e->getMessage(), [
                'connection' => $connectionName, 'exception' => $e
            ]);
            $this->events->dispatch(new CacheUnavailableEvent($e));
            return ConnectionStatus::UNKNOWN;
        }
    }

    /**
     * Retrieves the current failure count for the connection from cache.
     * Returns 0 if not found or if cache is unavailable (dispatches CacheUnavailableEvent).
     *
     * @param string $connectionName The name of the database connection.
     * @return int The current failure count.
     */
    public function getFailureCount(string $connectionName): int
    {
        $failureCountCacheKey = $this->getFailureCountCacheKey($connectionName);
        try {
            return (int)$this->getTaggedCache()->get($failureCountCacheKey, 0);
        } catch (\Exception $e) {
            Log::critical("Cache Exception: Failed to retrieve failure count for '{$connectionName}': " . $e->getMessage(), [
                'connection' => $connectionName, 'exception' => $e
            ]);
            $this->events->dispatch(new CacheUnavailableEvent($e));
            return 0; // Return 0 as a safe default if cache is inaccessible
        }
    }

    /**
     * Explicitly sets the status and failure count of a connection in the cache.
     * This can be used for manual overrides or initializing state.
     * Dispatches CacheUnavailableEvent if cache operations fail.
     *
     * @param string $connectionName The name of the database connection.
     * @param ConnectionStatus $status The status to set.
     * @param int|null $failureCount The failure count to set. If null, defaults based on status
     *                             (0 for HEALTHY, threshold for DOWN, 0 for UNKNOWN).
     * @return void
     */
    public function setConnectionStatus(string $connectionName, ConnectionStatus $status, ?int $failureCount = null): void
    {
        $finalFailureCount = $failureCount; // Placeholder, original logic to be kept

        if ($failureCount === null) {
            switch ($status) {
                case ConnectionStatus::HEALTHY:
                    $finalFailureCount = 0;
                    break;
                case ConnectionStatus::DOWN:
                    // If setting to DOWN without a specific count, assume it met the threshold
                    // or use a high value if threshold isn't directly relevant here.
                    // For consistency, might be better to require explicit count for DOWN.
                    // However, to avoid breaking existing calls, let's use a sensible default or stored one.
                    // Reading current count if setting to DOWN without specific could be an option.
                    $currentFailures = (int)$this->getTaggedCache()->get($this->getFailureCountCacheKey($connectionName), 0);
                    $finalFailureCount = max($currentFailures, $this->failureThreshold); // Ensure it's at least threshold
                    break;
                case ConnectionStatus::UNKNOWN:
                default:
                    $finalFailureCount = $failureCount ?? 0; // Default to 0 if not provided for UNKNOWN
                    break;
            }
        } else {
            $finalFailureCount = $failureCount;
        }

        $statusCacheKey = $this->getStatusCacheKey($connectionName);
        $failureCountCacheKey = $this->getFailureCountCacheKey($connectionName);

        try {
            $this->getTaggedCache()->put($statusCacheKey, $status->value, $this->cacheTtlSeconds);
            $this->getTaggedCache()->put($failureCountCacheKey, $finalFailureCount, $this->cacheTtlSeconds);
            Log::debug("Set status for '{$connectionName}' to {$status->value} with failure count {$finalFailureCount}. TTL: {$this->cacheTtlSeconds}s.");
        } catch (\Exception $e) {
            Log::critical("Cache Exception: Failed to set connection state for '{$connectionName}': " . $e->getMessage(), [
                'connection' => $connectionName, 'status' => $status->value, 'failure_count' => $finalFailureCount, 'exception' => $e
            ]);
            $this->events->dispatch(new CacheUnavailableEvent($e));
        }
    }

    /**
     * Increments the failure count for a given connection and stores it in the cache.
     * Dispatches CacheUnavailableEvent if cache operations fail.
     *
     * @param string $connectionName The name of the database connection.
     * @return int The new failure count after incrementing.
     */
    protected function incrementFailureCount(string $connectionName): int
    {
        $currentFailures = $this->getFailureCount($connectionName);
        $newFailures = $currentFailures + 1;

        try {
            $this->getTaggedCache()->put($this->getFailureCountCacheKey($connectionName), $newFailures, $this->cacheTtlSeconds);
            Log::debug("Incremented failure count for '{$connectionName}' to {$newFailures}.");
            return $newFailures;
        } catch (\Exception $e) {
            Log::critical("Cache Exception: Failed to increment failure count for '{$connectionName}': " . $e->getMessage(), [
                'connection' => $connectionName, 'exception' => $e
            ]);
            $this->events->dispatch(new CacheUnavailableEvent($e));
            // If cache put fails, return current (stale) count + 1, though it wasn't persisted.
            // The impact is that the next check might re-increment from the stale value.
            return $newFailures;
        }
    }

    /**
     * Flushes all cached statuses and failure counts related to dynamic DB failover.
     * Uses cache tags if supported and configured; otherwise, logs a warning about potential incompleteness.
     * Dispatches CacheUnavailableEvent if cache operations fail.
     *
     * @return void
     */
    public function flushAllStatuses(): void
    {
        try {
            if (!empty($this->cacheTag) && method_exists($this->cache->getStore(), 'tags')) {
                // When tags are supported and configured, flush only the tagged entries.
                /** @var \Illuminate\Cache\Repository $cache */
                $cache = $this->cache;
                /** @var \\Illuminate\\Cache\\TaggedCache $taggedCache */
                $taggedCache = $cache->tags($this->cacheTag);
                $taggedCache->flush();
                Log::info("All dynamic DB failover statuses flushed from cache using tag '{$this->cacheTag}'.");
            } else {
                // If tags are not supported/configured, selective flushing of only failover keys is not reliably possible
                // across all cache drivers without iterating keys (which isn't standard in CacheRepositoryContract).
                // Flushing the entire store ($this->cache->getStore()->flush()) is too broad an action for this method.
                // Therefore, we log a detailed warning advising on manual cleanup or using a taggable store.
                Log::warning(
                    "Cache store '{$this->config->get('dynamic_db_failover.cache.store', 'default')}' " .
                    "does not support tags or no tag is configured for '{$this->cacheTag}'. " .
                    "Cannot selectively flush only failover statuses. Please use a taggable cache store, or clear relevant cache keys manually " .
                    "(e.g., keys prefixed with '{$this->cachePrefix}')."
                );
            }
        } catch (\Exception $e) {
            Log::critical("Cache Exception: Failed to flush connection statuses: " . $e->getMessage(), ['exception' => $e]);
            $this->events->dispatch(new CacheUnavailableEvent($e));
        }
    }

    /**
     * Checks if the specified connection is currently marked as DOWN.
     *
     * @param string $connectionName The name of the database connection.
     * @return bool True if the connection status is DOWN, false otherwise.
     */
    public function isConnectionDown(string $connectionName): bool
    {
        return $this->getConnectionStatus($connectionName) === ConnectionStatus::DOWN;
    }

    /**
     * Checks if the specified connection is currently marked as HEALTHY.
     *
     * @param string $connectionName The name of the database connection.
     * @return bool True if the connection status is HEALTHY, false otherwise.
     */
    public function isConnectionHealthy(string $connectionName): bool
    {
        return $this->getConnectionStatus($connectionName) === ConnectionStatus::HEALTHY;
    }

    /**
     * Checks if the specified connection is currently in an UNKNOWN state.
     *
     * @param string $connectionName The name of the database connection.
     * @return bool True if the connection status is UNKNOWN, false otherwise.
     */
    public function isConnectionUnknown(string $connectionName): bool
    {
        return $this->getConnectionStatus($connectionName) === ConnectionStatus::UNKNOWN;
    }
}
