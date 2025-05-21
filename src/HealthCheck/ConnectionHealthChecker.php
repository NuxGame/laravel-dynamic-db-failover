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
        $timeoutSeconds = (int) $this->config->get('dynamic_db_failover.health_check.timeout_seconds', 2);

        $connection = null;
        $pdo = null;
        $originalTimeout = null;

        try {
            $connection = $this->dbManager->connection($connectionName);
            $pdo = $connection->getPdo();

            // Save the current PDO timeout, if it is set and supported
            if ($pdo instanceof \PDO && method_exists($pdo, 'getAttribute')) {
                try {
                    // Some drivers may not support ATTR_TIMEOUT or it might not be set
                    $originalTimeout = $pdo->getAttribute(\PDO::ATTR_TIMEOUT);
                } catch (\PDOException $e) {
                    Log::debug("ConnectionHealthChecker: Could not get PDO::ATTR_TIMEOUT for connection '{$connectionName}'. Message: {$e->getMessage()}");
                    // Not critical if getting fails, we just won't be able to restore a specific value
                }
            }

            // Set the new timeout for the health check query
            if ($pdo instanceof \PDO && method_exists($pdo, 'setAttribute')) {
                try {
                    $pdo->setAttribute(\PDO::ATTR_TIMEOUT, $timeoutSeconds);
                } catch (\PDOException $e) {
                    Log::warning("ConnectionHealthChecker: Could not set PDO::ATTR_TIMEOUT to {$timeoutSeconds}s for connection '{$connectionName}'. Message: {$e->getMessage()}");
                    // If setting fails, the query will execute with the default/previous connection timeout
                }
            } else {
                Log::debug("ConnectionHealthChecker: PDO::setAttribute method not available or PDO object not instance of \\PDO for connection '{$connectionName}', cannot set query timeout.");
            }

            $connection->unprepared($healthCheckQuery);

            Log::debug("Health check for connection '{$connectionName}' passed within timeout ({$timeoutSeconds}s).");
            $this->restorePdoTimeout($pdo, $originalTimeout, $connectionName); // Restore the timeout
            return true;
        } catch (PDOException $e) {
            $this->restorePdoTimeout($pdo, $originalTimeout, $connectionName); // Restore the timeout on error
            // Check if the exception is related to a timeout (this can be driver-specific)
            // For MySQL, code 'HY000' and a message containing 'max_statement_time exceeded' or 'Query execution was interrupted'
            $isTimeoutError = false;
            $errorCode = (string) $e->getCode();
            $errorMessage = $e->getMessage();

            if ($errorCode === 'HY000' &&
                (str_contains($errorMessage, 'max_statement_time') ||
                 str_contains($errorMessage, 'Query execution was interrupted') ||
                 str_contains($errorMessage, 'Lock wait timeout exceeded'))
            ) { // MySQL specific timeout errors
                $isTimeoutError = true;
            }

            if ($isTimeoutError) {
                Log::warning("Health check for connection '{$connectionName}' failed due to query timeout ({$timeoutSeconds}s): " . $e->getMessage(), [
                    'connection' => $connectionName,
                    'exception_code' => $errorCode,
                ]);
            } else {
                Log::warning("Health check for connection '{$connectionName}' failed due to PDOException: " . $e->getMessage(), [
                    'connection' => $connectionName,
                    'exception_code' => $errorCode,
                ]);
            }
            return false;
        } catch (Exception $e) {
            $this->restorePdoTimeout($pdo, $originalTimeout, $connectionName); // Restore the timeout on error
            Log::warning("Health check for connection '{$connectionName}' failed due to generic Exception: " . $e->getMessage(), [
                'connection' => $connectionName,
                'exception_code' => $e->getCode(),
                // 'trace' => $e->getTraceAsString(), // Uncomment for deeper debugging
            ]);
            return false;
        }
    }

    /**
     * Restores the original PDO timeout attribute if it was changed.
     *
     * @param \PDO|null $pdo The PDO instance.
     * @param mixed $originalTimeout The original timeout value to restore.
     * @param string $connectionName The name of the connection (for logging).
     * @return void
     */
    private function restorePdoTimeout($pdo, $originalTimeout, string $connectionName): void
    {
        if ($pdo instanceof \PDO && $originalTimeout !== null && method_exists($pdo, 'setAttribute')) {
            try {
                // Restore only if originalTimeout was successfully retrieved and is numeric (or a valid value).
                // PDO::ATTR_TIMEOUT expects an int. If originalTimeout was null due to a getAttribute error, don't try to set it.
                if (is_numeric($originalTimeout)) { // Simple check, can be improved for different drivers
                    $pdo->setAttribute(\PDO::ATTR_TIMEOUT, (int)$originalTimeout);
                }
            } catch (\PDOException $e) {
                Log::warning("ConnectionHealthChecker: Could not restore PDO::ATTR_TIMEOUT for connection '{$connectionName}'. Message: {$e->getMessage()}");
            }
        }
    }
}
