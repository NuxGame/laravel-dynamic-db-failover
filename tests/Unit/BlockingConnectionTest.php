<?php

namespace Nuxgame\LaravelDynamicDBFailover\Tests\Unit;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Nuxgame\LaravelDynamicDBFailover\Database\BlockingConnection;
use Nuxgame\LaravelDynamicDBFailover\Exceptions\AllDatabaseConnectionsUnavailableException;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Class BlockingConnectionTest
 *
 * Unit tests for the Nuxgame\LaravelDynamicDBFailover\Database\BlockingConnection class.
 * These tests ensure that the BlockingConnection correctly throws
 * AllDatabaseConnectionsUnavailableException for all relevant database operations.
 */
class BlockingConnectionTest extends TestCase
{
    use MockeryPHPUnitIntegration; // Integrates Mockery with PHPUnit for better mock management.

    /**
     * Helper method to create a new instance of BlockingConnection with a dummy PDO mock and configuration.
     *
     * @return BlockingConnection An instance of the BlockingConnection.
     */
    protected function createConnection(): BlockingConnection
    {
        $pdoMock = \Mockery::mock(PDO::class); // Mock the PDO dependency, though it's not used by BlockingConnection.
        $config = [
            'driver' => 'blocking', // Specify the driver type.
            'name' => 'blocking_test', // Connection name for testing.
            'database' => 'test_db', // Dummy database name.
            'prefix' => '', // No table prefix.
        ];
        return new BlockingConnection($pdoMock, $config['database'], $config['prefix'], $config);
    }

    /**
     * Tests that calling the selectOne() method on BlockingConnection throws AllDatabaseConnectionsUnavailableException.
     * @test
     */
    public function test_select_one_throws_exception(): void
    {
        $this->expectException(AllDatabaseConnectionsUnavailableException::class);
        $connection = $this->createConnection();
        $connection->selectOne('SELECT 1'); // Attempt a selectOne operation.
    }

    /**
     * Tests that calling the select() method on BlockingConnection throws AllDatabaseConnectionsUnavailableException.
     * @test
     */
    public function test_select_throws_exception(): void
    {
        $this->expectException(AllDatabaseConnectionsUnavailableException::class);
        $connection = $this->createConnection();
        $connection->select('SELECT 1'); // Attempt a select operation.
    }

    /**
     * Tests that calling the insert() method on BlockingConnection throws AllDatabaseConnectionsUnavailableException.
     * @test
     */
    public function test_insert_throws_exception(): void
    {
        $this->expectException(AllDatabaseConnectionsUnavailableException::class);
        $connection = $this->createConnection();
        $connection->insert('INSERT INTO test (id) VALUES (1)'); // Attempt an insert operation.
    }

    /**
     * Tests that calling the update() method on BlockingConnection throws AllDatabaseConnectionsUnavailableException.
     * @test
     */
    public function test_update_throws_exception(): void
    {
        $this->expectException(AllDatabaseConnectionsUnavailableException::class);
        $connection = $this->createConnection();
        $connection->update('UPDATE test SET name = \'test\' WHERE id = 1'); // Attempt an update operation.
    }

    /**
     * Tests that calling the delete() method on BlockingConnection throws AllDatabaseConnectionsUnavailableException.
     * @test
     */
    public function test_delete_throws_exception(): void
    {
        $this->expectException(AllDatabaseConnectionsUnavailableException::class);
        $connection = $this->createConnection();
        $connection->delete('DELETE FROM test WHERE id = 1'); // Attempt a delete operation.
    }

    /**
     * Tests that calling the statement() method on BlockingConnection throws AllDatabaseConnectionsUnavailableException.
     * @test
     */
    public function test_statement_throws_exception(): void
    {
        $this->expectException(AllDatabaseConnectionsUnavailableException::class);
        $connection = $this->createConnection();
        $connection->statement('ALTER TABLE test ADD COLUMN email VARCHAR(255)'); // Attempt a DDL statement.
    }

    /**
     * Tests that calling the affectingStatement() method on BlockingConnection throws AllDatabaseConnectionsUnavailableException.
     * @test
     */
    public function test_affecting_statement_throws_exception(): void
    {
        $this->expectException(AllDatabaseConnectionsUnavailableException::class);
        $connection = $this->createConnection();
        $connection->affectingStatement('UPDATE test SET name = \'foo\''); // Attempt an affecting statement.
    }

    /**
     * Tests that calling the unprepared() method on BlockingConnection throws AllDatabaseConnectionsUnavailableException.
     * @test
     */
    public function test_unprepared_throws_exception(): void
    {
        $this->expectException(AllDatabaseConnectionsUnavailableException::class);
        $connection = $this->createConnection();
        $connection->unprepared('SELECT * FROM users'); // Attempt an unprepared query.
    }

    /**
     * Tests that the pretend() method on BlockingConnection does not throw an exception and returns an empty array.
     * The pretend() method is typically used for dry runs and should not attempt a real connection.
     * @test
     */
    public function test_pretend_does_not_throw_exception_and_returns_array(): void
    {
        $connection = $this->createConnection();
        // The pretend method should execute the closure and return its log, or an empty array if no queries are logged.
        // For BlockingConnection, since it doesn't execute queries, it should simply return the log (empty array).
        $result = $connection->pretend(function($connection) {
            // This closure would normally contain database operations to be logged.
            // For this test, it can be empty as BlockingConnection overrides query methods.
            // $connection->select('SELECT 1'); // Example of what might be here
        });
        $this->assertEquals([], $result, "The pretend method should return an empty array for BlockingConnection.");
    }

    /**
     * Clean up after each test, ensuring Mockery expectations are verified.
     */
    protected function tearDown(): void
    {
        // Ensures all Mockery expectations set during the test are verified.
        \Mockery::close();
        // Call parent tearDown for any cleanup defined in PHPUnit\Framework\TestCase.
        parent::tearDown();
    }
}
