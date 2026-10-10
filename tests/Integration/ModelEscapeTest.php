<?php

namespace Gojira\Tests\Integration;

use Gojira\Tests\Support\Deprecations;
use Gojira\Tests\Support\IntegrationTestCase;

/**
 * Valores com aspas e barra invertida chegam ao banco como dado em todos os
 * caminhos do Model e da Connection que montam SQL com valores.
 */
final class ModelEscapeTest extends IntegrationTestCase
{
    use Deprecations;

    private const TRICKY = [
        'aspas duplas'     => ['o"neil'],
        'aspas simples'    => ["d'avila"],
        'barra invertida'  => ['a\\b'],
        'barra no fim'     => ['fim\\'],
        'tudo junto'       => ['x\' OR "1"="1" -- \\'],
    ];

    protected function setUp(): void
    {
        $this->resetModelDeprecations();
    }

    public function trickyValues(): array
    {
        return self::TRICKY;
    }

    private function codeModel(): \Fixture_Model
    {
        return $this->model($this->createTable(
            'code VARCHAR(50) NOT NULL PRIMARY KEY, '
            . 'name VARCHAR(50) NOT NULL, '
            . "kind ENUM('a','o''x','q\"z') NULL, "
            . 'raw VARBINARY(50) NULL, '
            . 'qty INT NULL, '
            . 'birth DATE NULL, '
            . 'created DATETIME NULL'
        ));
    }

    /** @dataProvider trickyValues */
    public function testSaveRowInsertAndUpdateWithTrickyPrimaryKeyAndText(string $value): void
    {
        $model = $this->codeModel();
        $model->saveRow(['code' => 'other', 'name' => 'other']);

        $inserted = $model->saveRow(['code' => $value, 'name' => $value]);
        self::assertSame(['code' => $value], $inserted);

        $model->saveRow(['code' => $value, 'name' => $value . '2']);

        self::assertEquals(2, $model->countData());
        $rows = $this->fetchAllRows('SELECT code, name FROM `' . $model->get_table() . '` ORDER BY code = "other"');
        self::assertSame(['code' => $value, 'name' => $value . '2'], $rows[0]);
        self::assertSame(['code' => 'other', 'name' => 'other'], $rows[1]);
    }

    /** @dataProvider trickyValues */
    public function testGetWhereCountDataAndDeleteMatchTrickyValuesLiterally(string $value): void
    {
        $model = $this->codeModel();
        $model->saveRow(['code' => 'k1', 'name' => $value]);
        $model->saveRow(['code' => $value, 'name' => 'n2']);
        $model->saveRow(['code' => 'k3', 'name' => 'n3']);

        self::assertEquals(1, $model->countData(['name' => $value]));
        self::assertSame('k1', $model->getData(['name' => $value])[0]->code);
        self::assertSame('n2', $model->getData([$value])[0]->name, 'lista posicional usa a chave primária');

        self::assertTrue($model->delete(['name' => $value]));
        self::assertTrue($model->delete([$value]));
        self::assertEquals(1, $model->countData());
        self::assertSame('k3', $model->getData()[0]->code);
    }

    public function testNumericKeyStillMatchesThePrimaryKey(): void
    {
        $model = $this->model($this->createTable('id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(20) NOT NULL'));
        $model->saveRow(['name' => 'Ana']);
        $model->saveRow(['name' => 'Bia']);

        self::assertSame('Bia', $model->getData(2)[0]->name);
        self::assertSame('Bia', $model->getData('2')[0]->name);
    }

    public function testEnumWithQuotesIsStoredAsData(): void
    {
        $model = $this->codeModel();
        $model->saveRow(['code' => 'a', 'name' => 'a', 'kind' => "o'x"]);
        $model->saveRow(['code' => 'b', 'name' => 'b', 'kind' => 'q"z']);

        self::assertSame("o'x", $model->getData(['code' => 'a'])[0]->kind);
        self::assertSame('q"z', $model->getData(['code' => 'b'])[0]->kind);
    }

    public function testCharactersThatAntiInjectionUsedToRemoveAreStoredIntact(): void
    {
        $model = $this->codeModel();
        $value = 'a -- b * c \\ d <b>e</b>';

        $model->saveRow(['code' => 'a', 'name' => $value, 'raw' => $value]);

        $row = $model->getData(['code' => 'a'])[0];
        self::assertSame($value, $row->name);
        self::assertSame($value, $row->raw);
    }

