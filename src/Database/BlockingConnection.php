<?php

namespace Nuxgame\LaravelDynamicDBFailover\Database;

use Illuminate\Database\Connection;
use Nuxgame\LaravelDynamicDBFailover\Exceptions\AllDatabaseConnectionsUnavailableException;
use Closure;
use PDO; // Хотя PDO не используется напрямую, он может быть ожидаем некоторыми инструментами анализа

class BlockingConnection extends Connection
{
    /**
     * Create a new database connection instance.
     *
     * @param mixed $pdo Typically a PDO instance or Closure, but for us, it will be a dummy.
     * @param string $database
     * @param string $tablePrefix
     * @param array $config
     */
    public function __construct($pdo, string $database = '', string $tablePrefix = '', array $config = [])
    {
        // We pass a dummy stdClass because parent::__construct expects a PDO|Closure.
        // Our methods will throw before any PDO interaction.
        parent::__construct(new \stdClass(), $database, $tablePrefix, $config);
    }

    /**
     * Throw the AllDatabaseConnectionsUnavailableException.
     *
     * @throws \Nuxgame\LaravelDynamicDBFailover\Exceptions\AllDatabaseConnectionsUnavailableException
     */
    protected function throwBlockedConnectionException(): void
    {
        throw new AllDatabaseConnectionsUnavailableException();
    }

    public function select($query, $bindings = [], $useReadPdo = true): array
    {
        $this->throwBlockedConnectionException();
        return []; // Satisfy return type
    }

    public function cursor($query, $bindings = [], $useReadPdo = true): \Generator
    {
        $this->throwBlockedConnectionException();
        // @phpstan-ignore-next-line
        if (false) { yield; } // Satisfy return type, though exception is always thrown first.
    }

    public function insert($query, $bindings = []): bool
    {
        $this->throwBlockedConnectionException();
        return false; // Satisfy return type
    }

    public function update($query, $bindings = []): int
    {
        $this->throwBlockedConnectionException();
        return 0; // Satisfy return type
    }

    public function delete($query, $bindings = []): int
    {
        $this->throwBlockedConnectionException();
        return 0; // Satisfy return type
    }

    public function statement($query, $bindings = []): bool
    {
        $this->throwBlockedConnectionException();
        return false; // Satisfy return type
    }

    public function affectingStatement($query, $bindings = []): int
    {
        $this->throwBlockedConnectionException();
        return 0; // Satisfy return type
    }

    public function transaction(Closure $callback, $attempts = 1): mixed
    {
        $this->throwBlockedConnectionException();
        return null; // Satisfy return type for mixed
    }

    public function beginTransaction(): void
    {
        $this->throwBlockedConnectionException();
    }

    public function commit(): void
    {
        $this->throwBlockedConnectionException();
    }

    public function rollBack($toLevel = null): void
    {
        $this->throwBlockedConnectionException();
    }

    public function getDriverName(): string
    {
        return 'blocking';
    }

    /**
     * Get the current PDO connection.
     *
     * @return \PDO|\stdClass
     */
    public function getPdo()
    {
        // This method might be called by some internal Laravel processes or debug tools.
        // We return the dummy stdClass instance passed in the constructor.
        if ($this->pdo instanceof Closure) {
            // This case should ideally not be hit with our setup.
            return $this->pdo = call_user_func($this->pdo);
        }
        return $this->pdo; // Returns the stdClass instance
    }

    /**
     * Get the current PDO connection used for reading.
     *
     * @return \PDO|\stdClass
     */
    public function getReadPdo()
    {
        return $this->getPdo();
    }

    // Many other methods could be overridden from Illuminate\Database\Connection.
    // However, the primary query execution methods (select, insert, update, delete, statement, transaction)
    // are the most critical. If other database operations are attempted and not caught
    // by these overrides, they might either fail due to the dummy PDO or
    // (less likely) attempt an operation. Thorough testing will reveal if more overrides are needed.
}