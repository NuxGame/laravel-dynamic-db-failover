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

    protected ConnectionStateManager $stateManager;
    protected ConfigRepository $config;

    /**
     * Create a new command instance.
     *
     * @param ConnectionStateManager $stateManager
     * @param ConfigRepository $config
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
     * @return int
     */
    public function handle()
    {
        $this->info('Starting database health checks...');

        $specificConnection = $this->argument('connection');
        $connectionsToWatch = [];

        if ($specificConnection) {
            $connectionsToWatch[] = $specificConnection;
            $this->info("Performing health check for specific connection: {$specificConnection}");
        } else {
            $primaryConnection = $this->config->get('dynamic_db_failover.connections.primary');
            $failoverConnection = $this->config->get('dynamic_db_failover.connections.failover');

            if ($primaryConnection) $connectionsToWatch[] = $primaryConnection;
            if ($failoverConnection) $connectionsToWatch[] = $failoverConnection;

            $this->info('Performing health checks for configured primary and failover connections.');
        }

        if (empty($connectionsToWatch)) {
            $this->warn('No connections configured or specified for health check.');
            return Command::SUCCESS;
        }

        foreach ($connectionsToWatch as $connectionName) {
            if (empty($connectionName)) continue;

            $this->line("Checking health of connection: {$connectionName}...");
            try {
                $this->stateManager->updateConnectionStatus($connectionName);
                // Status (HEALTHY/DOWN) is logged by ConnectionStateManager
                $status = $this->stateManager->getConnectionStatus($connectionName);
                $failures = $this->stateManager->getFailureCount($connectionName);
                $this->info("Connection '{$connectionName}' status: {$status}, Failures: {$failures}");
            } catch (\Exception $e) {
                $this->error("Failed to check health for connection '{$connectionName}': " . $e->getMessage());
                Log::error("Health check command failed for connection '{$connectionName}': ", ['exception' => $e]);
            }
        }

        $this->info('Database health checks completed.');
        return Command::SUCCESS;
    }
}
