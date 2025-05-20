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

/**
 * Unit tests for the {@see DynamicDBFailoverServiceProvider} class.
 *
 * This test suite verifies that the service provider correctly registers services,
 * commands, database drivers, and handles configuration publishing and conditional logic
 * based on the package's enabled status.
 */
class DynamicDBFailoverServiceProviderTest extends TestCase
{
    use MockeryPHPUnitIntegration; // Integrates Mockery with PHPUnit.

    /** @var \\Mockery\\MockInterface|DatabaseFailoverManager Mocked DatabaseFailoverManager. */
    protected $dbFailoverManagerMock;

    /**
     * Sets up the test environment before each test.
     * Currently, no specific setup beyond parent::setUp() is needed here.
     */
    protected function setUp(): void
    {
        parent::setUp();
    }

    /**
     * Cleans up the test environment after each test.
     * Currently, no specific teardown beyond parent::tearDown() is needed here.
     */
    protected function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * Get package providers. Used by Orchestra Testbench.
     *
     * @param \\Illuminate\\Foundation\\Application $app The application instance.
     * @return array An array containing the service provider class.
     */
    protected function getPackageProviders($app): array
    {
        return [DynamicDBFailoverServiceProvider::class]; // Register the package's service provider.
    }

    /**
     * Define environment setup. Used by Orchestra Testbench.
     *
     * This method configures the application environment for testing the service provider.
     * It loads the package's default configuration, sets up test database connections
     * (primary, failover, blocking), and mocks core services like ConnectionStateManager
     * and DatabaseFailoverManager.
     *
     * @param \\Illuminate\\Foundation\\Application $app The application instance.
     */
    protected function getEnvironmentSetUp($app): void
    {
        // Load package configuration defaults into the test application's config.
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

        // Override specific config values for testing.
        $app['config']->set('dynamic_db_failover.connections.primary', 'mysql_primary_test');
        $app['config']->set('dynamic_db_failover.connections.failover', 'mysql_failover_test');
        $app['config']->set('dynamic_db_failover.connections.blocking', 'blocking_test');

        // Set up dummy database connections for testing.
        $app['config']->set('database.default', $app['config']->get('dynamic_db_failover.connections.primary'));
        $app['config']->set('database.connections.mysql_primary_test', [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3306', 'database' => 'test_primary_db',
            'username' => 'testuser', 'password' => 'testpass', 'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true, 'engine' => null,
        ]);
        $app['config']->set('database.connections.mysql_failover_test', [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3307', 'database' => 'test_failover_db',
            'username' => 'testuser', 'password' => 'testpass', 'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true, 'engine' => null,
        ]);
        $app['config']->set('database.connections.blocking_test', [
            'driver' => 'blocking', 'name' => 'blocking_test', 'database' => 'blocking_db', 'prefix' => ''
        ]);

        // Mock ConnectionStateManager to prevent actual cache/health operations.
        // @phpstan-ignore-next-line
        $stateManagerMock = Mockery::mock(ConnectionStateManager::class);
        // @phpstan-ignore-next-line
        $stateManagerMock->shouldReceive('getConnectionStatus')->withAnyArgs()->andReturn(ConnectionStatus::UNKNOWN);
        // @phpstan-ignore-next-line
        $stateManagerMock->shouldReceive('getFailureCount')->withAnyArgs()->andReturn(0);
        $app->instance(ConnectionStateManager::class, $stateManagerMock);

        // Mock DatabaseFailoverManager for controlling its behavior.
        // @phpstan-ignore-next-line
        $this->dbFailoverManagerMock = Mockery::mock(DatabaseFailoverManager::class);
        $app->instance(DatabaseFailoverManager::class, $this->dbFailoverManagerMock);

        // Conditionally expect determineAndSetConnection based on package config.
        // This expectation is set here because refreshApplication() in tests will trigger provider booting.
        if ($app['config']->get('dynamic_db_failover.enabled', false)) { // Default to false if not set
            // @phpstan-ignore-next-line
            $this->dbFailoverManagerMock->shouldReceive('determineAndSetConnection')->once();
        } else {
            // @phpstan-ignore-next-line
            $this->dbFailoverManagerMock->shouldNotReceive('determineAndSetConnection');
        }
    }

