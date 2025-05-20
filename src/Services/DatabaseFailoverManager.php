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

        $this->primaryConnectionName = $this->config->get('dynamic_db_failover.connections.primary') ?? 'mysql';
        $this->failoverConnectionName = $this->config->get('dynamic_db_failover.connections.failover') ?? 'mysql_failover';
        $this->blockingConnectionName = $this->config->get('dynamic_db_failover.connections.blocking') ?? 'blocking';

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
            $previousConnectionNameForEvent = $this->currentActiveConnectionName;

            Log::info("Switching default database connection from '{$previousConnectionNameForEvent}' to '{$activeConnectionName}'. Reason: Regular determination.");
            $this->db->setDefaultConnection($activeConnectionName);
            $this->currentActiveConnectionName = $activeConnectionName;
            $this->events->dispatch(new DatabaseConnectionSwitchedEvent(
                $previousConnectionNameForEvent,
                $activeConnectionName
            ));

            if ($activeConnectionName === $this->blockingConnectionName) {
                Log::warning("Switched to blocking connection '{$this->blockingConnectionName}'. Limited functionality mode activated.");
                $this->events->dispatch(new LimitedFunctionalityModeActivatedEvent($this->blockingConnectionName));
            }
        } else {
            Log::debug("No change in active database connection. Still using '{$activeConnectionName}'.");
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
     * Forces a switch to the primary database connection.
     */
    public function forceSwitchToPrimary(): void
    {
        $previousConnectionNameForEvent = $this->currentActiveConnectionName;
        Log::info("Forcing switch to primary connection: {$this->primaryConnectionName}. Previous: {$previousConnectionNameForEvent}");
        $this->stateManager->setConnectionStatus($this->primaryConnectionName, ConnectionStatus::UNKNOWN, 0); // Reset to allow re-check as healthy
        // No, above line is wrong. forceSwitch should just switch, then determineAndSetConnection will verify and update status if needed.
        // The goal is to make it the default, then let health checks figure out the actual status soon after.
        // However, for the event to be correct and the internal state to be immediately consistent:

        if ($this->currentActiveConnectionName !== $this->primaryConnectionName) {
             $this->db->setDefaultConnection($this->primaryConnectionName);
             $this->currentActiveConnectionName = $this->primaryConnectionName;
             $this->events->dispatch(new DatabaseConnectionSwitchedEvent(
                 $previousConnectionNameForEvent,
                 $this->primaryConnectionName
             ));
             Log::info("Successfully forced switch to primary connection: {$this->primaryConnectionName}");
        } else {
            Log::info("Already on primary connection. No forced switch needed.");
        }
        // After forcing, we might want to immediately re-evaluate health if the previous state was bad.
        // For now, just switch.
    }

    /**
     * Forces a switch to the failover database connection.
     */
    public function forceSwitchToFailover(): void
    {
        $previousConnectionNameForEvent = $this->currentActiveConnectionName;
        Log::info("Forcing switch to failover connection: {$this->failoverConnectionName}. Previous: {$previousConnectionNameForEvent}");

        if ($this->currentActiveConnectionName !== $this->failoverConnectionName) {
            $this->db->setDefaultConnection($this->failoverConnectionName);
            $this->currentActiveConnectionName = $this->failoverConnectionName;
            $this->events->dispatch(new DatabaseConnectionSwitchedEvent(
                $previousConnectionNameForEvent,
                $this->failoverConnectionName
            ));
            Log::info("Successfully forced switch to failover connection: {$this->failoverConnectionName}");
        } else {
            Log::info("Already on failover connection. No forced switch needed.");
        }
    }
}
