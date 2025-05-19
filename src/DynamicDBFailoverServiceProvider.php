<?php

namespace Nuxgame\LaravelDynamicDBFailover;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\DatabaseManager as IlluminateDBManager; // Alias to avoid conflict
use Illuminate\Contracts\Events\Dispatcher as EventDispatcherContract;
use Nuxgame\LaravelDynamicDBFailover\Console\Commands\CheckDatabaseHealthCommand;
use Nuxgame\LaravelDynamicDBFailover\Database\BlockingConnection;
use Nuxgame\LaravelDynamicDBFailover\Services\DatabaseFailoverManager;
use Nuxgame\LaravelDynamicDBFailover\HealthCheck\ConnectionStateManager;
use Nuxgame\LaravelDynamicDBFailover\HealthCheck\ConnectionHealthChecker;
use Illuminate\Contracts\Config\Repository as ConfigRepositoryContract;
use Illuminate\Contracts\Cache\Factory as CacheFactoryContract; // Use Cache Factory for store resolution

class DynamicDBFailoverServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // Код регистрации для пакета (например, слияние конфигурации)
        $this->mergeConfigFrom(
            __DIR__.'/../config/dynamic_db_failover.php', 'dynamic_db_failover'
        );

        // Register ConnectionHealthChecker
        $this->app->singleton(ConnectionHealthChecker::class, function ($app) {
            return new ConnectionHealthChecker(
                $app->make(IlluminateDBManager::class),
                $app->make(ConfigRepositoryContract::class)
            );
        });

        // Register ConnectionStateManager
        $this->app->singleton(ConnectionStateManager::class, function ($app) {
            return new ConnectionStateManager(
                $app->make(ConnectionHealthChecker::class),
                $app->make(ConfigRepositoryContract::class),
                $app->make(EventDispatcherContract::class) // Inject Event Dispatcher
            );
        });

        // Register DatabaseFailoverManager
        $this->app->singleton(DatabaseFailoverManager::class, function ($app) {
            return new DatabaseFailoverManager(
                $app->make(ConfigRepositoryContract::class),
                $app->make(ConnectionStateManager::class),
                $app->make(IlluminateDBManager::class),
                $app->make(EventDispatcherContract::class)
            );
        });

        // Регистрация кастомного драйвера базы данных 'blocking'
        $this->app->resolving('db', function (IlluminateDBManager $db) {
            $db->extend('blocking', function ($config, $name) {
                // $config содержит массив конфигурации для этого соединения из config/database.php
                // $name содержит имя соединения (например, 'blocking_connection')

                // Поскольку BlockingConnection не использует реальное PDO, мы можем передать null
                // или фиктивный объект, который он ожидает в конструкторе.
                // Конструктор BlockingConnection ожидает $pdo, $database, $tablePrefix, $config.
                // $config['name'] = $name; // Можно добавить имя соединения в конфиг, если нужно внутри BlockingConnection

                // Заглушка для PDO, так как BlockingConnection его не использует по-настоящему.
                $pdoDummy = new \stdClass();

                return new BlockingConnection($pdoDummy, $config['database'], $config['prefix'], $config);
            });
        });

        // Register Artisan commands
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
        // Код загрузки для пакета (например, публикация конфигурации, миграций, представлений)
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/dynamic_db_failover.php' => config_path('dynamic_db_failover.php'),
            ], 'config');

            // Здесь можно будет добавить публикацию миграций, если они понадобятся
            // $this->publishes([
            //     __DIR__.'/../database/migrations/' => database_path('migrations'),
            // ], 'migrations');

            // Регистрация команд Artisan, если они будут
            // $this->commands([
            //     YourConsoleCommand::class,
            // ]);
        }

        // Загрузка маршрутов, если они есть
        // $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // Загрузка представлений, если они есть
        // $this->loadViewsFrom(__DIR__.'/../resources/views', 'dynamic-db-failover');
        // $this->publishes([
        //     __DIR__.'/../resources/views' => resource_path('views/vendor/dynamic-db-failover'),
        // ], 'views');

        // Загрузка файлов локализации, если они есть
        // $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'dynamic-db-failover');
        // $this->publishes([
        //     __DIR__.'/../resources/lang' => resource_path('lang/vendor/dynamic-db-failover'),
        // ], 'translations');

        // Determine and set the active database connection
        // This needs to happen after DB services are registered but before they are heavily used.
        // Boot method is a suitable place.
        if ($this->shouldRunFailoverLogic()) {
             try {
                $failoverManager = $this->app->make(DatabaseFailoverManager::class);
                $failoverManager->determineAndSetConnection();
            } catch (\Exception $e) {
                // Log critical error if failover manager itself fails during boot
                // Potentially fallback to a default connection if possible, or let it fail loudly
                logger()->critical('DynamicDBFailover: Failed to initialize DatabaseFailoverManager during boot: ' . $e->getMessage(), [
                    'exception' => $e
                ]);
                // Depending on severity, might re-throw or ensure a default connection like primary is set
                // For now, logging is the primary action.
            }
        }
    }

    /**
     * Determines if the failover logic should run.
     * For example, we might not want to run this for specific console commands or routes.
     * By default, it runs always if the package is enabled.
     */
    protected function shouldRunFailoverLogic(): bool
    {
        // Allow disabling via config for specific environments or globally
        if (!$this->app->make(ConfigRepositoryContract::class)->get('dynamic_db_failover.enabled', true)) {
            return false;
        }

        // Example: Avoid running for migrations or specific commands
        // if ($this->app->runningInConsole()) {
        //     $excludedCommands = $this->app->make(ConfigRepositoryContract::class)->get('dynamic_db_failover.excluded_console_commands', [
        //         'migrate', 'migrate:fresh', 'migrate:rollback', // etc.
        //     ]);
        //     // This requires inspecting current command, e.g., via $this->app['request']->server('argv') or a dedicated command checker
        //     // For simplicity, this check is basic. A more robust solution would inspect the input command.
        // }

        return true;
    }
}
