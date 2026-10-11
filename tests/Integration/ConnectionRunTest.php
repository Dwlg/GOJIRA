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

    public function testRunReportsAffectedRows(): void
    {
        $table = $this->itemTable();

        self::assertTrue(self::$connection->run('UPDATE `' . $table . '` SET qty = qty + 1 WHERE qty < ?', [4]));
        self::assertSame(2, self::$connection->affectedRows());

        self::assertTrue(self::$connection->run('UPDATE `' . $table . '` SET qty = 0 WHERE name = ?', ['nenhum']));
        self::assertSame(0, self::$connection->affectedRows());
    }

    /** @var string|null */
    private $errorLogFile;

    /** @var string|false */
    private $previousErrorLog = false;

    protected function setUp(): void
    {
        $this->errorLogFile     = (string) tempnam(sys_get_temp_dir(), 'gjlog');
        $this->previousErrorLog = ini_set('error_log', $this->errorLogFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->previousErrorLog);
        @unlink((string) $this->errorLogFile);
        parent::tearDown();
    }

    private function errorLog(): string
    {
        return (string) file_get_contents((string) $this->errorLogFile);
    }

    /**
     * Toda falha de run/runAll/runGet é a mesma ConnectionException, com
     * mensagem genérica, em qualquer versão do PHP. O detalhe vai para o log.
     *
     * @param array<int, mixed> $params
     */
    private function assertUniformFailure(string $method, string $sql, array $params, string $secret): void
    {
        try {
            self::$connection->$method($sql, $params);
            self::fail($method . ' deveria lançar ConnectionException');
        } catch (\ConnectionException $e) {
            self::assertSame(DB_CONNECTION_INVALID_SQL, $e->getMessage());
            self::assertSame(DB_CONNECTION_ERR_BOUND, $e->getCode());
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsString($secret, $e->getMessage());
        }

        // runGet delega para runAll
        self::assertStringContainsString('Connection::' . ($method === 'runGet' ? 'runAll' : $method) . ' falhou', $this->errorLog());
    }

    /** @return array<string, array{string, array<int, mixed>, string}> */
    public function invalidStatements(): array
    {
        return [
            'sintaxe inválida'          => ['SELEC 1', [], 'SELEC'],
            'tabela inexistente'        => ['SELECT * FROM gj_test_nao_existe WHERE id = ?', [1], 'gj_test_nao_existe'],
            'parâmetros a menos'        => ['SELECT ? AS a, ? AS b', [1], 'SELECT'],
            'parâmetros a mais'         => ['SELECT ? AS a', [1, 2], 'SELECT'],
            'placeholder sem parâmetro' => ['SELECT ? AS a', [], 'SELECT'],
        ];
    }

    /**
     * @dataProvider invalidStatements
     * @param array<int, mixed> $params
     */
    public function testInvalidStatementFailsUniformly(string $sql, array $params, string $secret): void
    {
        foreach (['run', 'runAll', 'runGet'] as $method) {
            $this->assertUniformFailure($method, $sql, $params, $secret);
        }
        self::assertStringContainsString($sql, $this->errorLog());
    }

    /** Erro na execução (chave duplicada): exceção igual no PHP 8.0 e 8.1+, valor fora da mensagem e do log. */
    public function testExecutionErrorFailsUniformlyWithoutLeakingValues(): void
    {
        $table = $this->createTable('id INT PRIMARY KEY, name VARCHAR(50) NOT NULL');
        self::$connection->run('INSERT INTO `' . $table . '` (id, name) VALUES (?, ?)', [1, 'a']);

        $this->assertUniformFailure('run', 'INSERT INTO `' . $table . '` (id, name) VALUES (?, ?)', [1, 'marcador-valor'], 'marcador-valor');
        self::assertStringNotContainsString('marcador-valor', $this->errorLog());
    }

    public function testExceptionIsAFrameworkException(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage(DB_CONNECTION_INVALID_SQL);
        self::$connection->runGet('SELEC 1');
    }

    public function testConnectionStillUsableAfterInvalidSql(): void
    {
        try {
            self::$connection->runGet('SELEC 1');
        } catch (\ConnectionException $e) {
            // esperado
        }

        self::assertEquals(1, self::$connection->runGet('SELECT ? AS v', [1])['v']);
    }
}
