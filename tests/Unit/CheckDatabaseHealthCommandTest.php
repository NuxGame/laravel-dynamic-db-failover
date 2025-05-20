<?php

namespace Nuxgame\LaravelDynamicDBFailover\Tests\Unit;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Nuxgame\LaravelDynamicDBFailover\DynamicDBFailoverServiceProvider;
use Nuxgame\LaravelDynamicDBFailover\HealthCheck\ConnectionStateManager;
use Nuxgame\LaravelDynamicDBFailover\Enums\ConnectionStatus;
use Orchestra\Testbench\TestCase;

/**
 * Class CheckDatabaseHealthCommandTest
 *
 * Unit tests for the Nuxgame\LaravelDynamicDBFailover\Console\Commands\CheckDatabaseHealthCommand artisan command.
 * These tests cover various scenarios, including checking all connections, a specific connection,
 * handling of misconfigurations, and exceptions during health checks.
 */
class CheckDatabaseHealthCommandTest extends TestCase
{
    use MockeryPHPUnitIntegration; // Integrates Mockery for mocking objects.

    /** @var \Mockery\MockInterface|ConnectionStateManager Mock object for the ConnectionStateManager. */
    protected $connectionStateManagerMock;

    /**
     * Get package providers. Used by Orchestra Testbench.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array
     */
    protected function getPackageProviders($app): array
    {
        return [DynamicDBFailoverServiceProvider::class]; // Register the package's service provider.
    }

    /**
     * Define environment setup. Used by Orchestra Testbench.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function getEnvironmentSetUp($app): void
    {
        // Setup default config values for the dynamic_db_failover package for testing purposes.
        Config::set('dynamic_db_failover.connections.primary', 'mysql_primary');
        Config::set('dynamic_db_failover.connections.failover', 'mysql_failover');
        Config::set('dynamic_db_failover.health_check.failure_threshold', 3);
        Config::set('dynamic_db_failover.cache.ttl_seconds', 300);
        Config::set('dynamic_db_failover.cache.prefix', 'test_status');
        Config::set('dynamic_db_failover.cache.tag', 'test-tag');

        // Setup dummy database connections in the application's config.
        // These are used by the command to validate if a connection name exists.
        $app['config']->set('database.connections.mysql_primary', [
            'driver' => 'mysql', 'host' => 'localhost', 'database' => 'test_primary', 'username' => 'user', 'password' => 'pass'
        ]);
        $app['config']->set('database.connections.mysql_failover', [
            'driver' => 'mysql', 'host' => 'localhost', 'database' => 'test_failover', 'username' => 'user', 'password' => 'pass'
        ]);

        // Mock the ConnectionStateManager to control its behavior during tests
        // and prevent actual cache/health check operations.
        $this->connectionStateManagerMock = \Mockery::mock(ConnectionStateManager::class);
        $app->instance(ConnectionStateManager::class, $this->connectionStateManagerMock);
    }

    /**
     * Clean up the testing environment before the next test.
     */
    protected function tearDown(): void
    {
        // Remove the mocked instance to avoid interference between tests.
        $this->app->forgetInstance(ConnectionStateManager::class);
        // Close Mockery to verify expectations and clean up mocks.
        \Mockery::close();
        parent::tearDown();
    }

