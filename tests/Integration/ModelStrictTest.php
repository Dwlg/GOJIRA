<?php

namespace Gojira\Tests\Integration;

use Gojira\Tests\Support\Deprecations;
use Gojira\Tests\Support\IntegrationTestCase;

/**
 * Modo estrito contra MySQL/MariaDB real: a entrada típica de injeção é
 * recusada antes de chegar ao banco, e o SQL explícito (Model::raw /
 * Gojira_Raw, com bind) continua funcionando com valores difíceis.
 */
final class ModelStrictTest extends IntegrationTestCase
{
    use Deprecations;

    protected function setUp(): void
    {
        $this->resetModelDeprecations();
    }

    private function people(): \Fixture_Model
    {
        $model = $this->model($this->createTable(
            'id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50) NOT NULL, status INT NOT NULL DEFAULT 0, created DATETIME NULL'
        ));
        foreach ([['Ana', 1], ['Bia', 0], ["o'neil", 1]] as [$name, $status]) {
            $model->saveRow(['name' => $name, 'status' => $status]);
        }

        return $model;
    }

    private function assertRefused(callable $fn): void
    {
        try {
            $fn();
        } catch (\ModelException $e) {
            self::assertSame(0x201A, $e->getCode(), $e->getMessage());
            return;
        }
        self::fail('Esperava ModelException 0x201A');
    }

    /** Uma chave vinda do usuário com uma tautologia: o legado devolve tudo; o estrito recusa. */
    public function testRequestKeyWithTautologyReadsEverythingInLegacyAndIsRefusedInStrict(): void
    {
        $model = $this->people();
        $input = '0 OR 1=1';

        $this->captureDeprecations();
        self::assertCount(3, $model->getData($input), 'legado: a string vira WHERE');
        $this->assertDeprecated('raw SQL string as key');

        \Model::setStrict(true);
        $this->assertRefused(function () use ($model, $input) {
            $model->getData($input);
        });
        $this->assertRefused(function () use ($model, $input) {
            $model->delete($input);
        });
        self::assertEquals(3, $model->countData(), 'nada foi apagado');

        self::assertSame([], $model->getData('0'), 'string numérica continua sendo a chave primária');
        self::assertSame('Ana', $model->getData(['name' => 'Ana'])[0]->name);
    }

    public function testFunctionValueRunsInLegacyAndIsRefusedInStrict(): void
    {
        $model = $this->people();
        $table = $model->get_table();

        $this->captureDeprecations();
        $model->saveRow(['name' => "LOWER('MARK')", 'status' => 0]);
        self::assertSame(['mark'], self::$connection->getValue($table, 'name', ['id' => 4]), 'legado: a função foi executada');
        $this->assertDeprecated('SQL function with arguments');

        \Model::setStrict(true);
        $this->assertRefused(function () use ($model) {
            $model->saveRow(['name' => "LOWER('MARK2')", 'status' => 0]);
        });
        self::assertEquals(4, $model->countData(), 'nada foi gravado');

        $row = $model->saveRow(['name' => \Model::raw('LOWER(?)', ["D'AVILA"]), 'status' => 0, 'created' => 'NOW()']);
        self::assertSame(["d'avila"], self::$connection->getValue($table, 'name', ['id' => $row['id']]));
        self::assertNotNull(self::$connection->getValue($table, 'created', ['id' => $row['id']])[0]);
    }

    public function testFreeSortIsRefusedInStrict(): void
    {
        $model = $this->people();
        \Model::setStrict(true);

        $this->assertRefused(function () use ($model) {
            $model->getData('', 'name, (SELECT 1)');
        });

        $names = array_map(static function ($r) {
            return $r->name;
        }, $model->getData('', 'name DESC'));
        self::assertSame(["o'neil", 'Bia', 'Ana'], $names);
    }

    public function testBuilderWithBoundValuesInStrict(): void
    {
        $model = $this->people();
        \Model::setStrict(true);

        $model->field('name')->where('name', '=', "o'neil")->wOr()->where('status', 'IN', [0]);
        $names = array_map(static function ($r) {
            return $r->name;
        }, $model->getCommandData());
        self::assertSame(['Bia', "o'neil"], $names);

        $model->field('status')->field(\Model::raw('COUNT(*)'), 'total')
            ->whereRaw('name <> ?', ['x" OR "1"="1'])
            ->group('status')
            ->havingRaw('COUNT(*) > ?', [1]);
        $rows = $model->getCommandData(true, true);
        self::assertEquals([['status' => 1, 'total' => 2]], $rows);

        $model->field('name')->where('name', '=', '1" OR "1"="1');
        self::assertSame([], $model->getCommandData(), 'o valor é comparado como texto');
    }

    public function testConnectionExistAndGetValueInStrict(): void
    {
        $model = $this->people();
        $table = $model->get_table();
        \Connection::setStrict(true);

        $this->assertRefused(function () use ($table) {
            self::$connection->exist($table, 'name', 'x', '1=1 OR 1=1');
        });
        $this->assertRefused(function () use ($table) {
            self::$connection->getValue($table, 'name', 'status = 1');
        });
        $this->assertRefused(function () use ($table) {
            self::$connection->getValue($table, 'MAX(id)', ['status' => 1]);
        });

        self::assertTrue(self::$connection->exist($table, 'name', "o'neil", ['status' => 1]));
        self::assertFalse(self::$connection->exist($table, 'name', "o'neil", \Connection::raw('status = ?', ['0 OR 1=1'])));
        self::assertEquals(['Ana', "o'neil"], self::$connection->getValue($table, 'name', ['status' => 1]));
        self::assertEquals(['3'], self::$connection->getValue($table, \Connection::raw('MAX(id)'), \Connection::raw('status >= ?', [0])));
    }
}
