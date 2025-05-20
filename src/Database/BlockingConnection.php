<?php

namespace Nuxgame\LaravelDynamicDBFailover\Database;

use Illuminate\Database\Connection;
use Nuxgame\LaravelDynamicDBFailover\Exceptions\AllDatabaseConnectionsUnavailableException;
use Closure;
use PDO; // Although PDO is not used directly, it might be expected by some analysis tools.

/**
 * Class BlockingConnection
 *
 * Represents a database connection that is intentionally non-functional.
 * When the application switches to this connection (typically because both primary
 * and failover databases are unavailable), any attempt to perform a database query
 * will result in an AllDatabaseConnectionsUnavailableException.
 * This class extends Illuminate\Database\Connection and overrides query-related methods
 * to enforce this blocking behavior.
 */
class BlockingConnection extends Connection
{
    /**
     * Create a new database connection instance.
     *
     * @param mixed $pdo Typically a PDO instance or Closure. For BlockingConnection, this is a dummy stdClass
     *                   as no actual PDO operations are performed.
     * @param string $database The name of the database.
     * @param string $tablePrefix The table prefix for queries.
     * @param array $config The connection configuration array.
     */
    public function __construct($pdo, string $database = '', string $tablePrefix = '', array $config = [])
    {
        // We pass a dummy stdClass because parent::__construct expects a PDO|Closure.
        // All our overridden query methods will throw an exception before any PDO interaction is attempted.
        parent::__construct(new \stdClass(), $database, $tablePrefix, $config);
    }

    /**
     * Throws an AllDatabaseConnectionsUnavailableException to indicate that no database connections are available.
     *
     * @throws \Nuxgame\LaravelDynamicDBFailover\Exceptions\AllDatabaseConnectionsUnavailableException
     */
    protected function throwBlockedConnectionException(): void
    {
        throw new AllDatabaseConnectionsUnavailableException();
    }

    /**
     * Attempts to run a select statement against the connection.
     * This will always throw AllDatabaseConnectionsUnavailableException.
     *
     * @param string $query
     * @param array $bindings
     * @param bool $useReadPdo
     * @return array Never returns; throws exception.
     * @throws \Nuxgame\LaravelDynamicDBFailover\Exceptions\AllDatabaseConnectionsUnavailableException
     */
    public function select($query, $bindings = [], $useReadPdo = true): array
    {
        $this->throwBlockedConnectionException();
        return []; // Satisfy return type, but this line is unreachable.
    }

    /**
     * Attempts to run a select statement against the connection and returns a generator.
     * This will always throw AllDatabaseConnectionsUnavailableException.
     *
     * @param string $query
     * @param array $bindings
     * @param bool $useReadPdo
     * @return \Generator Never returns; throws exception.
     * @throws \Nuxgame\LaravelDynamicDBFailover\Exceptions\AllDatabaseConnectionsUnavailableException
     */
    public function cursor($query, $bindings = [], $useReadPdo = true): \Generator
    {
        $this->throwBlockedConnectionException();
        return (function (): \Generator { yield; })(); // Satisfy return type, unreachable.
    }

    /**
     * Attempts to run an insert statement against the connection.
     * This will always throw AllDatabaseConnectionsUnavailableException.
     *
     * @param string $query
     * @param array $bindings
     * @return bool Never returns; throws exception.
     * @throws \Nuxgame\LaravelDynamicDBFailover\Exceptions\AllDatabaseConnectionsUnavailableException
     */
    public function insert($query, $bindings = []): bool
    {
        $this->throwBlockedConnectionException();
        return false; // Satisfy return type, unreachable.
    }

    /**
     * Attempts to run an update statement against the connection.
     * This will always throw AllDatabaseConnectionsUnavailableException.
     *
     * @param string $query
     * @param array $bindings
     * @return int Never returns; throws exception.
     * @throws \Nuxgame\LaravelDynamicDBFailover\Exceptions\AllDatabaseConnectionsUnavailableException
     */
    public function update($query, $bindings = []): int
    {
        $this->throwBlockedConnectionException();
        return 0; // Satisfy return type, unreachable.
    }

    /**
     * Attempts to run a delete statement against the connection.
     * This will always throw AllDatabaseConnectionsUnavailableException.
     *
     * @param string $query
     * @param array $bindings
     * @return int Never returns; throws exception.
     * @throws \Nuxgame\LaravelDynamicDBFailover\Exceptions\AllDatabaseConnectionsUnavailableException
     */
    public function delete($query, $bindings = []): int
    {
        $this->throwBlockedConnectionException();
        return 0; // Satisfy return type, unreachable.
    }

