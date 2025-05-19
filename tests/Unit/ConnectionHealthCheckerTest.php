<?php

namespace Nuxgame\LaravelDynamicDBFailover\Tests\Unit;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Nuxgame\LaravelDynamicDBFailover\HealthCheck\ConnectionHealthChecker;
use PHPUnit\Framework\TestCase;
use Illuminate\Support\Facades\Log;
use PDOException;
use Exception;

class ConnectionHealthCheckerTest extends TestCase
{
    use MockeryPHPUnitIntegration; // Trait for Mockery integration with PHPUnit

    protected $dbManagerMock;
    protected $configMock;
    protected $connectionMock;
    protected ConnectionHealthChecker $healthChecker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbManagerMock = \Mockery::mock(DatabaseManager::class);
        $this->configMock = \Mockery::mock(ConfigRepository::class);
        $this->connectionMock = \Mockery::mock(Connection::class);

        // Setup default config mock behavior
        $this->configMock->shouldReceive('get')
            ->with('dynamic_db_failover.health_check.query', 'SELECT 1')
            ->andReturn('SELECT 1');

        // Setup default dbManager mock behavior
        $this->dbManagerMock->shouldReceive('connection')
            ->andReturn($this->connectionMock);

        // Mock Log facade to prevent actual logging during tests
        // We can assert against these mocks if needed, but for now, just swallow logs.
        Log::shouldReceive('debug')->andReturnNull();
        Log::shouldReceive('warning')->andReturnNull();
        Log::shouldReceive('error')->andReturnNull();

        $this->healthChecker = new ConnectionHealthChecker($this->dbManagerMock, $this->configMock);
    }

    public function test_is_healthy_returns_true_for_successful_query(): void
    {
        $this->connectionMock->shouldReceive('unprepared')
            ->with('SELECT 1')
            ->once()
            ->andReturn(true);

        $this->assertTrue($this->healthChecker->isHealthy('mysql'));
    }

    public function test_is_healthy_returns_false_on_pdo_exception(): void
    {
        $this->connectionMock->shouldReceive('unprepared')
            ->with('SELECT 1')
            ->once()
            ->andThrow(new PDOException('Connection refused'));

        $this->assertFalse($this->healthChecker->isHealthy('mysql'));
    }

    public function test_is_healthy_returns_false_on_generic_exception(): void
    {
        $this->connectionMock->shouldReceive('unprepared')
            ->with('SELECT 1')
            ->once()
            ->andThrow(new Exception('Something went wrong'));

        $this->assertFalse($this->healthChecker->isHealthy('mysql'));
    }

    public function test_is_healthy_uses_configured_query(): void
    {
        $customQuery = 'SELECT name FROM users LIMIT 1';
        $this->configMock->shouldReceive('get') // Re-define for this specific test
            ->with('dynamic_db_failover.health_check.query', 'SELECT 1')
            ->andReturn($customQuery);

        $this->connectionMock->shouldReceive('unprepared')
            ->with($customQuery)
            ->once()
            ->andReturn(true);

        // Re-initialize with potentially modified mocks if needed, or ensure setUp covers this flexibility.
        // In this case, re-asserting the mock expectation for config is enough before creating new instance or running.
        // For simplicity, assuming the config mock is fresh or re-asserted as above.
        $healthChecker = new ConnectionHealthChecker($this->dbManagerMock, $this->configMock); // Re-init if config has changed for this test

        $this->assertTrue($healthChecker->isHealthy('mysql'));
    }

    public function test_is_healthy_returns_false_if_connection_cannot_be_resolved(): void
    {
        $this->dbManagerMock->shouldReceive('connection')
            ->with('non_existent_connection')
            ->once()
            ->andThrow(new Exception('Connection not configured'));

        $this->assertFalse($this->healthChecker->isHealthy('non_existent_connection'));
    }

    // It's good practice to tear down Mockery expectations after each test if not using the trait that handles it.
    // However, MockeryPHPUnitIntegration handles Mockery::close() automatically.
}
