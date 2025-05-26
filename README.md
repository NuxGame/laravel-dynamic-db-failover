# Laravel Dynamic Database Failover

[![Latest Stable Version](https://img.shields.io/packagist/v/nuxgame/laravel-dynamic-db-failover.svg?style=flat-square)](https://packagist.org/packages/nuxgame/laravel-dynamic-db-failover) <!-- Replace with actual link if/when published -->
[![License](https://img.shields.io/packagist/l/nuxgame/laravel-dynamic-db-failover.svg?style=flat-square)](https://packagist.org/packages/nuxgame/laravel-dynamic-db-failover) <!-- Replace with actual link if/when published -->

This package provides dynamic database connection failover capabilities for Laravel applications. It automatically monitors the health of your primary database connection and can switch to a pre-configured failover connection if the primary becomes unavailable. It also supports a "blocking" connection mode if all monitored connections are down and can restore the primary connection once it's healthy again.

## Features

*   Automatic failover from primary to a secondary (failover) database connection.
*   Switches to a special "blocking" connection if all primary/failover databases are unavailable, preventing application errors from unhandled connection issues.
*   Periodic health checks for database connections using configurable queries.
*   Event-driven: Dispatches events for connection switches, limited functionality mode, and primary connection restoration.
*   Configurable connection names, health check parameters (failure threshold, query, cache TTL), and cache store.
*   Artisan command `failover:health-check` for manual or scheduled health checks.
*   Easy integration with existing Laravel applications.

## Requirements

*   PHP: `^8.2`
*   Laravel Framework: `^10.0` or `^11.0`

## Installation

You can install the package via Composer:

```bash
composer require nuxgame/laravel-dynamic-db-failover
```

The service provider will be automatically registered.

## Configuration

1.  **Publish the configuration file:**

    ```bash
    php artisan vendor:publish --provider="Nuxgame\LaravelDynamicDBFailover\DynamicDBFailoverServiceProvider" --tag="config"
    ```
    This will create a `config/dynamic_db_failover.php` file in your application.

2.  **Configure your database connections:**
    Ensure you have your primary, failover, and a 'blocking' database connection configured in your `config/database.php` file. The 'blocking' connection uses a special driver provided by this package.

    Example for `blocking` connection in `config/database.php`:
    ```php
    'your_blocking_connection_name' => [
        'driver' => 'blocking',
        // CRITICAL: No other parameters (host, port, database, username, password, etc.)
        // should be defined here for the 'blocking' driver.
        // The driver is designed to not establish a real database connection.
    ],
    ```

    **Important Note on `.env` Configuration for the Blocking Connection:**
    To prevent potential 502 errors or other issues when primary and failover databases are down, it is crucial **NOT** to define `DB_BLOCKING_HOST`, `DB_BLOCKING_PORT`, `DB_BLOCKING_DATABASE`, `DB_BLOCKING_USERNAME`, or `DB_BLOCKING_PASSWORD` variables in your `.env` file. The `DB_BLOCKING_CONNECTION` variable (which defines the name of your blocking connection, e.g., `mysql_blocking`) is sufficient. The 'blocking' driver does not use these host/port/etc. details, and their presence might lead Laravel to attempt a connection before the failover logic fully engages, causing errors.

3.  **Update `config/dynamic_db_failover.php`:**

    *   `enabled`: Set to `true` to enable the failover mechanism.
    *   `connections`: Specify the names of your `primary`, `failover`, and `blocking` connections as defined in `config/database.php`.
    *   `health_check`:
        *   `query`: The SQL query to execute for health checks.
        *   `failure_threshold`: Number of consecutive failures before a connection is marked as down.
        *   `timeout_seconds`: Timeout in seconds specifically for the health check query execution. The package attempts to set this timeout directly on the PDO connection for the duration of the health check. Default is 2 seconds.
        *   `schedule_frequency`: Defines how often the `failover:health-check` command is scheduled by this package. 
            Possible values: `'everyMinute'`, `'everySecond'`, `'disabled'`. Default is `'everySecond'`. 
            If `'disabled'`, the package will not schedule the command, and you would need to schedule it manually if desired.
    *   `cache`:
        *   `store`: The cache store to use for storing connection statuses (e.g., `redis`, `memcached`, `file`). Successfully tested with `redis` and `memcached`.
        *   `prefix`: Prefix for cache keys.
        *   `ttl_seconds`: Time-to-live for connection status in cache.

## Usage

Once configured and enabled, the package works automatically. The `DynamicDBFailoverServiceProvider` hooks into Laravel's database manager. On each request (if not cached), or when `DatabaseFailoverManager::determineAndSetConnection()` is called, it checks the health of connections (based on cached status or fresh checks) and sets the default database connection accordingly.

### Artisan Command

The package includes an Artisan command `failover:health-check` to perform health assessments on your database connections.

```bash
php artisan failover:health-check
```
To check a specific connection:
```bash
php artisan failover:health-check your_connection_name
```

**Automated Scheduling:**

By default, this package will automatically schedule the `failover:health-check` command to run based on the `health_check.schedule_frequency` setting in the `config/dynamic_db_failover.php` file. 
The default frequency is `'everySecond'`. You can change this to `'everyMinute'` or set it to `'disabled'` if you prefer to manage the scheduling manually through your application's `App/Console/Kernel.php` file.

### Events

The package dispatches the following events, allowing you to hook into its lifecycle:

*   **`Nuxgame\\LaravelDynamicDBFailover\\Events\\SwitchedToPrimaryConnectionEvent`**
    *   Dispatched when the active connection switches to the primary database.
    *   Properties: `?string $previousConnectionName`, `string $newConnectionName` (primary).

*   **`Nuxgame\\LaravelDynamicDBFailover\\Events\\SwitchedToFailoverConnectionEvent`**
    *   Dispatched when the active connection switches to the failover database.
    *   Properties: `?string $previousConnectionName`, `string $newConnectionName` (failover).

*   **`Nuxgame\\LaravelDynamicDBFailover\\Events\\LimitedFunctionalityModeActivatedEvent`**
    *   Dispatched when both primary and failover connections are down, and the system switches to the blocking connection.
    *   Properties: `string $connectionName` (the blocking connection name).

*   **`Nuxgame\\LaravelDynamicDBFailover\\Events\\ExitedLimitedFunctionalityModeEvent`**
    *   Dispatched when the system switches from the blocking connection back to either the primary or failover connection.
    *   Properties: `string $restoredToConnectionName` (primary or failover).

*   **`Nuxgame\\LaravelDynamicDBFailover\\Events\\PrimaryConnectionRestoredEvent`**
    *   Dispatched by `ConnectionStateManager` when the primary connection becomes healthy after being down.
    *   Properties: `string $connectionName` (primary).

*   **`Nuxgame\\LaravelDynamicDBFailover\\Events\\FailoverConnectionRestoredEvent`**
    *   Dispatched by `ConnectionStateManager` when the failover connection becomes healthy after being down.
    *   Properties: `string $connectionName` (failover).

*   **`Nuxgame\\LaravelDynamicDBFailover\\Events\\PrimaryConnectionDownEvent`**
    *   Dispatched by `ConnectionStateManager` when the primary connection is confirmed down after reaching the failure threshold.
    *   Properties: `string $connectionName` (primary).

*   **`Nuxgame\\LaravelDynamicDBFailover\\Events\\FailoverConnectionDownEvent`**
    *   Dispatched by `ConnectionStateManager` when the failover connection is confirmed down after reaching the failure threshold.
    *   Properties: `string $connectionName` (failover).

*   **`Nuxgame\\LaravelDynamicDBFailover\\Events\\ConnectionHealthyEvent`**
    *   Dispatched by `ConnectionStateManager` when a monitored connection (primary or failover) is confirmed healthy.
    *   Properties: `string $connectionName`.

*   **`Nuxgame\\LaravelDynamicDBFailover\\Events\\ConnectionDownEvent`** (Legacy / Generic)
    *   Dispatched by `ConnectionStateManager` if a connection (not specifically primary or failover, or if those specific events are not handled) is confirmed down. Currently, `PrimaryConnectionDownEvent` and `FailoverConnectionDownEvent` are preferred and dispatched for those specific connections.
    *   Properties: `string $connectionName`.

*   **`Nuxgame\\LaravelDynamicDBFailover\\Events\\CacheUnavailableEvent`**
    *   Dispatched by `ConnectionStateManager` if there's an error accessing the cache for connection statuses.
    *   Properties: `\Exception $exception`.

*   **`Nuxgame\\LaravelDynamicDBFailover\\Events\\DatabaseConnectionSwitchedEvent`** (Legacy)
    *   A generic event that was previously used for all connection switches. Listeners should prefer the more specific `SwitchedToPrimaryConnectionEvent` and `SwitchedToFailoverConnectionEvent`. While its direct dispatch from `DatabaseFailoverManager`'s main logic has been removed, it's kept for potential backward compatibility or direct usage.
    *   Properties: `?string $previousConnectionName`, `string $newConnectionName`.

You can listen for these events in your application to implement custom logic (e.g., logging, notifications).

## Testing

To run the package's tests, it's recommended to use the Makefile command if you are working within the provided demo project:
```bash
make test-package
```
This ensures the test environment is correctly set up.

Alternatively, if you have the package as a standalone dependency or want to run tests directly from its directory:
```bash
cd packages/nuxgame/laravel-dynamic-db-failover # Or your path to the package
../../vendor/bin/phpunit # Adjust path to your main project's phpunit
```

## Contributing

Contributions are welcome! Please feel free to submit pull requests or create issues for bugs and feature requests.

1.  Fork the repository.
2.  Create your feature branch (`git checkout -b feature/my-new-feature`).
3.  Commit your changes (`git commit -am 'Add some feature'`).
4.  Push to the branch (`git push origin feature/my-new-feature`).
5.  Create a new Pull Request.

Please ensure tests pass before submitting a PR.

## License

The Laravel Dynamic Database Failover package is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT). 

## Class Diagram

The following diagram illustrates the main classes of the package and their relationships.

```mermaid
classDiagram
    direction LR

    class DynamicDBFailoverServiceProvider {
        +register()
        +boot()
        +scheduleHealthChecks()
    }

    class DatabaseFailoverManager {
        -config: ConfigRepository
        -stateManager: ConnectionStateManager
        -dbManager: IlluminateDBManager
        -events: EventDispatcher
        +determineAndSetConnection()
        +switchToPrimary()
        +switchToFailover()
        +switchToBlocking()
    }

    class ConnectionStateManager {
        -healthChecker: ConnectionHealthChecker
        -cache: CacheRepositoryContract
        -config: ConfigRepository
        -events: EventDispatcher
        +updateConnectionStatus(connectionName)
        +getConnectionStatus(connectionName): ConnectionStatus
        +getFailureCount(connectionName): int
        +setConnectionStatus(connectionName, status, failureCount)
    }

    class ConnectionHealthChecker {
        -dbManager: IlluminateDBManager
        -config: ConfigRepository
        +isHealthy(connectionName): bool
    }

    class CheckDatabaseHealthCommand {
        -stateManager: ConnectionStateManager
        -config: ConfigRepository
        +handle()
    }

    class BlockingConnection {
        +select()
        +insert()
        +update()
        +delete()
    }

    class ConnectionStatus {
        <<Enumeration>>
        HEALTHY
        DOWN
        UNKNOWN
    }

    class EventDispatcher
    class ConfigRepository
    class IlluminateDBManager
    class CacheRepositoryContract
    class CacheFactoryContract

    DynamicDBFailoverServiceProvider --> DatabaseFailoverManager : uses
    DynamicDBFailoverServiceProvider --> ConnectionStateManager : uses
    DynamicDBFailoverServiceProvider --> ConnectionHealthChecker : uses
    DynamicDBFailoverServiceProvider ..> CheckDatabaseHealthCommand : registers

    DatabaseFailoverManager o-- ConfigRepository
    DatabaseFailoverManager o-- ConnectionStateManager
    DatabaseFailoverManager o-- IlluminateDBManager
    DatabaseFailoverManager o-- EventDispatcher
    DatabaseFailoverManager ..> Events : dispatches

    ConnectionStateManager o-- ConnectionHealthChecker
    ConnectionStateManager o-- ConfigRepository
    ConnectionStateManager o-- EventDispatcher
    ConnectionStateManager o-- CacheFactoryContract
    ConnectionStateManager --> CacheRepositoryContract : uses
    ConnectionStateManager ..> ConnectionStatus : uses
    ConnectionStateManager ..> Events : dispatches

    ConnectionHealthChecker o-- IlluminateDBManager
    ConnectionHealthChecker o-- ConfigRepository

    CheckDatabaseHealthCommand o-- ConnectionStateManager
    CheckDatabaseHealthCommand o-- ConfigRepository

    BlockingConnection --|> Illuminate\Database\Connection
    BlockingConnection ..> Exceptions.BlockingConnectionUsedException : throws

    namespace Events {
        class CacheUnavailableEvent
        class ConnectionHealthyEvent
        class DefaultConnectionChangedEvent
        class FailoverConnectionDownEvent
        class FailoverConnectionRestoredEvent
        class FullFunctionalityModeEvent
        class LimitedFunctionalityModeEvent
        class PrimaryConnectionDownEvent
        class PrimaryConnectionRestoredEvent
    }
    namespace Exceptions {
        class BlockingConnectionUsedException
        class InvalidConnectionStatusException
    }
```

This diagram can be rendered by any Markdown viewer that supports Mermaid.js (e.g., GitHub, GitLab, or IDE plugins).