    /**
     * Attempts to execute a statement against the connection.
     * This will always throw AllDatabaseConnectionsUnavailableException.
     *
     * @param string $query
     * @param array $bindings
     * @return bool Never returns; throws exception.
     * @throws \Nuxgame\LaravelDynamicDBFailover\Exceptions\AllDatabaseConnectionsUnavailableException
     */
    public function statement($query, $bindings = []): bool
    {
        $this->throwBlockedConnectionException();
        return false; // Satisfy return type, unreachable.
    }

    /**
     * Attempts to run an affecting statement against the connection.
     * This will always throw AllDatabaseConnectionsUnavailableException.
     *
     * @param string $query
     * @param array $bindings
     * @return int Never returns; throws exception.
     * @throws \Nuxgame\LaravelDynamicDBFailover\Exceptions\AllDatabaseConnectionsUnavailableException
     */
    public function affectingStatement($query, $bindings = []): int
    {
        $this->throwBlockedConnectionException();
        return 0; // Satisfy return type, unreachable.
    }

    /**
     * Attempts to execute a Closure within a transaction.
     * This will always throw AllDatabaseConnectionsUnavailableException.
     *
     * @param \Closure $callback
     * @param int $attempts
     * @return mixed Never returns; throws exception.
     * @throws \Nuxgame\LaravelDynamicDBFailover\Exceptions\AllDatabaseConnectionsUnavailableException
     */
    public function transaction(Closure $callback, $attempts = 1): mixed
    {
        $this->throwBlockedConnectionException();
        return null; // Satisfy return type for mixed, unreachable.
    }

    /**
     * Attempts to start a new database transaction.
     * This will always throw AllDatabaseConnectionsUnavailableException.
     *
     * @return void Never returns; throws exception.
     * @throws \Nuxgame\LaravelDynamicDBFailover\Exceptions\AllDatabaseConnectionsUnavailableException
     */
    public function beginTransaction(): void
    {
        $this->throwBlockedConnectionException();
    }

    /**
     * Attempts to commit the active database transaction.
     * This will always throw AllDatabaseConnectionsUnavailableException.
     *
     * @return void Never returns; throws exception.
     * @throws \Nuxgame\LaravelDynamicDBFailover\Exceptions\AllDatabaseConnectionsUnavailableException
     */
    public function commit(): void
    {
        $this->throwBlockedConnectionException();
    }

    /**
     * Attempts to roll back the active database transaction.
     * This will always throw AllDatabaseConnectionsUnavailableException.
     *
     * @param int|null $toLevel
     * @return void Never returns; throws exception.
     * @throws \Nuxgame\LaravelDynamicDBFailover\Exceptions\AllDatabaseConnectionsUnavailableException
     */
    public function rollBack($toLevel = null): void
    {
        $this->throwBlockedConnectionException();
    }

    /**
     * Get the driver name for the connection.
     *
     * @return string The driver name, always 'blocking'.
     */
    public function getDriverName(): string
    {
        return 'blocking';
    }

    /**
     * Get the current PDO connection (dummy in this case).
     *
     * @return \PDO|\stdClass The dummy stdClass instance used as a PDO placeholder.
     */
    public function getPdo()
    {
        // This method might be called by some internal Laravel processes or debug tools.
        // We return the dummy stdClass instance passed in the constructor as this connection does not use a real PDO.
        if ($this->pdo instanceof Closure) {
            // This case should ideally not be hit with our setup, as we pass stdClass directly.
            return $this->pdo = call_user_func($this->pdo);
        }
        return $this->pdo; // Returns the stdClass instance
    }

    /**
     * Get the current PDO connection used for reading (dummy in this case).
     *
     * @return \PDO|\stdClass The dummy stdClass instance used as a PDO placeholder.
     */
    public function getReadPdo()
    {
        // For BlockingConnection, the read and write PDO are the same dummy instance.
        return $this->getPdo();
    }

    /**
     * Attempts to run an unprepared SQL query.
     * This will always throw AllDatabaseConnectionsUnavailableException.
     *
     * @param string $query
     * @return bool Never returns; throws exception.
     * @throws \Nuxgame\LaravelDynamicDBFailover\Exceptions\AllDatabaseConnectionsUnavailableException
     */
    public function unprepared($query): bool
    {
        $this->throwBlockedConnectionException();
        return false; // Satisfy return type, unreachable.
    }

    // Note on other Connection methods:
    // Many other public methods exist in the parent Illuminate\Database\Connection class.
    // The most critical query execution methods (select, insert, update, delete, statement, transaction)
    // have been overridden above to throw exceptions.
    // If other database operations (e.g., schema builders, DDL statements not covered by `statement()`,
    // or more obscure methods) are attempted while this connection is active, they might either:
    //   a) Fail due to the dummy stdClass not behaving like a real PDO object.
    //   b) Attempt an operation if they don't directly rely on the PDO object in an expected way (less likely).
    // Thorough testing of an application under limited functionality mode would reveal if further
    // method overrides are necessary to ensure all database interactions are gracefully blocked.
}
