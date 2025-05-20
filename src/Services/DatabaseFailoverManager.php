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

/**
 * Class DatabaseFailoverManager
 *
 * Manages the active database connection for the application based on the health states
 * of configured primary and failover connections. It interacts with ConnectionStateManager
 * to get health statuses, sets Laravel's default database connection, and dispatches
 * events related to connection switching and mode changes (e.g., limited functionality).
 */
class DatabaseFailoverManager
{
    /** @var ConfigRepository Repository for accessing application configurations. */
    protected ConfigRepository $config;

    /** @var ConnectionStateManager Service to manage and retrieve health states of connections. */
    protected ConnectionStateManager $stateManager;

    /** @var DB Laravel's database manager instance for setting the default connection. */
    protected DB $db;

    /** @var EventDispatcher Service for dispatching events. */
    protected EventDispatcher $events;

    /** @var string Name of the primary database connection. */
    protected string $primaryConnectionName;

    /** @var string Name of the failover database connection. */
    protected string $failoverConnectionName;

    /** @var string Name of the blocking database connection (used when primary and failover are down). */
    protected string $blockingConnectionName;

    /** @var string|null Tracks the name of the connection currently set as default by this manager. */
    protected ?string $currentActiveConnectionName = null;

    /**
     * DatabaseFailoverManager constructor.
     *
     * @param ConfigRepository $config The configuration repository.
     * @param ConnectionStateManager $stateManager The connection state manager.
     * @param DB $db The database manager.
     * @param EventDispatcher $events The event dispatcher.
     */
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

        // Load connection names from config, with fallbacks.
        $this->primaryConnectionName = $this->config->get('dynamic_db_failover.connections.primary') ?? 'mysql';
        $this->failoverConnectionName = $this->config->get('dynamic_db_failover.connections.failover') ?? 'mysql_failover';
        $this->blockingConnectionName = $this->config->get('dynamic_db_failover.connections.blocking') ?? 'blocking';

