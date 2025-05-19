<?php

namespace Nuxgame\LaravelDynamicDBFailover;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\DatabaseManager;
use Nuxgame\LaravelDynamicDBFailover\Database\BlockingConnection;

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

        // Регистрация кастомного драйвера базы данных 'blocking'
        $this->app->resolving('db', function (DatabaseManager $db) {
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
    }
}