    /**
     * Tests that core services (ConnectionHealthChecker, ConnectionStateManager, DatabaseFailoverManager)
     * are registered as singletons in the service container.
     * @test
     */
    public function test_it_registers_services_as_singletons(): void
    {
        $instance1 = $this->app->make(ConnectionHealthChecker::class);
        $instance2 = $this->app->make(ConnectionHealthChecker::class);
        $this->assertSame($instance1, $instance2, 'ConnectionHealthChecker should be a singleton.');

        // ConnectionStateManager is already mocked as a singleton instance in getEnvironmentSetUp.
        $instance1 = $this->app->make(ConnectionStateManager::class);
        $instance2 = $this->app->make(ConnectionStateManager::class);
        $this->assertSame($instance1, $instance2, 'ConnectionStateManager should be a singleton.');

        // DatabaseFailoverManager is also mocked as a singleton instance.
        $managerInstance1 = $this->app->make(DatabaseFailoverManager::class);
        $managerInstance2 = $this->app->make(DatabaseFailoverManager::class);
        $this->assertSame($managerInstance1, $managerInstance2, 'DatabaseFailoverManager should be a singleton resolved from provider.');
    }

    /**
     * Tests that the custom 'blocking' database driver is correctly registered and resolvable.
     * It should return an instance of BlockingConnection.
     * @test
     */
    public function test_it_registers_blocking_database_driver(): void
    {
        // Refresh application to ensure service provider registers the driver extension.
        $this->refreshApplication();

        $db = $this->app->make('db'); // Resolve the DatabaseManager.
        $this->assertInstanceOf(DatabaseManager::class, $db, 'Failed to resolve a real DatabaseManager.');

        // Attempt to get a connection using the 'blocking_test' configuration.
        $connection = $db->connection('blocking_test');
        $this->assertInstanceOf(BlockingConnection::class, $connection, 'Connection was not an instance of BlockingConnection.');
    }

    /**
     * Tests that the service provider's boot method correctly publishes the package configuration file
     * when the `vendor:publish` command is run with the appropriate tag.
     * @test
     */
    public function test_boot_publishes_configuration(): void
    {
        $configPath = config_path('dynamic_db_failover.php');
        // Ensure the config file doesn't exist before publishing.
        if (file_exists($configPath)) {
            unlink($configPath);
        }
        $this->assertFileDoesNotExist($configPath);

        // Execute the vendor:publish command for the package's config.
        $this->artisan('vendor:publish', ['--provider' => DynamicDBFailoverServiceProvider::class, '--tag' => 'config']);
        $this->assertFileExists($configPath);

        // Clean up by deleting the published config file.
        if (file_exists($configPath)) {
            unlink($configPath);
        }
    }

    /**
     * Tests that the `determineAndSetConnection` method on DatabaseFailoverManager is called during boot
     * when the package is configured as enabled.
     * @test
     */
    public function test_boot_determines_connection_when_enabled(): void
    {
        Config::set('dynamic_db_failover.enabled', true);
        // The expectation for determineAndSetConnection is set in getEnvironmentSetUp.
        // Refreshing the application will trigger the service provider's boot method.
        $this->refreshApplication();
        // Mockery will assert the expectation upon tearDown.
    }

    /**
     * Tests that the `determineAndSetConnection` method on DatabaseFailoverManager is NOT called during boot
     * when the package is configured as disabled.
     * @test
     */
    public function test_boot_does_not_determine_connection_when_disabled(): void
    {
        Config::set('dynamic_db_failover.enabled', false);
        // The expectation for determineAndSetConnection (or its absence) is set in getEnvironmentSetUp.
        $this->refreshApplication();
        // Mockery will assert the expectation upon tearDown.
    }

    /**
     * Tests that the `failover:health-check` command is registered when the application is running in the console.
     * @test
     */
    public function test_boot_registers_commands_when_running_in_console(): void
    {
        $this->app['runningInConsole'] = true; // Simulate running in console.
        $this->refreshApplication(); // Re-boot the service provider.

        $commands = Artisan::all(); // Get all registered Artisan commands.
        $this->assertArrayHasKey('failover:health-check', $commands, "Command 'failover:health-check' not found.");
        $this->assertInstanceOf(CheckDatabaseHealthCommand::class, $commands['failover:health-check']);
    }
}
