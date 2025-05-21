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

/**
 * Unit tests for the {@see ConnectionHealthChecker} class.
 *
 * This test suite verifies the functionality of the connection health checker,
 * ensuring it correctly determines if a database connection is healthy or not
 * under various scenarios, including successful queries, different types of exceptions,
 * and misconfigurations.
 */
class ConnectionHealthCheckerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /**
     * @var \\Mockery\\MockInterface|DatabaseManager Mocked DatabaseManager.
     */
    protected $dbManagerMock;

    /**
     * @var \\Mockery\\MockInterface|ConfigRepository Mocked ConfigRepository.
     */
    protected $configMock;

    /**
     * @var \\Mockery\\MockInterface|Connection Mocked database Connection.
     */
    protected $connectionMock;

    /**
     * Sets up the test environment before each test.
     *
     * Initializes mocks for DatabaseManager, ConfigRepository, and Connection.
     * Configures default behaviors for these mocks.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dbManagerMock = \Mockery::mock(DatabaseManager::class);
        $this->configMock = \Mockery::mock(ConfigRepository::class);
        $this->connectionMock = \Mockery::mock(Connection::class); // This mock will be used for 'mysql' connection by default

        // Default behavior for dbManager: return connectionMock for 'mysql'
        // @phpstan-ignore-next-line
        $this->dbManagerMock->shouldReceive('connection')->with('mysql')->andReturn($this->connectionMock)->byDefault();
        // Allow other connection calls but they might not return the specific $this->connectionMock unless specified in test
        // @phpstan-ignore-next-line
        $this->dbManagerMock->shouldReceive('connection')->withAnyArgs()->andReturnUsing(function($connName) {
            if ($connName === 'mysql') return $this->connectionMock; // Ensure 'mysql' uses the prepared mock
            // For other names, a new generic mock or throw exception if not expected by a specific test
            return \Mockery::mock(Connection::class);
        })->byDefault();


        // Suppress log messages during tests.
        // @phpstan-ignore-next-line
        Log::shouldReceive('debug')->andReturnNull()->byDefault();
        // @phpstan-ignore-next-line
        Log::shouldReceive('warning')->andReturnNull()->byDefault();
        // @phpstan-ignore-next-line
        Log::shouldReceive('error')->andReturnNull()->byDefault();
    }

    /**
     * Cleans up the test environment after each test.
     *
     * Closes Mockery expectations.
     */
    protected function tearDown(): void
    {
        // MockeryPHPUnitIntegration should handle this, but explicit call for safety
        \Mockery::close();
        parent::tearDown();
    }

    /**
     * Helper method to create an instance of ConnectionHealthChecker with mocked dependencies.
     *
     * @return ConnectionHealthChecker
     */
    protected function createHealthChecker(): ConnectionHealthChecker
    {
        return new ConnectionHealthChecker($this->dbManagerMock, $this->configMock);
    }

    /**
     * Tests that `isHealthy` returns true when the database query is successful.
     *
     * @test
     */
    public function test_is_healthy_returns_true_for_successful_query(): void
    {
        // Mock PDO object
        $pdoMock = \Mockery::mock(\PDO::class);

        // Configure config mock
        // @phpstan-ignore-next-line
        $this->configMock->shouldReceive('get')
            ->with('dynamic_db_failover.health_check.query', 'SELECT 1')
            ->andReturn('SELECT 1');
        // @phpstan-ignore-next-line
        $this->configMock->shouldReceive('get')
            ->with('dynamic_db_failover.health_check.timeout_seconds', 2)
            ->andReturn(2);

        // Configure connection mock
        // @phpstan-ignore-next-line
        $this->connectionMock->shouldReceive('getPdo')
            ->once()
            ->andReturn($pdoMock);
        // @phpstan-ignore-next-line
        $this->connectionMock->shouldReceive('unprepared')
            ->with('SELECT 1')
            ->once()
            ->andReturn(true);

        // Configure PDO mock
        // @phpstan-ignore-next-line
        $pdoMock->shouldReceive('getAttribute')
            ->with(\PDO::ATTR_TIMEOUT)
            ->once()
            ->andReturn(60); // Return some default timeout
        // @phpstan-ignore-next-line
        $pdoMock->shouldReceive('setAttribute')
            ->with(\PDO::ATTR_TIMEOUT, 2)
            ->once();
        // @phpstan-ignore-next-line
        $pdoMock->shouldReceive('setAttribute')
            ->with(\PDO::ATTR_TIMEOUT, 60)
            ->once();

        $healthChecker = $this->createHealthChecker();
        $this->assertTrue($healthChecker->isHealthy('mysql'), 'Health check should return true for a healthy connection.');
    }

    /**
     * Tests that `isHealthy` returns false when a PDOException occurs during the query.
     *
     * @test
     */
    public function test_is_healthy_returns_false_on_pdo_exception(): void
    {
        // Mock PDO object
        $pdoMock = \Mockery::mock(\PDO::class);

        // Configure config mock
        // @phpstan-ignore-next-line
        $this->configMock->shouldReceive('get')
            ->with('dynamic_db_failover.health_check.query', 'SELECT 1')
            ->andReturn('SELECT 1');
        // @phpstan-ignore-next-line
        $this->configMock->shouldReceive('get')
            ->with('dynamic_db_failover.health_check.timeout_seconds', 2)
            ->andReturn(2);

        // Configure connection mock
        // @phpstan-ignore-next-line
        $this->connectionMock->shouldReceive('getPdo')
            ->once()
            ->andReturn($pdoMock);
        // @phpstan-ignore-next-line
        $this->connectionMock->shouldReceive('unprepared')
            ->with('SELECT 1')
            ->once()
            ->andThrow(new PDOException('Connection refused'));

        // Configure PDO mock
        // @phpstan-ignore-next-line
        $pdoMock->shouldReceive('getAttribute')
            ->with(\PDO::ATTR_TIMEOUT)
            ->once()
            ->andReturn(60); // Return some default timeout
        // @phpstan-ignore-next-line
        $pdoMock->shouldReceive('setAttribute')
            ->with(\PDO::ATTR_TIMEOUT, 2)
            ->once();
        // @phpstan-ignore-next-line
        $pdoMock->shouldReceive('setAttribute')
            ->with(\PDO::ATTR_TIMEOUT, 60)
            ->once();

        $healthChecker = $this->createHealthChecker();
        $this->assertFalse($healthChecker->isHealthy('mysql'), 'Health check should return false when a PDOException occurs.');
    }

    /**
     * Tests that `isHealthy` returns false when a generic Exception occurs during the query.
     *
     * @test
     */
    public function test_is_healthy_returns_false_on_generic_exception(): void
    {
        // Mock PDO object
        $pdoMock = \Mockery::mock(\PDO::class);

        // Configure config mock
        // @phpstan-ignore-next-line
        $this->configMock->shouldReceive('get')
            ->with('dynamic_db_failover.health_check.query', 'SELECT 1')
            ->andReturn('SELECT 1');
        // @phpstan-ignore-next-line
        $this->configMock->shouldReceive('get')
            ->with('dynamic_db_failover.health_check.timeout_seconds', 2)
            ->andReturn(2);

        // Configure connection mock
        // @phpstan-ignore-next-line
        $this->connectionMock->shouldReceive('getPdo')
            ->once()
            ->andReturn($pdoMock);
        // @phpstan-ignore-next-line
        $this->connectionMock->shouldReceive('unprepared')
            ->with('SELECT 1')
            ->once()
            ->andThrow(new Exception('Something went wrong'));

        // Configure PDO mock
        // @phpstan-ignore-next-line
        $pdoMock->shouldReceive('getAttribute')
            ->with(\PDO::ATTR_TIMEOUT)
            ->once()
            ->andReturn(60); // Return some default timeout
        // @phpstan-ignore-next-line
        $pdoMock->shouldReceive('setAttribute')
            ->with(\PDO::ATTR_TIMEOUT, 2)
            ->once();
        // @phpstan-ignore-next-line
        $pdoMock->shouldReceive('setAttribute')
            ->with(\PDO::ATTR_TIMEOUT, 60)
            ->once();

        $healthChecker = $this->createHealthChecker();
        $this->assertFalse($healthChecker->isHealthy('mysql'), 'Health check should return false when a generic Exception occurs.');
    }

    /**
     * Tests that `isHealthy` uses the health check query specified in the configuration.
     *
     * @test
     */
    public function test_is_healthy_uses_configured_query(): void
    {
        $customQuery = 'SELECT name FROM users LIMIT 1';

        // Mock PDO object
        $pdoMock = \Mockery::mock(\PDO::class);

        // Configure config mock
        // Ensure config mock returns the custom query
        // @phpstan-ignore-next-line
        $this->configMock->shouldReceive('get')
            ->with('dynamic_db_failover.health_check.query', 'SELECT 1')
            ->once()
            ->andReturn($customQuery);
        // @phpstan-ignore-next-line
        $this->configMock->shouldReceive('get')
            ->with('dynamic_db_failover.health_check.timeout_seconds', 2)
            ->andReturn(2);

        // Configure connection mock
        // @phpstan-ignore-next-line
        $this->connectionMock->shouldReceive('getPdo')
            ->once()
            ->andReturn($pdoMock);
        // Ensure the 'mysql' connection (which is $this->connectionMock) expects the custom query
        // @phpstan-ignore-next-line
        $this->connectionMock->shouldReceive('unprepared')
            ->with($customQuery)
            ->once()
            ->andReturn(true);

        // Configure PDO mock
        // @phpstan-ignore-next-line
        $pdoMock->shouldReceive('getAttribute')
            ->with(\PDO::ATTR_TIMEOUT)
            ->once()
            ->andReturn(60); // Return some default timeout
        // @phpstan-ignore-next-line
        $pdoMock->shouldReceive('setAttribute')
            ->with(\PDO::ATTR_TIMEOUT, 2)
            ->once();
        // @phpstan-ignore-next-line
        $pdoMock->shouldReceive('setAttribute')
            ->with(\PDO::ATTR_TIMEOUT, 60)
            ->once();

        $healthChecker = $this->createHealthChecker();
        $this->assertTrue($healthChecker->isHealthy('mysql'), 'Health check should use the configured query.');
    }

    /**
     * Tests that `isHealthy` returns false if the specified database connection cannot be resolved.
     *
     * @test
     */
    public function test_is_healthy_returns_false_if_connection_cannot_be_resolved(): void
    {
        // @phpstan-ignore-next-line
        $this->configMock->shouldReceive('get') // Still need config for the query key, even if connection fails
            ->with('dynamic_db_failover.health_check.query', 'SELECT 1')
            ->andReturn('SELECT 1');
        // @phpstan-ignore-next-line
        $this->configMock->shouldReceive('get')
            ->with('dynamic_db_failover.health_check.timeout_seconds', 2)
            ->andReturn(2);

        // Specifically for 'non_existent_connection', dbManager should throw an exception
        // @phpstan-ignore-next-line
        $this->dbManagerMock->shouldReceive('connection')
            ->with('non_existent_connection')
            ->once()
            ->andThrow(new Exception('Connection [non_existent_connection] not configured.'));

        $healthChecker = $this->createHealthChecker();
        $this->assertFalse($healthChecker->isHealthy('non_existent_connection'), 'Health check should return false if connection cannot be resolved.');
    }
}
