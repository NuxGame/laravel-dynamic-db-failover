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
        'name' => 'your_blocking_connection_name',
        // Other options like 'database', 'prefix' are ignored for blocking driver
    ],
    ```

3.  **Update `config/dynamic_db_failover.php`:**

    *   `enabled`: Set to `true` to enable the failover mechanism.
    *   `connections`: Specify the names of your `primary`, `failover`, and `blocking` connections as defined in `config/database.php`.
    *   `health_check`:
        *   `query`: The SQL query to execute for health checks.
        *   `failure_threshold`: Number of consecutive failures before a connection is marked as down.
    *   `cache`:
        *   `store`: The cache store to use for storing connection statuses (e.g., `redis`, `file`).
        *   `prefix`: Prefix for cache keys.
        *   `ttl_seconds`: Time-to-live for connection status in cache.
    *   `events`: Configure which events from this package should be dispatched globally.

## Usage

Once configured and enabled, the package works automatically. The `DynamicDBFailoverServiceProvider` hooks into Laravel's database manager. On each request (if not cached), or when `DatabaseFailoverManager::determineAndSetConnection()` is called, it checks the health of connections (based on cached status or fresh checks) and sets the default database connection accordingly.

### Artisan Command

You can run health checks manually or schedule them via the Laravel Task Scheduler:

```bash
php artisan failover:health-check
```
To check a specific connection:
```bash
php artisan failover:health-check your_connection_name
```

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
    *   Properties: `string $connectionName`, `string $method`, `\\Exception $exception`.

*   **`Nuxgame\\LaravelDynamicDBFailover\\Events\\DatabaseConnectionSwitchedEvent`** (Legacy)
    *   A generic event that was previously used for all connection switches. Listeners should prefer the more specific `SwitchedToPrimaryConnectionEvent` and `SwitchedToFailoverConnectionEvent`. While its direct dispatch from `DatabaseFailoverManager`'s main logic has been removed, it's kept for potential backward compatibility or direct usage.
    *   Properties: `?string $previousConnectionName`, `string $newConnectionName`.

You can listen for these events in your application to implement custom logic (e.g., logging, notifications).

## Testing

To run the package's tests, navigate to your main project root and use the script (if available from the demo project setup):

```bash
make test-package
```
Or, if you have the package pulled in as a standalone development dependency, you can run PHPUnit from its directory:
```bash
cd packages/nuxgame/laravel-dynamic-db-failover
../../vendor/bin/phpunit
```
(Adjust path to `phpunit` based on your main project's vendor directory).

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
