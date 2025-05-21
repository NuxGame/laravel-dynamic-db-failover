<?php

namespace Nuxgame\LaravelDynamicDBFailover;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\DatabaseManager as IlluminateDBManager; // Alias to avoid conflict
use Illuminate\Contracts\Events\Dispatcher as EventDispatcherContract;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract; // Added for scheduling
use Illuminate\Console\Scheduling\Schedule; // Added for scheduling
use Nuxgame\LaravelDynamicDBFailover\Console\Commands\CheckDatabaseHealthCommand;
use Nuxgame\LaravelDynamicDBFailover\Database\BlockingConnection;
use Nuxgame\LaravelDynamicDBFailover\Services\DatabaseFailoverManager;
use Nuxgame\LaravelDynamicDBFailover\HealthCheck\ConnectionStateManager;
use Nuxgame\LaravelDynamicDBFailover\HealthCheck\ConnectionHealthChecker;
use Illuminate\Contracts\Config\Repository as ConfigRepositoryContract;
use Illuminate\Contracts\Cache\Factory as CacheFactoryContract; // Use Cache Factory for store resolution
use Illuminate\Support\Facades\Log; // Added for logging

/**
 * Class DynamicDBFailoverServiceProvider
 *
 * The main service provider for the Laravel Dynamic Database Failover package.
 * This provider registers necessary services, commands, custom database drivers,
 * and handles the initial determination of the active database connection upon booting.
 */
class DynamicDBFailoverServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // Merge package configuration with the application's configuration.
        $this->mergeConfigFrom(
            __DIR__.'/../config/dynamic_db_failover.php', 'dynamic_db_failover'
        );

        // Register ConnectionHealthChecker as a singleton.
        $this->app->singleton(ConnectionHealthChecker::class, function ($app) {
            return new ConnectionHealthChecker(
                $app->make(IlluminateDBManager::class),
                $app->make(ConfigRepositoryContract::class)
            );
        });

        // Register ConnectionStateManager as a singleton.
        $this->app->singleton(ConnectionStateManager::class, function ($app) {
            return new ConnectionStateManager(
                $app->make(ConnectionHealthChecker::class),
                $app->make(ConfigRepositoryContract::class),
                $app->make(EventDispatcherContract::class), // Inject Event Dispatcher
                $app->make(CacheFactoryContract::class) // Inject Cache Factory
            );
        });

        // Register DatabaseFailoverManager as a singleton.
        $this->app->singleton(DatabaseFailoverManager::class, function ($app) {
            return new DatabaseFailoverManager(
                $app->make(ConfigRepositoryContract::class),
                $app->make(ConnectionStateManager::class),
                $app->make(IlluminateDBManager::class),
                $app->make(EventDispatcherContract::class)
            );
        });

        // Register the custom 'blocking' database driver.
        // This driver is used when all other connections (primary, failover) are unavailable.
        $this->app->resolving('db', function (IlluminateDBManager $db) {
            $db->extend('blocking', function ($config, $name) {
                // $config contains the configuration array for this connection from config/database.php
                // $name contains the connection name (e.g., 'blocking_connection')

                // The BlockingConnection does not interact with a real PDO object for its primary function
                // (which is to throw exceptions on query attempts). Thus, a dummy stdClass suffices
                // to satisfy the constructor's type hint if it were for a generic PDO-like object,
                // or simply as a placeholder if no PDO methods are called.
                $pdoDummy = new \stdClass();

                return new BlockingConnection($pdoDummy, $config['database'], $config['prefix'], $config);
            });
        });

        // Register Artisan commands if running in the console.
        if ($this->app->runningInConsole()) {
            $this->commands([
                CheckDatabaseHealthCommand::class,
            ]);
        }
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // Publish package configuration if running in console.
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/dynamic_db_failover.php' => config_path('dynamic_db_failover.php'),
            ], 'config');

            // Schedule the health check command
            $this->scheduleHealthChecks();

            // Placeholder for publishing migrations if needed in the future.
            // $this->publishes([
            //     __DIR__.'/../database/migrations/' => database_path('migrations'),
            // ], 'migrations');

            // Placeholder for registering additional Artisan commands.
            // $this->commands([
            //     YourConsoleCommand::class,
            // ]);
        }

        // Placeholder for loading routes if the package provides them.
        // $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // Placeholder for loading views if the package provides them.
        // $this->loadViewsFrom(__DIR__.'/../resources/views', 'dynamic-db-failover');
        // $this->publishes([
        //     __DIR__.'/../resources/views' => resource_path('views/vendor/dynamic-db-failover'),
        // ], 'views');

        // Placeholder for loading translation files if the package provides them.
        // $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'dynamic-db-failover');
        // $this->publishes([
        //     __DIR__.'/../resources/lang' => resource_path('lang/vendor/dynamic-db-failover'),
        // ], 'translations');

        // Determine and set the initial active database connection based on health checks.
        // This is crucial for the package to function correctly from the start of the application lifecycle.
        if ($this->shouldRunFailoverLogic()) {
             try {
                /** @var DatabaseFailoverManager $failoverManager */
                $failoverManager = $this->app->make(DatabaseFailoverManager::class);
                $failoverManager->determineAndSetConnection();
            } catch (\Exception $e) {
                // Log a critical error if the DatabaseFailoverManager fails to initialize during boot.
                // This could happen if, for example, the cache service is down and ConnectionStateManager
                // cannot operate, or if there's a fundamental issue with DB configuration resolving.
                // The application might be in an unstable state if this fails.
                // For now, we log the error and allow the application to continue, which might mean
                // it operates on Laravel's default connection without failover capabilities active.
                logger()->critical(
                    'DynamicDBFailover: Failed to initialize DatabaseFailoverManager during boot: ' . $e->getMessage(),
                    ['exception' => $e]
                );
            }
        }
    }

    /**
     * Schedules the database health check command based on package configuration.
     *
     * @return void
     */
    protected function scheduleHealthChecks(): void
    {
        $this->app->booted(function () {
            /** @var ConfigRepositoryContract $config */
            $config = $this->app->make(ConfigRepositoryContract::class);

            if (!$this->app->runningInConsole() || !$this->app->bound(Schedule::class)) {
                return;
            }

            /** @var Schedule $schedule */
            $schedule = $this->app->make(Schedule::class);
            $command = $schedule->command(CheckDatabaseHealthCommand::class);

            $scheduleFrequency = $config->get('dynamic_db_failover.health_check.schedule_frequency', 'everySecond');

            switch (strtolower($scheduleFrequency)) {
                case 'everysecond':
                    $command->everySecond();
                    Log::info('DynamicDBFailover: Health check command scheduled to run every second.');
                    break;
                case 'everyminute':
                    $command->everyMinute();
                    Log::info('DynamicDBFailover: Health check command scheduled to run every minute.');
                    break;
                case 'disabled':
                    Log::info('DynamicDBFailover: Automatic health check command scheduling is disabled by configuration.');
                    break;
                default:
                    $command->everySecond();
                    Log::warning('DynamicDBFailover: Unknown schedule_frequency value \"' . $scheduleFrequency . '\". Defaulting to everySecond.');
                    break;
            }
        });
    }

    /**
     * Determines if the failover logic should run.
     * This method provides a control point to enable or disable the failover mechanism,
     * for instance, based on configuration or the current application environment (e.g., console commands).
     *
     * @return bool True if the failover logic should be executed, false otherwise.
     */
    protected function shouldRunFailoverLogic(): bool
    {
        // Check the 'dynamic_db_failover.enabled' configuration value.
        // If set to false, the failover logic will not run.
        if (!$this->app->make(ConfigRepositoryContract::class)->get('dynamic_db_failover.enabled', true)) {
            return false;
        }

        // Example: Future enhancement to exclude certain console commands.
        // if ($this->app->runningInConsole()) {
        //     $excludedCommands = $this->app->make(ConfigRepositoryContract::class)->get('dynamic_db_failover.excluded_console_commands', [
        //         'migrate', 'migrate:fresh', 'migrate:rollback', // etc.
        //     ]);
        //     // This would require inspecting the current command, e.g., via $this->app['request']->server('argv')
        //     // or a dedicated command checker. For simplicity, this is currently a basic check.
        // }

        return true;
    }
}
