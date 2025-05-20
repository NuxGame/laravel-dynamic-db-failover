<?php

namespace Nuxgame\LaravelDynamicDBFailover\HealthCheck;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use PDOException;
use Exception;

/**
 * Class ConnectionHealthChecker
 *
 * Responsible for performing health checks on specified database connections.
 * It executes a configurable query to determine if a connection is responsive.
 */
class ConnectionHealthChecker
{
    /** @var DatabaseManager The Laravel database manager instance. */
    protected DatabaseManager $dbManager;

    /** @var ConfigRepository The configuration repository instance. */
    protected ConfigRepository $config;

    /**
     * ConnectionHealthChecker constructor.
     *
     * @param DatabaseManager $dbManager Laravel's database manager for resolving connections.
     * @param ConfigRepository $config Repository for accessing package and application configurations.
     */
    public function __construct(DatabaseManager $dbManager, ConfigRepository $config)
    {
        $this->dbManager = $dbManager;
        $this->config = $config;
    }

    /**
     * Checks the health of a given database connection by executing a configured query.
     *
     * @param string $connectionName The name of the database connection to check.
     * @return bool True if the connection is healthy and the query succeeds, false otherwise.
     */
    public function isHealthy(string $connectionName): bool
    {
        $healthCheckQuery = $this->config->get('dynamic_db_failover.health_check.query', 'SELECT 1');
        // The 'timeout_seconds' from config is harder to enforce per query without complex logic.
        // We rely on the connection's own configured timeout for now for the connection attempt itself,
        // and the query itself should be very lightweight.

        try {
            // Attempt to get the connection. This can fail if config is bad or driver is missing.
            $connection = $this->dbManager->connection($connectionName);

            // Ping the database by executing a simple query.
            // Using unprepared() to avoid issues with query binding if not needed for a simple query like "SELECT 1".
            $connection->unprepared($healthCheckQuery);

            Log::debug("Health check for connection '{$connectionName}' passed.");
            return true;
        } catch (PDOException $e) {
            // Specific PDO exceptions (e.g., connection refused, authentication failure, query error during health check)
            Log::warning("Health check for connection '{$connectionName}' failed due to PDOException: " . $e->getMessage(), [
                'connection' => $connectionName,
                'exception_code' => $e->getCode(),
            ]);
            return false;
        } catch (Exception $e) {
            // Other general exceptions during connection attempt or query (e.g., connection not configured)
            Log::warning("Health check for connection '{$connectionName}' failed due to generic Exception: " . $e->getMessage(), [
                'connection' => $connectionName,
                'exception_code' => $e->getCode(),
                'trace' => $e->getTraceAsString(), // Optional: for deeper debugging if needed
            ]);
            return false;
        }
    }
}
