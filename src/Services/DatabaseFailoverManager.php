<?php

namespace Nuxgame\LaravelDynamicDBFailover\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager as DB;
use Illuminate\Support\Facades\Log;
use Nuxgame\LaravelDynamicDBFailover\Enums\ConnectionStatus;
use Nuxgame\LaravelDynamicDBFailover\HealthCheck\ConnectionStateManager;
use Nuxgame\LaravelDynamicDBFailover\Events\DatabaseConnectionSwitchedEvent;
use Nuxgame\LaravelDynamicDBFailover\Events\LimitedFunctionalityModeActivatedEvent;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;

class DatabaseFailoverManager
{
    protected ConfigRepository $config;
    protected ConnectionStateManager $stateManager;
    protected DB $db;
    protected EventDispatcher $events;

    protected string $primaryConnectionName;
    protected string $failoverConnectionName;
    protected string $blockingConnectionName;
    protected ?string $currentActiveConnectionName = null;

    public function __construct(
        ConfigRepository $config,
        ConnectionStateManager $stateManager,
        DB $db,
        EventDispatcher $events
    )
    {
        $this->config = $config;
        $this->stateManager = $stateManager;
        $this->db = $db;
        $this->events = $events;

        $this->primaryConnectionName = $this->config->get('dynamic_db_failover.connections.primary', 'mysql');
        $this->failoverConnectionName = $this->config->get('dynamic_db_failover.connections.failover', 'mysql_failover');
        $this->blockingConnectionName = $this->config->get('dynamic_db_failover.connections.blocking', 'blocking');

        if (empty($this->primaryConnectionName) || empty($this->failoverConnectionName) || empty($this->blockingConnectionName)) {
            Log::warning('DynamicDBFailover: One or more connection names (primary, failover, blocking) are not configured correctly. Please check your dynamic_db_failover.php config file.');
        }
    }

    /**
     * Determines and sets the active database connection based on health states.
     * This method should be called early in the request lifecycle, or before any DB operation.
     */
    public function determineAndSetConnection(): string
    {
        $activeConnectionName = $this->resolveActiveConnection();

        if ($this->currentActiveConnectionName !== $activeConnectionName) {
            Log::info("Switching default database connection from '{$this->currentActiveConnectionName}' to '{$activeConnectionName}'.");
            $this->db->setDefaultConnection($activeConnectionName);
            $this->currentActiveConnectionName = $activeConnectionName;
            $this->events->dispatch(new DatabaseConnectionSwitchedEvent($this->currentActiveConnectionName, $activeConnectionName));

            if ($activeConnectionName === $this->blockingConnectionName) {
                $this->events->dispatch(new LimitedFunctionalityModeActivatedEvent());
            }
        }
        return $activeConnectionName;
    }

    /**
     * Resolves which connection should be active based on their status.
     */
    protected function resolveActiveConnection(): string
    {
        $primaryConnectionNameStr = $this->primaryConnectionName; // for logging
        $failoverConnectionNameStr = $this->failoverConnectionName; // for logging

        $primaryStatus = $this->stateManager->getConnectionStatus($this->primaryConnectionName);
        $failoverStatus = $this->stateManager->getConnectionStatus($this->failoverConnectionName);

        // Handle cache unavailability scenario - default to primary
        // If both are UNKNOWN and have 0 failure counts, it might indicate cache issues.
        if ($primaryStatus === ConnectionStatus::UNKNOWN && $this->stateManager->getFailureCount($this->primaryConnectionName) === 0 &&
            $failoverStatus === ConnectionStatus::UNKNOWN && $this->stateManager->getFailureCount($this->failoverConnectionName) === 0) {
            Log::warning("Cache might be unavailable or statuses not yet determined. Defaulting to primary connection '{$primaryConnectionNameStr}'.");
            return $this->primaryConnectionName;
        }

        if ($primaryStatus === ConnectionStatus::HEALTHY) {
            Log::debug("Primary connection '{$primaryConnectionNameStr}' is HEALTHY. Setting as active.");
            return $this->primaryConnectionName;
        }

        Log::warning("Primary connection '{$primaryConnectionNameStr}' is not healthy (Status: {$primaryStatus->value}). Checking failover.");

        if ($failoverStatus === ConnectionStatus::HEALTHY) {
            Log::info("Failover connection '{$failoverConnectionNameStr}' is HEALTHY. Setting as active.");
            return $this->failoverConnectionName;
        }

        Log::error("Both primary ('{$primaryConnectionNameStr}' - Status: {$primaryStatus->value}) and failover ('{$failoverConnectionNameStr}' - Status: {$failoverStatus->value}) connections are unavailable. Activating blocking connection.");
        return $this->blockingConnectionName;
    }

    /**
     * Gets the currently configured active connection name by this manager.
     * Note: This might not reflect Laravel's actual default if it was changed elsewhere.
     */
    public function getCurrentActiveConnectionName(): ?string
    {
        return $this->currentActiveConnectionName ?? $this->db->getDefaultConnection();
    }

    /**
     * Manually forces a switch to the primary connection.
     * Resets its status in cache to UNKNOWN to trigger a fresh health check on next cycle.
     */
    public function forceSwitchToPrimary(): void
    {
        Log::info("Forcing switch to primary connection: {$this->primaryConnectionName}");
        $this->stateManager->setConnectionStatus($this->primaryConnectionName, ConnectionStatus::UNKNOWN, 0);
        $this->determineAndSetConnection();
    }

    /**
     * Manually forces a switch to the failover connection.
     * Resets its status in cache to UNKNOWN to trigger a fresh health check on next cycle.
     */
    public function forceSwitchToFailover(): void
    {
        Log::info("Forcing switch to failover connection: {$this->failoverConnectionName}");
        $this->stateManager->setConnectionStatus($this->failoverConnectionName, ConnectionStatus::UNKNOWN, 0);
        $this->determineAndSetConnection();
    }
}
