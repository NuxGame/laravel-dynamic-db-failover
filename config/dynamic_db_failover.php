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
    | Primary Database Connection Name
    |--------------------------------------------------------------------------
    |
    | This is the name of the primary database connection as defined in your
    | application's config/database.php file.
    |
    */
    'primary_connection_name' => env('DB_CONNECTION', 'mysql'),

    /*
    |--------------------------------------------------------------------------
    | Failover Database Connection Name
    |--------------------------------------------------------------------------
    |
    | This is the name of the failover database connection (e.g., RDS Proxy)
    | as defined in your application's config/database.php file.
    |
    */
    'failover_connection_name' => 'mysql_failover', // Example, ensure this connection is configured

    /*
    |--------------------------------------------------------------------------
    | Blocking Driver Connection Name
    |--------------------------------------------------------------------------
    |
    | This is the name for the connection that will use the custom "blocking"
    | database driver when both primary and failover connections are down.
    | This connection will also need to be defined in config/database.php,
    | pointing to the 'blocking' driver.
    |
    */
    'blocking_connection_name' => 'blocking_connection',

    /*
    |--------------------------------------------------------------------------
    | Health Check Settings
    |--------------------------------------------------------------------------
    |
    | Common health check parameters applicable to both primary and failover
    | connections.
    |
    */
    'health_check' => [
        // The SQL query to execute for the health check.
        // It should be a lightweight query, e.g., 'SELECT 1'.
        'query' => 'SELECT 1',

        // The interval in seconds between health checks.
        'interval_seconds' => 60,

        // Timeout for the health check query in seconds.
        'timeout_seconds' => 5,

        // Number of consecutive failures to declare a connection unavailable.
        'failure_threshold' => 3,
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

    /*
    |--------------------------------------------------------------------------
    | Health Check Schedule
    |--------------------------------------------------------------------------
    |
    | Configure how often the `failover:health-check` command should run.
    | This is a cron expression string. Default is every minute.
    | You need to add this command to your Laravel application's console kernel.
    |
    | Example Kernel entry:
    |   $schedule->command('failover:health-check')
    |            ->cron(config('dynamic_db_failover.health_check.schedule_cron', '* * * * *'));
    |
    */
    'health_check_schedule_cron' => env('DYNAMIC_DB_FAILOVER_HEALTH_CHECK_CRON', '* * * * *'),
];