        // Log a warning if any connection name is missing, as this indicates a configuration issue.
        if (empty($this->primaryConnectionName) || empty($this->failoverConnectionName) || empty($this->blockingConnectionName)) {
            Log::warning('DynamicDBFailover: One or more connection names (primary, failover, blocking) are not configured correctly. Please check your dynamic_db_failover.php config file.');
        }
    }

    /**
     * Determines the appropriate database connection based on health states and sets it
     * as Laravel's default connection if it differs from the currently tracked active connection.
     * Dispatches events upon successful connection switch or mode changes.
     *
     * This method should be called early in the application lifecycle (e.g., in a service provider's boot method
     * or via middleware) before significant database operations occur.
     *
     * @return string The name of the connection that was determined to be active.
     */
    public function determineAndSetConnection(): string
    {
        $activeConnectionName = $this->resolveActiveConnection();

        // Only switch and dispatch events if the resolved active connection is different from the current one.
        if ($this->currentActiveConnectionName !== $activeConnectionName) {
            $previousConnectionNameForEvent = $this->currentActiveConnectionName;

            Log::info("Attempting to switch default database connection from '{$previousConnectionNameForEvent}' to '{$activeConnectionName}'.");
            $this->db->setDefaultConnection($activeConnectionName);
            $this->currentActiveConnectionName = $activeConnectionName; // Update tracked active connection

            // Dispatch events based on the type of switch
            if ($activeConnectionName === $this->primaryConnectionName) {
                Log::info("Switched default database connection to PRIMARY '{$activeConnectionName}' from '{$previousConnectionNameForEvent}'.");
                $this->events->dispatch(new SwitchedToPrimaryConnectionEvent(
                    $previousConnectionNameForEvent,
                    $activeConnectionName
                ));
                // If previously in blocking mode, dispatch event indicating exit from limited functionality.
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
                // If previously in blocking mode, dispatch event indicating exit from limited functionality.
                if ($previousConnectionNameForEvent === $this->blockingConnectionName) {
                    Log::info("Exiting limited functionality mode. Switched to failover '{$activeConnectionName}'.");
                    $this->events->dispatch(new ExitedLimitedFunctionalityModeEvent($activeConnectionName));
                }
            } elseif ($activeConnectionName === $this->blockingConnectionName) {
                // Only dispatch LimitedFunctionalityModeActivatedEvent if not already in blocking mode.
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
     * Resolves which database connection should be active based on their current health status.
     * The priority is: Primary (if HEALTHY) -> Failover (if HEALTHY) -> Blocking.
     * If both primary and failover statuses are UNKNOWN with zero failures (initial state or cache issue),
     * it defaults to the primary connection as a precaution.
     *
     * @return string The name of the connection that should be active.
     */
    protected function resolveActiveConnection(): string
    {
        $primaryStatus = $this->stateManager->getConnectionStatus($this->primaryConnectionName);
        $failoverStatus = $this->stateManager->getConnectionStatus($this->failoverConnectionName);

        // Scenario: Initial state or cache is unavailable/cleared.
        // If both connections are UNKNOWN and have no recorded failures, it's safer to try primary first.
        if ($primaryStatus === ConnectionStatus::UNKNOWN && $this->stateManager->getFailureCount($this->primaryConnectionName) === 0 &&
            $failoverStatus === ConnectionStatus::UNKNOWN && $this->stateManager->getFailureCount($this->failoverConnectionName) === 0) {
            Log::warning("Both primary ('{$this->primaryConnectionName}') and failover ('{$this->failoverConnectionName}') statuses are UNKNOWN with no failures. This might be an initial state or cache issue. Defaulting to primary connection.");
            return $this->primaryConnectionName;
        }

        // Prefer primary connection if it's healthy.
        if ($primaryStatus === ConnectionStatus::HEALTHY) {
            Log::debug("Primary connection '{$this->primaryConnectionName}' is HEALTHY. Setting as active.");
            return $this->primaryConnectionName;
        }

        // Primary is not healthy, log this and check failover.
        Log::warning("Primary connection '{$this->primaryConnectionName}' is not healthy (Status: {$primaryStatus->value}). Checking failover connection '{$this->failoverConnectionName}'.");

        // Try failover connection if it's healthy.
        if ($failoverStatus === ConnectionStatus::HEALTHY) {
            Log::info("Failover connection '{$this->failoverConnectionName}' is HEALTHY. Setting as active.");
            return $this->failoverConnectionName;
        }

        // Both primary and failover are unavailable. Activate the blocking connection.
        Log::error("Both primary ('{$this->primaryConnectionName}' - Status: {$primaryStatus->value}) and failover ('{$this->failoverConnectionName}' - Status: {$failoverStatus->value}) connections are unavailable. Activating blocking connection '{$this->blockingConnectionName}'.");
        return $this->blockingConnectionName;
    }

    /**
     * Gets the name of the database connection that this manager currently considers active.
     * This is based on the last call to `determineAndSetConnection` or `forceSwitchTo*`.
     * If no connection has been explicitly set by this manager yet during the current request,
     * it falls back to Laravel's current default connection name.
     *
     * @return string|null The name of the active connection, or null if indeterminate.
     */
    public function getCurrentActiveConnectionName(): ?string
    {
        return $this->currentActiveConnectionName ?? $this->db->getDefaultConnection();
    }

    /**
     * Forces the application to use the primary database connection.
     * This method also resets the status of both primary and failover connections
     * to HEALTHY in the ConnectionStateManager, assuming the primary is being forced
     * because it's believed to be operational or desired as the main source.
     * Dispatches appropriate connection switch events.
     */
    public function forceSwitchToPrimary(): void
    {
        $previousConnectionNameForEvent = $this->currentActiveConnectionName;
        Log::info("Forcing switch to primary connection: {$this->primaryConnectionName}. Previous active: {$previousConnectionNameForEvent}");

        // By forcing a switch to primary, we implicitly assume it (and potentially failover) should be considered healthy.
        // This helps in scenarios where an admin intervenes or a known fix has been applied.
        $this->stateManager->setConnectionStatus($this->primaryConnectionName, ConnectionStatus::HEALTHY, 0);
        $this->stateManager->setConnectionStatus($this->failoverConnectionName, ConnectionStatus::HEALTHY, 0); // Also reset failover.

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
            Log::info("Already on primary connection '{$this->primaryConnectionName}'. No forced switch performed.");
        }
    }

    /**
     * Forces the application to use the failover database connection.
     * Unlike `forceSwitchToPrimary`, this method does not automatically reset the health status
     * of the failover connection in ConnectionStateManager; it assumes the failover is chosen
     * explicitly, perhaps while primary is still being investigated.
     * Dispatches appropriate connection switch events.
     */
    public function forceSwitchToFailover(): void
    {
        $previousConnectionNameForEvent = $this->currentActiveConnectionName;
        Log::info("Forcing switch to failover connection: {$this->failoverConnectionName}. Previous active: {$previousConnectionNameForEvent}");

        // Note: Unlike forceSwitchToPrimary, we don't automatically mark failover as HEALTHY here.
        // The health check mechanism should determine its status. Forcing is about selection priority.
        // If failover is not actually healthy, the next determineAndSetConnection cycle might switch away
        // unless its health state is also manually managed or recovers.

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
            Log::info("Already on failover connection '{$this->failoverConnectionName}'. No forced switch performed.");
        }
    }
}
