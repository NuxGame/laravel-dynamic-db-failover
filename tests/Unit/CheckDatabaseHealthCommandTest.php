<?php

namespace Nuxgame\LaravelDynamicDBFailover\Tests\Unit; // Should be Tests\Feature or Tests\Console typically

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Nuxgame\LaravelDynamicDBFailover\DynamicDBFailoverServiceProvider;
use Nuxgame\LaravelDynamicDBFailover\HealthCheck\ConnectionStateManager;
use Nuxgame\LaravelDynamicDBFailover\Enums\ConnectionStatus;
use Orchestra\Testbench\TestCase;

class CheckDatabaseHealthCommandTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected $connectionStateManagerMock;

    protected function getPackageProviders($app): array
    {
        return [DynamicDBFailoverServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Setup default config values for testing
        Config::set('dynamic_db_failover.connections.primary', 'mysql_primary');
        Config::set('dynamic_db_failover.connections.failover', 'mysql_failover');
        Config::set('dynamic_db_failover.health_check.failure_threshold', 3);
        Config::set('dynamic_db_failover.cache.ttl_seconds', 300);
        Config::set('dynamic_db_failover.cache.prefix', 'test_status');
        Config::set('dynamic_db_failover.cache.tag', 'test-tag');

        // Setup dummy database connections for the command's validation logic
        $app['config']->set('database.connections.mysql_primary', [
            'driver' => 'mysql', 'host' => 'localhost', 'database' => 'test_primary', 'username' => 'user', 'password' => 'pass'
        ]);
        $app['config']->set('database.connections.mysql_failover', [
            'driver' => 'mysql', 'host' => 'localhost', 'database' => 'test_failover', 'username' => 'user', 'password' => 'pass'
        ]);
        // Note: 'invalid_connection' or 'non_existent_db' are intentionally not added here
        // to test the command's handling of unconfigured connections.

        // Mock the ConnectionStateManager
        $this->connectionStateManagerMock = \Mockery::mock(ConnectionStateManager::class);
        $app->instance(ConnectionStateManager::class, $this->connectionStateManagerMock);
    }

    public function test_command_updates_status_for_all_connections_by_default(): void
    {
        $this->connectionStateManagerMock
            ->shouldReceive('updateConnectionStatus')
            ->with('mysql_primary')
            ->once();
        $this->connectionStateManagerMock
            ->shouldReceive('getConnectionStatus')
            ->with('mysql_primary')
            ->once()
            ->andReturn(ConnectionStatus::HEALTHY);
        $this->connectionStateManagerMock
            ->shouldReceive('getFailureCount')
            ->with('mysql_primary')
            ->once()
            ->andReturn(0);

        $this->connectionStateManagerMock
            ->shouldReceive('updateConnectionStatus')
            ->with('mysql_failover')
            ->once();
        $this->connectionStateManagerMock
            ->shouldReceive('getConnectionStatus')
            ->with('mysql_failover')
            ->once()
            ->andReturn(ConnectionStatus::HEALTHY);
        $this->connectionStateManagerMock
            ->shouldReceive('getFailureCount')
            ->with('mysql_failover')
            ->once()
            ->andReturn(0);

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

    public function test_command_updates_status_for_specified_connection(): void
    {
        $this->connectionStateManagerMock
            ->shouldReceive('updateConnectionStatus')
            ->with('mysql_primary')
            ->once();
        $this->connectionStateManagerMock
            ->shouldReceive('getConnectionStatus')
            ->with('mysql_primary')
            ->once()
            ->andReturn(ConnectionStatus::DOWN);
        $this->connectionStateManagerMock
            ->shouldReceive('getFailureCount')
            ->with('mysql_primary')
            ->once()
            ->andReturn(1);

        $this->connectionStateManagerMock
            ->shouldNotReceive('updateConnectionStatus')
            ->with('mysql_failover');

        $this->artisan('failover:health-check', ['connection' => 'mysql_primary'])
            ->expectsOutputToContain('Starting database health checks...')
            ->expectsOutputToContain('Performing health check for specific connection: mysql_primary')
            ->expectsOutputToContain('Checking health of connection: mysql_primary...')
            ->expectsOutputToContain("Connection 'mysql_primary' status: DOWN, Failures: 1")
            ->expectsOutputToContain('Database health checks completed.')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_command_handles_attempt_to_check_non_configured_connection_argument(): void
    {
        // This test verifies the command's behavior when trying to check a connection
        // name that is passed as an argument but does not exist in `database.connections`.
        // The command should output an error and return Command::FAILURE.

        $this->connectionStateManagerMock
            ->shouldNotReceive('updateConnectionStatus'); // Should not be called if connection is not configured

        $invalidConnectionName = 'unconfigured_db_connection'; // Use a distinct name for clarity

        $this->artisan('failover:health-check', ['connection' => $invalidConnectionName])
            ->expectsOutputToContain("Connection '{$invalidConnectionName}' is not configured in your database settings.")
            ->assertExitCode(Command::FAILURE);
    }

    public function test_command_skips_unconfigured_connection_name_from_config(): void
    {
        Config::set('dynamic_db_failover.connections.primary', null);
        Config::set('dynamic_db_failover.connections.failover', 'mysql_failover'); // Failover is still configured

        $this->connectionStateManagerMock
            ->shouldNotReceive('updateConnectionStatus')
            ->with(null);
        $this->connectionStateManagerMock
            ->shouldNotReceive('updateConnectionStatus')
            ->with('mysql_primary');

        $this->connectionStateManagerMock
            ->shouldReceive('updateConnectionStatus')
            ->with('mysql_failover')
            ->once();
        $this->connectionStateManagerMock
            ->shouldReceive('getConnectionStatus')
            ->with('mysql_failover')
            ->once()
            ->andReturn(ConnectionStatus::HEALTHY);
        $this->connectionStateManagerMock
            ->shouldReceive('getFailureCount')
            ->with('mysql_failover')
            ->once()
            ->andReturn(0);

        $this->artisan('failover:health-check')
            ->expectsOutputToContain('Starting database health checks...')
            ->expectsOutputToContain('Performing health checks for configured primary and failover connections.')
            ->expectsOutputToContain('Checking health of connection: mysql_failover...')
            ->expectsOutputToContain("Connection 'mysql_failover' status: HEALTHY, Failures: 0")
            ->expectsOutputToContain('Database health checks completed.')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_command_handles_exception_during_health_check(): void
    {
        $this->connectionStateManagerMock
            ->shouldReceive('updateConnectionStatus')
            ->with('mysql_primary')
            ->once()
            ->andThrow(new \RuntimeException('Cache totally unavailable'));

        // It should still try the failover connection
        $this->connectionStateManagerMock
            ->shouldReceive('updateConnectionStatus')
            ->with('mysql_failover')
            ->once();
         $this->connectionStateManagerMock
            ->shouldReceive('getConnectionStatus')
            ->with('mysql_failover')
            ->once()
            ->andReturn(ConnectionStatus::HEALTHY);
        $this->connectionStateManagerMock
            ->shouldReceive('getFailureCount')
            ->with('mysql_failover')
            ->once()
            ->andReturn(0);

        $this->artisan('failover:health-check')
            ->expectsOutputToContain('Starting database health checks...')
            ->expectsOutputToContain('Performing health checks for configured primary and failover connections.')
            ->expectsOutputToContain('Checking health of connection: mysql_primary...')
            ->expectsOutputToContain("Failed to check health for connection 'mysql_primary': Cache totally unavailable")
            ->expectsOutputToContain('Checking health of connection: mysql_failover...')
            ->expectsOutputToContain("Connection 'mysql_failover' status: HEALTHY, Failures: 0")
            ->expectsOutputToContain('Database health checks completed.')
            ->assertExitCode(Command::SUCCESS); // Command itself completes, errors are logged
    }

    public function test_command_warns_if_no_connections_are_configured_at_all(): void
    {
        Config::set('dynamic_db_failover.connections.primary', null);
        Config::set('dynamic_db_failover.connections.failover', null);

        $this->connectionStateManagerMock->shouldNotReceive('updateConnectionStatus');

        $this->artisan('failover:health-check')
            ->expectsOutputToContain('Starting database health checks...')
            ->expectsOutputToContain('Performing health checks for configured primary and failover connections.') // This is output before the check
            ->expectsOutputToContain('No connections configured or specified for health check.')
            ->assertExitCode(Command::SUCCESS); // Command::SUCCESS is returned
    }

    public function test_handle_logs_error_for_invalid_connection_name(): void
    {
        // Ensure that the 'non_existent_db' is indeed not in the app's config for database connections.
        // getEnvironmentSetUp doesn't set this, so it will be missing.

        $this->connectionStateManagerMock
            ->shouldNotReceive('updateConnectionStatus');

        $invalidConnectionName = 'non_existent_db';

        $this->artisan('failover:health-check', ['connection' => $invalidConnectionName])
            ->expectsOutputToContain("Connection '{$invalidConnectionName}' is not configured in your database settings.")
            ->assertExitCode(Command::FAILURE);
    }
}
