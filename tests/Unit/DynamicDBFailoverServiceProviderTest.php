<?php

namespace Nuxgame\LaravelDynamicDBFailover\Tests\Unit;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Nuxgame\LaravelDynamicDBFailover\Console\Commands\CheckDatabaseHealthCommand;
use Nuxgame\LaravelDynamicDBFailover\Database\BlockingConnection;
use Nuxgame\LaravelDynamicDBFailover\DynamicDBFailoverServiceProvider;
use Nuxgame\LaravelDynamicDBFailover\HealthCheck\ConnectionHealthChecker;
use Nuxgame\LaravelDynamicDBFailover\HealthCheck\ConnectionStateManager;
use Nuxgame\LaravelDynamicDBFailover\Services\DatabaseFailoverManager;
use Orchestra\Testbench\TestCase;
use Illuminate\Support\Facades\Event;
use Nuxgame\LaravelDynamicDBFailover\Events\DatabaseConnectionSwitchedEvent;
use Nuxgame\LaravelDynamicDBFailover\Events\LimitedFunctionalityModeActivatedEvent;
use Illuminate\Database\DatabaseManager;
use Nuxgame\LaravelDynamicDBFailover\Enums\ConnectionStatus;
use Illuminate\Support\Facades\Log;
use Mockery;

class DynamicDBFailoverServiceProviderTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected $dbFailoverManagerMock;

    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [DynamicDBFailoverServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $packageConfigFile = realpath(__DIR__ . '/../../../../config/dynamic_db_failover.php');
        if ($packageConfigFile && file_exists($packageConfigFile)) {
            $packageConfig = require $packageConfigFile;
            foreach ($packageConfig as $key => $value) {
                if (is_array($value)) {
                    foreach ($value as $subKey => $subValue) {
                        $app['config']->set("dynamic_db_failover.{$key}.{$subKey}", $subValue);
                    }
                } else {
                    $app['config']->set("dynamic_db_failover.{$key}", $value);
                }
            }
        }

        $app['config']->set('dynamic_db_failover.connections.primary', 'mysql_primary_test');
        $app['config']->set('dynamic_db_failover.connections.failover', 'mysql_failover_test');
        $app['config']->set('dynamic_db_failover.connections.blocking', 'blocking_test');

        $app['config']->set('database.default', $app['config']->get('dynamic_db_failover.connections.primary'));
        $app['config']->set('database.connections.mysql_primary_test', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'test_primary_db',
            'username' => 'testuser',
            'password' => 'testpass',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
        ]);
        $app['config']->set('database.connections.mysql_failover_test', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3307',
            'database' => 'test_failover_db',
            'username' => 'testuser',
            'password' => 'testpass',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
        ]);
        $app['config']->set('database.connections.blocking_test', [
            'driver' => 'blocking',
            'name' => 'blocking_test',
            'database' => 'blocking_db',
            'prefix' => ''
        ]);

        $stateManagerMock = Mockery::mock(ConnectionStateManager::class);
        $stateManagerMock->shouldReceive('getConnectionStatus')->withAnyArgs()->andReturn(ConnectionStatus::UNKNOWN);
        $stateManagerMock->shouldReceive('getFailureCount')->withAnyArgs()->andReturn(0);
        $app->instance(ConnectionStateManager::class, $stateManagerMock);

        $this->dbFailoverManagerMock = Mockery::mock(DatabaseFailoverManager::class);
        $app->instance(DatabaseFailoverManager::class, $this->dbFailoverManagerMock);

        if ($app['config']->get('dynamic_db_failover.enabled')) {
            $this->dbFailoverManagerMock->shouldReceive('determineAndSetConnection')->once();
        } else {
            $this->dbFailoverManagerMock->shouldNotReceive('determineAndSetConnection');
        }
    }

    public function test_it_registers_services_as_singletons(): void
    {
        $instance1 = $this->app->make(ConnectionHealthChecker::class);
        $instance2 = $this->app->make(ConnectionHealthChecker::class);
        $this->assertSame($instance1, $instance2, 'ConnectionHealthChecker should be a singleton.');

        $instance1 = $this->app->make(ConnectionStateManager::class);
        $instance2 = $this->app->make(ConnectionStateManager::class);
        $this->assertSame($instance1, $instance2, 'ConnectionStateManager should be a singleton.');

        $managerInstance1 = $this->app->make(DatabaseFailoverManager::class);
        $managerInstance2 = $this->app->make(DatabaseFailoverManager::class);
        $this->assertSame($managerInstance1, $managerInstance2, 'DatabaseFailoverManager should be a singleton resolved from provider.');
    }

    public function test_it_registers_blocking_database_driver(): void
    {
        $this->refreshApplication();

        $db = $this->app->make('db');
        $this->assertInstanceOf(\Illuminate\Database\DatabaseManager::class, $db, 'Failed to resolve a real DatabaseManager.');

        $connection = $db->connection('blocking_test');
        $this->assertInstanceOf(BlockingConnection::class, $connection, 'Connection was not an instance of BlockingConnection.');
    }

    public function test_boot_publishes_configuration(): void
    {
        $configPath = config_path('dynamic_db_failover.php');
        if (file_exists($configPath)) {
            unlink($configPath);
        }
        $this->assertFileDoesNotExist($configPath);

        $this->artisan('vendor:publish', ['--provider' => DynamicDBFailoverServiceProvider::class, '--tag' => 'config']);
        $this->assertFileExists($configPath);

        if (file_exists($configPath)) {
            unlink($configPath);
        }
    }

    public function test_boot_determines_connection_when_enabled(): void
    {
        Config::set('dynamic_db_failover.enabled', true);

        $this->refreshApplication();
    }

    public function test_boot_does_not_determine_connection_when_disabled(): void
    {
        Config::set('dynamic_db_failover.enabled', false);

        $this->refreshApplication();
    }

    public function test_boot_registers_commands_when_running_in_console(): void
    {
        $this->app['runningInConsole'] = true;
        $this->refreshApplication();

        $commands = Artisan::all();
        $this->assertArrayHasKey('failover:health-check', $commands);
        $this->assertInstanceOf(CheckDatabaseHealthCommand::class, $commands['failover:health-check']);
    }
}
