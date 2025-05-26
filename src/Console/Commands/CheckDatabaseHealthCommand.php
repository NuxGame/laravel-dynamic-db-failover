<?php

namespace Nuxgame\LaravelDynamicDBFailover\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Nuxgame\LaravelDynamicDBFailover\HealthCheck\ConnectionStateManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Event;
use Nuxgame\LaravelDynamicDBFailover\Events\DBHealthCheckCommandStarted;
use Nuxgame\LaravelDynamicDBFailover\Events\DBHealthCheckCommandFinished;

class CheckDatabaseHealthCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'failover:health-check
                             {connection? : The specific connection to check (optional). All if not specified.}
                             {--dispatch-events= : Override config: Dispatch lifecycle events (true/false/1/0).}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Periodically checks the health of monitored database connections and updates their status in cache.';

    /** @var ConnectionStateManager Service to manage the state of database connections. */
    protected ConnectionStateManager $stateManager;

    /** @var ConfigRepository Contract for configuration repository. */
    protected ConfigRepository $config;

    /**
     * Create a new command instance.
     *
     * @param ConnectionStateManager $stateManager The service for managing connection states.
     * @param ConfigRepository $config The configuration repository.
     * @return void
     */
    public function __construct(ConnectionStateManager $stateManager, ConfigRepository $config)
    {
        parent::__construct();
        $this->stateManager = $stateManager;
        $this->config = $config;
    }

    /**
     * Determine if lifecycle events should be dispatched.
     *
     * @return bool
     */
    protected function shouldDispatchLifecycleEvents(): bool
    {
        $option = $this->option('dispatch-events');

        if ($option !== null) {
            // If option is provided, use its boolean value
            return filter_var($option, FILTER_VALIDATE_BOOLEAN);
        }

        // Otherwise, use the config setting
        return (bool) $this->config->get('dynamic_db_failover.dispatch_command_lifecycle_events', true);
    }

    /**
     * Execute the console command.
     *
     * This command checks the health of specified or configured database connections.
     * If a specific connection name is provided as an argument, only that connection is checked.
     * Otherwise, it checks the primary and failover connections defined in the package configuration.
     * The status of each connection (HEALTHY, DOWN, UNKNOWN) and its failure count are updated
     * via the ConnectionStateManager and logged to the console output.
     * Errors during health checks are caught and logged.
     *
     * @return int Returns Command::SUCCESS or Command::FAILURE.
     */
    public function handle()
    {
        $dispatchEvents = $this->shouldDispatchLifecycleEvents();

        $specificConnection = $this->argument('connection');
        $connectionsForStartEvent = [];

        if ($specificConnection) {
            $connectionsForStartEvent[] = $specificConnection;
        } else {
            $primary = $this->config->get('dynamic_db_failover.connections.primary');
            $failover = $this->config->get('dynamic_db_failover.connections.failover');
            if ($primary) $connectionsForStartEvent[] = $primary;
            if ($failover) $connectionsForStartEvent[] = $failover;
        }
        if ($dispatchEvents) {
            Event::dispatch(new DBHealthCheckCommandStarted($connectionsForStartEvent));
        }

        $message = 'Starting database health checks...';
        Log::channel('db_health_checks')->info($message);
        $this->info($message);

        $connectionsToWatch = [];
        $processedConnections = [];

        if ($specificConnection) {
            if (!$this->config->has("database.connections.{$specificConnection}")) {
                $errorMessage = "Connection '{$specificConnection}' is not configured in your database settings.";
                Log::channel('db_health_checks')->error($errorMessage);
                $this->error($errorMessage);
                if ($dispatchEvents) {
                    Event::dispatch(new DBHealthCheckCommandFinished($processedConnections, Command::FAILURE));
                }
                return Command::FAILURE;
            }
            $connectionsToWatch[] = $specificConnection;
            $message = "Performing health check for specific connection: {$specificConnection}";
            Log::channel('db_health_checks')->info($message);
            $this->info($message);
        } else {
            $primaryConnection = $this->config->get('dynamic_db_failover.connections.primary');
            $failoverConnection = $this->config->get('dynamic_db_failover.connections.failover');

            if ($primaryConnection) {
                $connectionsToWatch[] = $primaryConnection;
            }
            if ($failoverConnection) {
                $connectionsToWatch[] = $failoverConnection;
            }
            $message = 'Performing health checks for configured primary and failover connections.';
            Log::channel('db_health_checks')->info($message);
            $this->info($message);
        }

        if (empty($connectionsToWatch)) {
            $warningMessage = 'No connections configured or specified for health check.';
            Log::channel('db_health_checks')->warning($warningMessage);
            $this->warn($warningMessage);
            if ($dispatchEvents) {
                Event::dispatch(new DBHealthCheckCommandFinished($processedConnections, Command::SUCCESS));
            }
            return Command::SUCCESS;
        }

        foreach ($connectionsToWatch as $connectionName) {
            if (empty($connectionName)) {
                continue;
            }
            $processedConnections[] = $connectionName;

            $message = "Checking health of connection: {$connectionName}...";
            Log::channel('db_health_checks')->info($message);
            $this->info($message);
            try {
                $this->stateManager->updateConnectionStatus($connectionName);
                $status = $this->stateManager->getConnectionStatus($connectionName);
                $failures = $this->stateManager->getFailureCount($connectionName);
                $message = "Connection '{$connectionName}' status: {$status->value}, Failures: {$failures}";
                Log::channel('db_health_checks')->info($message);
                $this->info($message);
            } catch (\Exception $e) {
                $errorMessage = "Failed to check health for connection '{$connectionName}': " . $e->getMessage();
                Log::channel('db_health_checks')->error($errorMessage, ['exception' => $e]);
                $this->error($errorMessage);
            }
        }

        $message = 'Database health checks completed.';
        Log::channel('db_health_checks')->info($message);
        $this->info($message);
        if ($dispatchEvents) {
            Event::dispatch(new DBHealthCheckCommandFinished($processedConnections, Command::SUCCESS));
        }
        return Command::SUCCESS;
    }
}
