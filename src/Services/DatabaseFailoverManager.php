<?php

namespace Nuxgame\LaravelDynamicDBFailover\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager as DB;
use Illuminate\Support\Facades\Log;
use Nuxgame\LaravelDynamicDBFailover\Enums\ConnectionStatus;
use Nuxgame\LaravelDynamicDBFailover\HealthCheck\ConnectionStateManager;
use Nuxgame\LaravelDynamicDBFailover\Events\LimitedFunctionalityModeActivatedEvent;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Nuxgame\LaravelDynamicDBFailover\Events\SwitchedToPrimaryConnectionEvent;
use Nuxgame\LaravelDynamicDBFailover\Events\SwitchedToFailoverConnectionEvent;
use Nuxgame\LaravelDynamicDBFailover\Events\ExitedLimitedFunctionalityModeEvent;

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

            Log::info("Attempting to switch default database connection from '{$previousConnectionNameForEvent}' to '{$activeConnectionName}'.");
            $this->db->setDefaultConnection($activeConnectionName);
            $this->currentActiveConnectionName = $activeConnectionName;

            if ($activeConnectionName === $this->primaryConnectionName) {
                Log::info("Switched default database connection to PRIMARY '{$activeConnectionName}' from '{$previousConnectionNameForEvent}'.");
                $this->events->dispatch(new SwitchedToPrimaryConnectionEvent(
                    $previousConnectionNameForEvent,
                    $activeConnectionName
                ));
                if ($previousConnectionNameForEvent === $this->blockingConnectionName) {
                    Log::info("Exiting limited functionality mode. Switched to primary '{$activeConnectionName}'.");
                    $this->events->dispatch(new ExitedLimitedFunctionalityModeEvent($activeConnectionName));
                }
            } elseif ($activeConnectionName === $this->failoverConnectionName) {
                Log::info("Switched default database connection to FAILOVER '{$activeConnectionName}' from '{$previousConnectionNameForEvent}'.");
                $this->events->dispatch(new SwitchedToFailoverConnectionEvent(
                    $previousConnectionNameForEvent,
                    $activeConnectionName
                ));
                if ($previousConnectionNameForEvent === $this->blockingConnectionName) {
                    Log::info("Exiting limited functionality mode. Switched to failover '{$activeConnectionName}'.");
                    $this->events->dispatch(new ExitedLimitedFunctionalityModeEvent($activeConnectionName));
                }
            } elseif ($activeConnectionName === $this->blockingConnectionName) {
                if ($previousConnectionNameForEvent !== $this->blockingConnectionName) {
                    Log::warning("Switched to blocking connection '{$this->blockingConnectionName}'. Limited functionality mode activated.");
                    $this->events->dispatch(new LimitedFunctionalityModeActivatedEvent($this->blockingConnectionName));
                } else {
                    Log::info("Remained on blocking connection '{$this->blockingConnectionName}'. No new LimitedFunctionalityModeActivatedEvent dispatched.");
                }
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

        // When forcing a switch to primary, we assume it's now healthy and reset its status.
        // Also reset failover status, as part of restoring primary preference.
        $this->stateManager->setConnectionStatus($this->primaryConnectionName, ConnectionStatus::HEALTHY, 0);
        $this->stateManager->setConnectionStatus($this->failoverConnectionName, ConnectionStatus::HEALTHY, 0);

        if ($this->currentActiveConnectionName !== $this->primaryConnectionName) {
             $this->db->setDefaultConnection($this->primaryConnectionName);
             $this->currentActiveConnectionName = $this->primaryConnectionName;
             $this->events->dispatch(new SwitchedToPrimaryConnectionEvent(
                 $previousConnectionNameForEvent,
                 $this->primaryConnectionName
             ));
             if ($previousConnectionNameForEvent === $this->blockingConnectionName) {
                Log::info("Exiting limited functionality mode due to forced switch to primary '{$this->primaryConnectionName}'.");
                $this->events->dispatch(new ExitedLimitedFunctionalityModeEvent($this->primaryConnectionName));
            }
             Log::info("Successfully forced switch to primary connection: {$this->primaryConnectionName}");
        } else {
            Log::info("Already on primary connection. No forced switch needed.");
        }
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
            $this->events->dispatch(new SwitchedToFailoverConnectionEvent(
                $previousConnectionNameForEvent,
                $this->failoverConnectionName
            ));
            if ($previousConnectionNameForEvent === $this->blockingConnectionName) {
                Log::info("Exiting limited functionality mode due to forced switch to failover '{$this->failoverConnectionName}'.");
                $this->events->dispatch(new ExitedLimitedFunctionalityModeEvent($this->failoverConnectionName));
            }
            Log::info("Successfully forced switch to failover connection: {$this->failoverConnectionName}");
        } else {
            Log::info("Already on failover connection. No forced switch needed.");
        }
    }
}
