<?php

namespace Nuxgame\LaravelDynamicDBFailover\HealthCheck;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use PDOException;
use Exception;

class ConnectionHealthChecker
{
    protected DatabaseManager $dbManager;
    protected ConfigRepository $config;

    public function __construct(DatabaseManager $dbManager, ConfigRepository $config)
    {
        $this->dbManager = $dbManager;
        $this->config = $config;
    }

    /**
     * Checks the health of a given database connection.
     *
     * @param string $connectionName
     * @return bool True if healthy, false otherwise.
     */
    public function isHealthy(string $connectionName): bool
    {
        $healthCheckQuery = $this->config->get('dynamic_db_failover.health_check.query', 'SELECT 1');
        // The 'timeout_seconds' from config is harder to enforce per query without complex logic.
        // We rely on the connection's own configured timeout for now for the connection attempt itself.
        // And the query itself should be very lightweight.

        try {
            // Attempt to get the connection. This can fail if config is bad or driver is missing.
            $connection = $this->dbManager->connection($connectionName);

            // Ping the database by executing a simple query.
            // Using unprepared to avoid issues with query binding if not needed for "SELECT 1"
            $connection->unprepared($healthCheckQuery);

            Log::debug("Health check for connection '{$connectionName}' passed.");
            return true;
        } catch (PDOException $e) {
            // Specific PDO exceptions (e.g., connection refused, auth failure, query error during health check)
            Log::warning("Health check for connection '{$connectionName}' failed due to PDOException: " . $e->getMessage());
            return false;
        } catch (Exception $e) {
            // Other general exceptions during connection attempt or query
            Log::warning("Health check for connection '{$connectionName}' failed due to generic Exception: " . $e->getMessage());
            return false;
        }
    }
}