    /**
     * Tests that the command updates the status for all configured connections (primary and failover)
     * when no specific connection argument is provided.
     * @test
     */
    public function test_command_updates_status_for_all_connections_by_default(): void
    {
        // Mock expectations for the primary connection check.
        $this->connectionStateManagerMock
            ->shouldReceive('updateConnectionStatus')->with('mysql_primary')->once();
        $this->connectionStateManagerMock
            ->shouldReceive('getConnectionStatus')->with('mysql_primary')->once()->andReturn(ConnectionStatus::HEALTHY);
        $this->connectionStateManagerMock
            ->shouldReceive('getFailureCount')->with('mysql_primary')->once()->andReturn(0);

        // Mock expectations for the failover connection check.
        $this->connectionStateManagerMock
            ->shouldReceive('updateConnectionStatus')->with('mysql_failover')->once();
        $this->connectionStateManagerMock
            ->shouldReceive('getConnectionStatus')->with('mysql_failover')->once()->andReturn(ConnectionStatus::HEALTHY);
        $this->connectionStateManagerMock
            ->shouldReceive('getFailureCount')->with('mysql_failover')->once()->andReturn(0);

        // Execute the command and assert expected output and exit code.
        $this->artisan('failover:health-check')
            ->expectsOutputToContain('Starting database health checks...')
            ->expectsOutputToContain('Performing health checks for configured primary and failover connections.')
            ->expectsOutputToContain('Checking health of connection: mysql_primary...')
            ->expectsOutputToContain("Connection 'mysql_primary' status: HEALTHY, Failures: 0")
            ->expectsOutputToContain('Checking health of connection: mysql_failover...')
            ->expectsOutputToContain("Connection 'mysql_failover' status: HEALTHY, Failures: 0")
            ->expectsOutputToContain('Database health checks completed.')
            ->assertExitCode(Command::SUCCESS);
    }

    /**
     * Tests that the command updates the status only for a specified connection
     * when a connection name is provided as an argument.
     * @test
     */
    public function test_command_updates_status_for_specified_connection(): void
    {
        // Mock expectations for the specified primary connection check.
        $this->connectionStateManagerMock
            ->shouldReceive('updateConnectionStatus')->with('mysql_primary')->once();
        $this->connectionStateManagerMock
            ->shouldReceive('getConnectionStatus')->with('mysql_primary')->once()->andReturn(ConnectionStatus::DOWN);
        $this->connectionStateManagerMock
            ->shouldReceive('getFailureCount')->with('mysql_primary')->once()->andReturn(1);

        // Ensure the failover connection is not checked.
        $this->connectionStateManagerMock
            ->shouldNotReceive('updateConnectionStatus')->with('mysql_failover');

        // Execute the command with the specific connection argument.
        $this->artisan('failover:health-check', ['connection' => 'mysql_primary'])
            ->expectsOutputToContain('Starting database health checks...')
            ->expectsOutputToContain('Performing health check for specific connection: mysql_primary')
            ->expectsOutputToContain('Checking health of connection: mysql_primary...')
            ->expectsOutputToContain("Connection 'mysql_primary' status: DOWN, Failures: 1")
            ->expectsOutputToContain('Database health checks completed.')
            ->assertExitCode(Command::SUCCESS);
    }

    /**
     * Tests the command's behavior when an argument for a non-configured database connection is provided.
     * It should output an error and return a failure exit code.
     * @test
     */
    public function test_command_handles_attempt_to_check_non_configured_connection_argument(): void
    {
        // Ensure ConnectionStateManager::updateConnectionStatus is not called for an unconfigured connection.
        $this->connectionStateManagerMock
            ->shouldNotReceive('updateConnectionStatus');

        $invalidConnectionName = 'unconfigured_db_connection';

        // Execute the command with an invalid connection name.
        $this->artisan('failover:health-check', ['connection' => $invalidConnectionName])
            ->expectsOutputToContain("Connection '{$invalidConnectionName}' is not configured in your database settings.")
            ->assertExitCode(Command::FAILURE);
    }

    /**
     * Tests that the command correctly skips a connection if its name is configured as null or empty
     * in the package's failover settings, but still processes other valid configured connections.
     * @test
     */
    public function test_command_skips_unconfigured_connection_name_from_config(): void
    {
        // Simulate primary connection being unconfigured (null) in failover settings.
        Config::set('dynamic_db_failover.connections.primary', null);
        // Failover connection remains configured.
        Config::set('dynamic_db_failover.connections.failover', 'mysql_failover');

        // Ensure updateConnectionStatus is not called for null or 'mysql_primary' (which is no longer in failover config).
        $this->connectionStateManagerMock
            ->shouldNotReceive('updateConnectionStatus')->with(null);
        $this->connectionStateManagerMock
            ->shouldNotReceive('updateConnectionStatus')->with('mysql_primary');

        // Mock expectations for the still-configured failover connection.
        $this->connectionStateManagerMock
            ->shouldReceive('updateConnectionStatus')->with('mysql_failover')->once();
        $this->connectionStateManagerMock
            ->shouldReceive('getConnectionStatus')->with('mysql_failover')->once()->andReturn(ConnectionStatus::HEALTHY);
        $this->connectionStateManagerMock
            ->shouldReceive('getFailureCount')->with('mysql_failover')->once()->andReturn(0);

        // Execute the command.
        $this->artisan('failover:health-check')
            ->expectsOutputToContain('Starting database health checks...')
            ->expectsOutputToContain('Performing health checks for configured primary and failover connections.')
            ->expectsOutputToContain('Checking health of connection: mysql_failover...')
            ->expectsOutputToContain("Connection 'mysql_failover' status: HEALTHY, Failures: 0")
            ->expectsOutputToContain('Database health checks completed.')
            ->assertExitCode(Command::SUCCESS);
    }

