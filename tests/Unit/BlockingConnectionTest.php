<?php

namespace Nuxgame\LaravelDynamicDBFailover\Tests\Unit;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Nuxgame\LaravelDynamicDBFailover\Database\BlockingConnection;
use Nuxgame\LaravelDynamicDBFailover\Exceptions\AllDatabaseConnectionsUnavailableException;
use PDO;
use PHPUnit\Framework\TestCase;

class BlockingConnectionTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function createConnection(): BlockingConnection
    {
        $pdoMock = \Mockery::mock(PDO::class); // PDO or Closure that returns PDO
        $config = [
            'driver' => 'blocking',
            'name' => 'blocking_test',
            'database' => 'test_db',
            'prefix' => '',
        ];
        return new BlockingConnection($pdoMock, $config['database'], $config['prefix'], $config);
    }

    public function test_select_one_throws_exception(): void
    {
        $this->expectException(AllDatabaseConnectionsUnavailableException::class);
        $connection = $this->createConnection();
        $connection->selectOne('SELECT 1');
    }

    public function test_select_throws_exception(): void
    {
        $this->expectException(AllDatabaseConnectionsUnavailableException::class);
        $connection = $this->createConnection();
        $connection->select('SELECT 1');
    }

    public function test_insert_throws_exception(): void
    {
        $this->expectException(AllDatabaseConnectionsUnavailableException::class);
        $connection = $this->createConnection();
        $connection->insert('INSERT INTO test (id) VALUES (1)');
    }

    public function test_update_throws_exception(): void
    {
        $this->expectException(AllDatabaseConnectionsUnavailableException::class);
        $connection = $this->createConnection();
        $connection->update('UPDATE test SET name = \'test\' WHERE id = 1');
    }

    public function test_delete_throws_exception(): void
    {
        $this->expectException(AllDatabaseConnectionsUnavailableException::class);
        $connection = $this->createConnection();
        $connection->delete('DELETE FROM test WHERE id = 1');
    }

    public function test_statement_throws_exception(): void
    {
        $this->expectException(AllDatabaseConnectionsUnavailableException::class);
        $connection = $this->createConnection();
        $connection->statement('ALTER TABLE test ADD COLUMN email VARCHAR(255)');
    }

    public function test_affecting_statement_throws_exception(): void
    {
        $this->expectException(AllDatabaseConnectionsUnavailableException::class);
        $connection = $this->createConnection();
        $connection->affectingStatement('UPDATE test SET name = \'foo\'');
    }

    public function test_unprepared_throws_exception(): void
    {
        $this->expectException(AllDatabaseConnectionsUnavailableException::class);
        $connection = $this->createConnection();
        $connection->unprepared('SELECT * FROM users');
    }

    public function test_pretend_does_not_throw_exception_and_returns_array(): void
    {
        $connection = $this->createConnection();
        $result = $connection->pretend(function() {
            // This closure shouldn't be executed in a real scenario
            // but pretend() itself should return an empty array.
        });
        $this->assertEquals([], $result);
    }
}
