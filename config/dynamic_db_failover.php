<?php

// config/dynamic_db_failover.php

return [
    /*
    |--------------------------------------------------------------------------
    | Enable Dynamic DB Failover
    |--------------------------------------------------------------------------
    |
    | Set this to false to completely disable the dynamic failover logic.
    | Useful for specific environments or during maintenance.
    |
    */
    'enabled' => env('DYNAMIC_DB_FAILOVER_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Command Lifecycle Events
    |--------------------------------------------------------------------------
    |
    | Set this to true to dispatch DBHealthCheckCommandStarted and
    | DBHealthCheckCommandFinished events during the health check command.
    | This can be overridden by a command-line option.
    | Default for the package is false, can be overridden in app config.
    |
    */
    'dispatch_command_lifecycle_events' => env('DYNAMIC_DB_FAILOVER_DISPATCH_LIFECYCLE_EVENTS', false),

    /*
    |--------------------------------------------------------------------------
    | Database Connection Names
    |--------------------------------------------------------------------------
    |
    | Define the names of your primary, failover, and blocking connections
    | as they are configured in your application's config/database.php file.
    |
    */
    'connections' => [
        'primary' => env('DB_CONNECTION', 'mysql'),
        'failover' => env('DB_FAILOVER_CONNECTION', 'mysql_failover'), // Use env variable
        'blocking' => env('DB_BLOCKING_CONNECTION', 'blocking_connection'), // Use env variable
    ],

    /*
    |--------------------------------------------------------------------------
    | Health Check Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for the health checking mechanism.
    |
    */
    'health_check' => [
        // Number of consecutive failures before a connection is marked as DOWN.
        'failure_threshold' => env('DB_FAILOVER_FAILURE_THRESHOLD', 3),

        // Query to execute for health checks. Should be a lightweight query.
        'query' => env('DB_FAILOVER_HEALTH_QUERY', 'SELECT 1'),

        // Timeout in seconds for the health check query execution.
        'timeout_seconds' => env('DB_FAILOVER_HEALTH_TIMEOUT', 2),

        // Defines how often the health check command is scheduled by this package.
        // Possible values: 'everyMinute', 'everySecond', 'disabled'.
        // If 'disabled', the package will not schedule the command.
        // Default: 'everySecond'.
        'schedule_frequency' => env('DB_FAILOVER_HEALTH_SCHEDULE_FREQUENCY', 'everySecond'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for caching connection statuses.
    |
    */
    'cache' => [
        // The cache store to use (e.g., 'redis', 'memcached').
        // Ensure this store is configured in config/cache.php.
        // Leave null to use the default cache store.
        'store' => null,

        // Prefix for cache keys used by this package.
        'prefix' => 'dynamic_db_failover_status',

        // Time-to-live for cache entries in seconds.
        // Should generally be longer than the health check interval.
        'ttl_seconds' => env('DYNAMIC_DB_FAILOVER_CACHE_TTL_SECONDS', 300), // 5 minutes
    ],

    /*
    |--------------------------------------------------------------------------
    | Behavior on Cache Unavailability
    |--------------------------------------------------------------------------
    |
    | If true, the package will default to the primary connection if the cache
    | service is unavailable. If false, it might throw an exception or handle
    | it differently (current spec says default to primary).
    |
    */
    'default_to_primary_on_cache_unavailable' => true,

    /*
    |--------------------------------------------------------------------------
    | Cache Tag
    |--------------------------------------------------------------------------
    |
    | A cache tag to apply to all failover related cache items, if the
    | cache driver supports tagging. This allows for easier clearing of
    | all failover related cache entries.
    |
    */
    'tag' => 'dynamic-db-failover',
];
