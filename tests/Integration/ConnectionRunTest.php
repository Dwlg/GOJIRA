<?php

namespace Gojira\Tests\Integration;

use Gojira\Tests\Support\IntegrationTestCase;

/**
 * Connection::run, runAll e runGet (prepared statements) contra banco real.
 */
final class ConnectionRunTest extends IntegrationTestCase
{
    private function itemTable(): string
    {
        $table = $this->createTable(
            'id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50) NULL, qty INT NOT NULL DEFAULT 0, price DECIMAL(6,2) NULL'
        );
        self::$connection->execute(
            'INSERT INTO `' . $table . '` (name, qty, price) VALUES ("a", 1, 1.50), ("b", 5, 2.00), (NULL, 3, NULL)'
        );

        return $table;
    }

    /**
     * O tipo do bind é inferido do valor PHP: int vira "i", float vira "d",
     * o resto vira "s". O resultado da comparação abaixo depende do tipo:
     * como número, 10 = '1e1'; como texto, '10' <> '1e1'.
     */
    public function testIntegerIsBoundAsNumberAndStringAsText(): void
    {
        $sql = "SELECT (? = '1e1') AS eq";

        self::assertEquals(1, self::$connection->runGet($sql, [10])['eq']);
        self::assertEquals(0, self::$connection->runGet($sql, ['10'])['eq']);
    }

    public function testFloatIsBoundAsDouble(): void
    {
        $row = self::$connection->runGet('SELECT (? * 2) AS v', [1.25]);

        self::assertEquals(2.5, (float) $row['v']);
    }

    public function testFloatKeepsFractionWhenStored(): void
    {
        $table = $this->itemTable();

        self::assertTrue(self::$connection->run('UPDATE `' . $table . '` SET price = ? WHERE name = ?', [3.75, 'a']));

        $row = self::$connection->runGet('SELECT price FROM `' . $table . '` WHERE name = ?', ['a']);
        self::assertSame('3.75', (string) $row['price']);
    }

    public function testRunAllReturnsEveryRowAsAssociativeArray(): void
    {
        $table = $this->itemTable();

        $rows = self::$connection->runAll('SELECT name, qty FROM `' . $table . '` WHERE qty >= ? ORDER BY qty', [3]);

        self::assertCount(2, $rows);
        self::assertNull($rows[0]['name']);
        self::assertEquals(3, $rows[0]['qty']);
        self::assertSame('b', $rows[1]['name']);
    }

    public function testRunAllWithoutParamsAndWithNumericFetchMode(): void
    {
        $table = $this->itemTable();

        $rows = self::$connection->runAll('SELECT name FROM `' . $table . '` ORDER BY id', [], MYSQLI_NUM);

        self::assertSame([['a'], ['b'], [null]], $rows);
    }

    public function testRunAllWithoutMatchReturnsEmptyArray(): void
    {
        $table = $this->itemTable();

        self::assertSame([], self::$connection->runAll('SELECT name FROM `' . $table . '` WHERE qty > ?', [100]));
    }

    public function testRunGetReturnsFirstRow(): void
    {
        $table = $this->itemTable();

        $row = self::$connection->runGet('SELECT name FROM `' . $table . '` WHERE qty > ? ORDER BY qty DESC', [0]);

        self::assertSame(['name' => 'b'], $row);
    }

    public function testRunGetWithoutMatchReturnsNull(): void
    {
        $table = $this->itemTable();

        self::assertNull(self::$connection->runGet('SELECT name FROM `' . $table . '` WHERE name = ?', ['nenhum']));
    }

    public function testRunInsertsAndRecordsLastCommand(): void
    {
        $table = $this->itemTable();
        $sql   = 'INSERT INTO `' . $table . '` (name, qty) VALUES (?, ?)';

        self::assertTrue(self::$connection->run($sql, ['c', 7]));
        self::assertSame($sql, self::$connection->get_lastCommand());
        self::assertEquals(7, self::$connection->runGet('SELECT qty FROM `' . $table . '` WHERE name = ?', ['c'])['qty']);
    }

    public function testNullIsBoundAsSqlNull(): void
    {
        $table = $this->itemTable();

        self::assertTrue(self::$connection->run('INSERT INTO `' . $table . '` (name, qty) VALUES (?, ?)', [null, 9]));

        $row = self::$connection->runGet('SELECT name FROM `' . $table . '` WHERE qty = ?', [9]);
        self::assertNull($row['name']);
    }

    /** @return array<string, array{string}> */
    public function literalValues(): array
    {
        return [
            'aspas simples'   => ["o'neil"],
            'aspas duplas'    => ['o"neil'],
            'barra invertida' => ['a\\b'],
            'comentário'      => ['x -- y'],
            'ponto e vírgula' => ['x; y'],
        ];
    }

    /**
     * O valor vai por bind, nunca concatenado: chega ao banco exatamente como foi passado.
     *
     * @dataProvider literalValues
     */
    public function testBoundStringIsStoredAndMatchedLiterally(string $value): void
    {
        $table = $this->itemTable();

        self::assertTrue(self::$connection->run('INSERT INTO `' . $table . '` (name, qty) VALUES (?, ?)', [$value, 42]));

        $row = self::$connection->runGet('SELECT name, qty FROM `' . $table . '` WHERE name = ?', [$value]);
        self::assertSame($value, $row['name']);
        self::assertEquals(42, $row['qty']);
        self::assertSame(4, (int) self::$connection->runGet('SELECT COUNT(*) AS n FROM `' . $table . '`')['n']);
    }

    /** @return array<string, array{string, array<int, mixed>}> */
    public function invalidStatements(): array
    {
        return [
            'sintaxe inválida'   => ['SELEC 1', []],
            'tabela inexistente' => ['SELECT * FROM gj_test_nao_existe WHERE id = ?', [1]],
        ];
    }

    /**
     * @dataProvider invalidStatements
     * @param array<int, mixed> $params
     */
    public function testInvalidSqlThrowsInRunAllAndRunGet(string $sql, array $params): void
    {
        foreach (['runAll', 'runGet'] as $method) {
            try {
                self::$connection->$method($sql, $params);
                self::fail($method . ' deveria lançar exceção para SQL inválido');
            } catch (\Exception $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    /**
     * @dataProvider invalidStatements
     * @param array<int, mixed> $params
     */
    public function testInvalidSqlThrowsInRun(string $sql, array $params): void
    {
        $this->expectException(\Exception::class);
        self::$connection->run($sql, $params);
    }

    public function testConnectionStillUsableAfterInvalidSql(): void
    {
        try {
            self::$connection->runGet('SELEC 1');
        } catch (\Exception $e) {
            // esperado
        }

        self::assertEquals(1, self::$connection->runGet('SELECT ? AS v', [1])['v']);
    }
}
