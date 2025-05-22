<?php

namespace Nuxgame\LaravelDynamicDBFailover\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Nuxgame\LaravelDynamicDBFailover\HealthCheck\ConnectionStateManager;
use Illuminate\Support\Facades\Log;

class CheckDatabaseHealthCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'failover:health-check
                             {connection? : The specific connection to check (optional). All if not specified.}';

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
        Log::channel('db_health_checks')->info('Starting database health checks...');

        $specificConnection = $this->argument('connection');
        $connectionsToWatch = [];

        if ($specificConnection) {
            // Validate if the specific connection exists in the database configurations.
            if (!$this->config->has("database.connections.{$specificConnection}")) {
                Log::channel('db_health_checks')->error("Connection '{$specificConnection}' is not configured in your database settings.");
                return Command::FAILURE;
            }
            $connectionsToWatch[] = $specificConnection;
            Log::channel('db_health_checks')->info("Performing health check for specific connection: {$specificConnection}");
        } else {
            // If no specific connection is given, check primary and failover connections from config.
            $primaryConnection = $this->config->get('dynamic_db_failover.connections.primary');
            $failoverConnection = $this->config->get('dynamic_db_failover.connections.failover');

            if ($primaryConnection) {
                $connectionsToWatch[] = $primaryConnection;
            }
            if ($failoverConnection) {
                $connectionsToWatch[] = $failoverConnection;
            }

            Log::channel('db_health_checks')->info('Performing health checks for configured primary and failover connections.');
        }

        if (empty($connectionsToWatch)) {
            Log::channel('db_health_checks')->warning('No connections configured or specified for health check.');
            return Command::SUCCESS;
        }

        foreach ($connectionsToWatch as $connectionName) {
            // Skip if a connection name from config happens to be empty.
            if (empty($connectionName)) {
                continue;
            }

            Log::channel('db_health_checks')->info("Checking health of connection: {$connectionName}...");
            try {
                $this->stateManager->updateConnectionStatus($connectionName);
                // The ConnectionStateManager itself might log detailed reasons for status changes (e.g., on health check failure).
                // Here, we retrieve and display the resulting status and failure count.
                $status = $this->stateManager->getConnectionStatus($connectionName);
                $failures = $this->stateManager->getFailureCount($connectionName);
                Log::channel('db_health_checks')->info("Connection '{$connectionName}' status: {$status->value}, Failures: {$failures}");
            } catch (\Exception $e) {
                Log::channel('db_health_checks')->error("Failed to check health for connection '{$connectionName}': " . $e->getMessage(), ['exception' => $e]);
            }
        }

        Log::channel('db_health_checks')->info('Database health checks completed.');
        return Command::SUCCESS;
    }
}