    public function testIsoAndBrazilianDatesAreStoredCorrectly(): void
    {
        $model = $this->codeModel();
        $model->saveRow(['code' => 'iso', 'name' => 'x', 'birth' => '2024-12-25', 'created' => '2024-12-25 10:30:00']);
        $model->saveRow(['code' => 'br', 'name' => 'x', 'birth' => '25/12/2024', 'created' => '25/12/2024 10:30:00']);

        foreach (['iso', 'br'] as $code) {
            $row = $model->getData(['code' => $code])[0];
            self::assertSame('25/12/2024', $row->birth, $code);
            self::assertSame('25/12/2024 10:30:00', $row->created, $code);
        }
    }

    public function testNonNumericIntegerIsRejectedBeforeReachingTheDatabase(): void
    {
        $model = $this->codeModel();

        try {
            $model->saveRow(['code' => 'a', 'name' => 'a', 'qty' => '1 OR 1']);
            self::fail('valor não numérico deveria lançar ModelException');
        } catch (\ModelException $e) {
            self::assertSame(0x2017, $e->getCode());
        }
        self::assertEquals(0, $model->countData());
    }

    public function testInvalidLimitIsRejected(): void
    {
        $model = $this->codeModel();

        $this->expectException(\ModelException::class);
        $this->expectExceptionCode(0x2016);
        $model->getData('', ['code'], '1 OFFSET 0');
    }

    public function testFreeSortStillWorksWithDeprecation(): void
    {
        $model = $this->codeModel();
        $model->saveRow(['code' => 'a', 'name' => 'ccc']);
        $model->saveRow(['code' => 'b', 'name' => 'b']);

        [$rows, $deprecations] = $this->withDeprecations(function () use ($model) {
            return $model->getData('', 'LENGTH(name)');
        });

        self::assertSame(['b', 'a'], array_map(static function ($r) { return $r->code; }, $rows));
        self::assertCount(1, $deprecations);
    }

    public function testDeleteWithoutKeyKeepsTheRows(): void
    {
        $model = $this->codeModel();
        $model->saveRow(['code' => 'a', 'name' => 'a']);

        try {
            $model->delete('');
            self::fail('delete sem chave deveria lançar ModelException');
        } catch (\ModelException $e) {
            self::assertSame(0x2018, $e->getCode());
        }
        self::assertEquals(1, $model->countData());

        self::assertTrue($model->delete('1=1'));
        self::assertEquals(0, $model->countData());
    }

    /** @dataProvider trickyValues */
    public function testConnectionExistMatchesTrickyValuesLiterally(string $value): void
    {
        $table = $this->createTable('id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50) NULL');
        self::$connection->execute('INSERT INTO `' . $table . '` (name) VALUES ("' . self::$connection->escape($value) . '"), ("x")');

        self::assertTrue(self::$connection->exist($table, 'name', $value));
        self::assertFalse(self::$connection->exist($table, 'name', $value . 'z'));
        self::assertTrue(self::$connection->exist($table, 'name', $value, 'id = 1'));
    }

    public function testGetValueAcceptsNumericLimitsAndRejectsOthers(): void
    {
        $table = $this->createTable('id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50) NULL');
        self::$connection->execute('INSERT INTO `' . $table . '` (name) VALUES ("a"), ("b"), ("c")');

        self::assertSame(['a', 'b', 'c'], self::$connection->getValue($table, 'name', '1=1'));
        self::assertSame(['a', 'b'], self::$connection->getValue($table, 'name', '1=1', 2));
        self::assertSame(['b'], self::$connection->getValue($table, 'name', '1=1', '1, 1'));

        $this->expectException(\Exception::class);
        self::$connection->getValue($table, 'name', '1=1', '1 OFFSET 1');
    }

    public function testTableExistAndFieldExistUseTheConnectedDatabase(): void
    {
        $table = $this->createTable('id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50) NULL');

        self::assertTrue(self::$connection->tableExist($table));
        self::assertFalse(self::$connection->tableExist($table . '_x'));
        self::assertFalse(self::$connection->tableExist('a"b\'c'));

        self::assertTrue(self::$connection->fieldExist($table, 'name'));
        self::assertFalse(self::$connection->fieldExist($table, 'nome'));
        self::assertFalse(self::$connection->fieldExist($table, 'a"b\'c'));
    }
}