    /**
     * Tests how the command handles an exception thrown by ConnectionStateManager during a health check.
     * The command should catch the exception, log an error for that connection, and continue processing others.
     * @test
     */
    public function test_command_handles_exception_during_health_check(): void
    {
        // Simulate an exception when checking the primary connection.
        $this->connectionStateManagerMock
            ->shouldReceive('updateConnectionStatus')->with('mysql_primary')->once()
            ->andThrow(new \RuntimeException('Cache totally unavailable'));

        // The command should still attempt to check the failover connection.
        $this->connectionStateManagerMock
            ->shouldReceive('updateConnectionStatus')->with('mysql_failover')->once();
        $this->connectionStateManagerMock
            ->shouldReceive('getConnectionStatus')->with('mysql_failover')->once()->andReturn(ConnectionStatus::HEALTHY);
        $this->connectionStateManagerMock
            ->shouldReceive('getFailureCount')->with('mysql_failover')->once()->andReturn(0);

        // Execute the command.
        $this->artisan('failover:health-check')
            ->expectsOutputToContain('Starting database health checks...')
            ->expectsOutputToContain('Performing health checks for configured primary and failover connections.')
            ->expectsOutputToContain('Checking health of connection: mysql_primary...')
            ->expectsOutputToContain("Failed to check health for connection 'mysql_primary': Cache totally unavailable")
            ->expectsOutputToContain('Checking health of connection: mysql_failover...')
            ->expectsOutputToContain("Connection 'mysql_failover' status: HEALTHY, Failures: 0")
            ->expectsOutputToContain('Database health checks completed.')
            ->assertExitCode(Command::SUCCESS); // Command itself completes successfully, errors are logged internally.
    }

    /**
     * Tests that the command outputs a warning if no database connections (primary or failover)
     * are configured in the dynamic_db_failover settings.
     * @test
     */
    public function test_command_warns_if_no_connections_are_configured_at_all(): void
    {
        // Simulate no connections configured for failover.
        Config::set('dynamic_db_failover.connections.primary', null);
        Config::set('dynamic_db_failover.connections.failover', null);

        $this->connectionStateManagerMock->shouldNotReceive('updateConnectionStatus');

        // Execute the command.
        $this->artisan('failover:health-check')
            ->expectsOutputToContain('Starting database health checks...')
            ->expectsOutputToContain('Performing health checks for configured primary and failover connections.')
            ->expectsOutputToContain('No connections configured or specified for health check.')
            ->assertExitCode(Command::SUCCESS); // Command returns success as it's not an error state for the command itself.
    }

    /**
     * Tests that the command correctly identifies a connection name provided as an argument
     * as non-existent if it's not defined in the main `database.connections` configuration.
     * @test
     */
    public function test_handle_logs_error_for_invalid_connection_name(): void
    {
        // The 'non_existent_db' connection is not set up in getEnvironmentSetUp(), so it's invalid.
        $this->connectionStateManagerMock
            ->shouldNotReceive('updateConnectionStatus');

        $invalidConnectionName = 'non_existent_db';

        // Execute the command with the invalid connection name.
        $this->artisan('failover:health-check', ['connection' => $invalidConnectionName])
            ->expectsOutputToContain("Connection '{$invalidConnectionName}' is not configured in your database settings.")
            ->assertExitCode(Command::FAILURE);
    }
}
