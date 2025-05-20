<?php

namespace Nuxgame\LaravelDynamicDBFailover\Tests\Unit;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Nuxgame\LaravelDynamicDBFailover\HealthCheck\ConnectionHealthChecker;
use Orchestra\Testbench\TestCase;
use Illuminate\Support\Facades\Log;
use PDOException;
use Exception;

class ConnectionHealthCheckerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected $dbManagerMock;
    protected $configMock;
    protected $connectionMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbManagerMock = \Mockery::mock(DatabaseManager::class);
        $this->configMock = \Mockery::mock(ConfigRepository::class);
        $this->connectionMock = \Mockery::mock(Connection::class); // This mock will be used for 'mysql' connection by default

        // Default behavior for dbManager: return connectionMock for 'mysql'
        $this->dbManagerMock->shouldReceive('connection')->with('mysql')->andReturn($this->connectionMock)->byDefault();
        // Allow other connection calls but they might not return the specific $this->connectionMock unless specified in test
        $this->dbManagerMock->shouldReceive('connection')->withAnyArgs()->andReturnUsing(function($connName) {
            if ($connName === 'mysql') return $this->connectionMock; // Ensure 'mysql' uses the prepared mock
            // For other names, a new generic mock or throw exception if not expected by a specific test
            return \Mockery::mock(Connection::class);
        })->byDefault();


        Log::shouldReceive('debug')->andReturnNull()->byDefault();
        Log::shouldReceive('warning')->andReturnNull()->byDefault();
        Log::shouldReceive('error')->andReturnNull()->byDefault();
    }

    protected function tearDown(): void
    {
        // MockeryPHPUnitIntegration should handle this, but explicit call for safety
        \Mockery::close();
        parent::tearDown();
    }

    protected function createHealthChecker(): ConnectionHealthChecker
    {
        return new ConnectionHealthChecker($this->dbManagerMock, $this->configMock);
    }

    public function test_is_healthy_returns_true_for_successful_query(): void
    {
        $this->configMock->shouldReceive('get')
            ->with('dynamic_db_failover.health_check.query', 'SELECT 1')
            ->andReturn('SELECT 1');

        $this->connectionMock->shouldReceive('unprepared')
            ->with('SELECT 1')
            ->once()
            ->andReturn(true);

        $healthChecker = $this->createHealthChecker();
        $this->assertTrue($healthChecker->isHealthy('mysql'));
    }

    public function test_is_healthy_returns_false_on_pdo_exception(): void
    {
        $this->configMock->shouldReceive('get')
            ->with('dynamic_db_failover.health_check.query', 'SELECT 1')
            ->andReturn('SELECT 1');

        $this->connectionMock->shouldReceive('unprepared')
            ->with('SELECT 1')
            ->once()
            ->andThrow(new PDOException('Connection refused'));

        $healthChecker = $this->createHealthChecker();
        $this->assertFalse($healthChecker->isHealthy('mysql'));
    }

    public function test_is_healthy_returns_false_on_generic_exception(): void
    {
        $this->configMock->shouldReceive('get')
            ->with('dynamic_db_failover.health_check.query', 'SELECT 1')
            ->andReturn('SELECT 1');

        $this->connectionMock->shouldReceive('unprepared')
            ->with('SELECT 1')
            ->once()
            ->andThrow(new Exception('Something went wrong'));

        $healthChecker = $this->createHealthChecker();
        $this->assertFalse($healthChecker->isHealthy('mysql'));
    }

    public function test_is_healthy_uses_configured_query(): void
    {
        $customQuery = 'SELECT name FROM users LIMIT 1';
        // Ensure config mock returns the custom query
        $this->configMock->shouldReceive('get')
            ->with('dynamic_db_failover.health_check.query', 'SELECT 1')
            ->once()
            ->andReturn($customQuery);

        // Ensure the 'mysql' connection (which is $this->connectionMock) expects the custom query
        $this->connectionMock->shouldReceive('unprepared')
            ->with($customQuery)
            ->once()
            ->andReturn(true);

        $healthChecker = $this->createHealthChecker();
        $this->assertTrue($healthChecker->isHealthy('mysql'));
    }

    public function test_is_healthy_returns_false_if_connection_cannot_be_resolved(): void
    {
         $this->configMock->shouldReceive('get') // Still need config for the query key, even if connection fails
            ->with('dynamic_db_failover.health_check.query', 'SELECT 1')
            ->andReturn('SELECT 1');

        // Specifically for 'non_existent_connection', dbManager should throw an exception
        $this->dbManagerMock->shouldReceive('connection')
            ->with('non_existent_connection')
            ->once()
            ->andThrow(new Exception('Connection [non_existent_connection] not configured.'));

        $healthChecker = $this->createHealthChecker();
        $this->assertFalse($healthChecker->isHealthy('non_existent_connection'));
    }
}
