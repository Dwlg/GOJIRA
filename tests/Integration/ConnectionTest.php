<?php

namespace Gojira\Tests\Integration;

use Gojira\Tests\Support\IntegrationTestCase;

/**
 * Caracterização da Connection contra MySQL/MariaDB real.
 */
final class ConnectionTest extends IntegrationTestCase
{
    private function itemTable(): string
    {
        $table = $this->createTable('id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50) NULL, qty INT NOT NULL DEFAULT 0');
        self::$connection->execute('INSERT INTO `' . $table . '` (name, qty) VALUES ("a", 1), ("b", 5), (NULL, 3)');

        return $table;
    }

    public function testIsConnectedAfterConnect(): void
    {
        self::assertTrue(self::$connection->isConnected());
        self::assertSame(getenv('GOJIRA_TEST_DB_NAME'), self::$connection->database);
    }

    public function testConnectSetsThreeByteUtf8AndBrazilianTimeNames(): void
    {
        $row = self::$connection->fetch(self::$connection->query(
            'SELECT @@character_set_client AS client, @@character_set_connection AS conn, '
            . '@@character_set_results AS results, @@lc_time_names AS lc'
        ));

        self::assertContains($row['client'], ['utf8', 'utf8mb3']);
        self::assertContains($row['conn'], ['utf8', 'utf8mb3']);
        self::assertContains($row['results'], ['utf8', 'utf8mb3']);
        self::assertSame('pt_BR', $row['lc']);
    }

    public function testQueryFetchAndFetchAll(): void
    {
        $table = $this->itemTable();

        $rows = self::$connection->fetchAll(self::$connection->query('SELECT name, qty FROM `' . $table . '` ORDER BY id'));
        self::assertSame('a', $rows[0]['name']);
        self::assertSame(3, count($rows));
        self::assertEquals(5, $rows[1]['qty']);

        $object = self::$connection->fetch(self::$connection->query('SELECT name FROM `' . $table . '` ORDER BY id'), \Connection::FETCH_OBJECT);
        self::assertSame('a', $object->name);
    }

    public function testCountAcceptsSqlStringOrResult(): void
    {
        $table = $this->itemTable();

        self::assertEquals(2, self::$connection->count('SELECT 1 FROM `' . $table . '` WHERE qty > 1'));
        self::assertSame(3, self::$connection->count(self::$connection->query('SELECT 1 FROM `' . $table . '`')));
        self::assertFalse(self::$connection->count(null));
    }

    public function testMaxAndMin(): void
    {
        $table = $this->itemTable();

        self::assertEquals(5, self::$connection->max($table, 'qty'));
        self::assertEquals(1, self::$connection->min($table, 'qty'));
    }

    public function testExist(): void
    {
        $table = $this->itemTable();

        self::assertTrue(self::$connection->exist($table, 'name', 'b'));
        self::assertFalse(self::$connection->exist($table, 'name', 'z'));
        self::assertTrue(self::$connection->exist($table, 'name', null), 'null vira IS NULL');
        self::assertFalse(self::$connection->exist($table, 'name', 'NULL'), 'a string "NULL" é comparada como texto');
        self::$connection->execute('INSERT INTO `' . $table . '` (name, qty) VALUES ("null", 9)');
        self::assertTrue(self::$connection->exist($table, 'name', 'null'));
        self::assertTrue(self::$connection->exist($table, 'name', 'null', 'qty = 9'));
        self::assertTrue(self::$connection->exist($table, '', '', 'qty = 5'), 'compare é anexado como SQL');
        self::assertTrue(self::$connection->exist($table, 'name', 'a', 'qty = 1'));
        self::assertFalse(self::$connection->exist($table, 'name', 'a', 'qty = 2'));
    }

    public function testGetValue(): void
    {
        $table = $this->itemTable();

        self::assertEquals(['a', 'b'], self::$connection->getValue($table, 'name', 'qty <> 3 ORDER BY id'));
        self::assertEquals(['a'], self::$connection->getValue($table, 'name', 'qty <> 3 ORDER BY id', 1));
        self::assertSame([], self::$connection->getValue($table, 'name', 'qty > 100'));
    }

    public function testTableExistAndFieldExist(): void
    {
        $table = $this->itemTable();

        self::assertTrue(self::$connection->tableExist($table));
        self::assertFalse(self::$connection->tableExist($table . '_missing'));
        self::assertTrue(self::$connection->fieldExist($table, 'qty'));
        self::assertFalse(self::$connection->fieldExist($table, 'missing'));
    }

    public function testInvalidQueryThrows(): void
    {
        $this->expectException(\Exception::class);

        self::$connection->query('SELECT FROM nowhere');
    }

    public function testInvalidExecuteDependsOnMysqliReportMode(): void
    {
        // A partir do PHP 8.1 o padrão do mysqli é lançar exceção; no 8.0, prepare() devolve false.
        if (PHP_VERSION_ID < 80100) {
            self::assertFalse(@self::$connection->execute('NOT VALID SQL'));
            return;
        }

        $this->expectException(\mysqli_sql_exception::class);
        self::$connection->execute('NOT VALID SQL');
    }

    public function testSecureExecCommitsAllStatements(): void
    {
        $table = $this->itemTable();

        self::assertTrue(self::$connection->secureExec([
            'UPDATE `' . $table . '` SET qty = 10 WHERE name = "a"',
            'UPDATE `' . $table . '` SET qty = 20 WHERE name = "b"',
        ]));
        self::assertEquals(['10', '20'], self::$connection->getValue($table, 'qty', 'name IS NOT NULL ORDER BY id'));
    }

    public function testSecureExecRollsBackWhenAStatementFails(): void
    {
        $table = $this->itemTable();

        self::assertFalse(@self::$connection->secureExec([
            'UPDATE `' . $table . '` SET qty = 10 WHERE name = "a"',
            'NOT VALID SQL',
        ]));
        self::assertEquals(['1'], self::$connection->getValue($table, 'qty', 'name = "a"'));
    }
}
