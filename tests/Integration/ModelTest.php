<?php

namespace Gojira\Tests\Integration;

use Gojira\Tests\Support\Deprecations;
use Gojira\Tests\Support\IntegrationTestCase;

/**
 * Caracterização do Model contra MySQL/MariaDB real: o SQL que ele monta
 * hoje é aceito pelo banco e produz estes dados.
 *
 * @group legacy-sql
 */
final class ModelTest extends IntegrationTestCase
{
    use Deprecations;

    protected function setUp(): void
    {
        $this->resetModelDeprecations();
    }

    private function itemModel(): \Fixture_Model
    {
        $table = $this->createTable(
            'id INT AUTO_INCREMENT PRIMARY KEY, '
            . 'name VARCHAR(100) NOT NULL, '
            . 'status INT NULL, '
            . "kind ENUM('a','b') NULL, "
            . 'created DATETIME NULL, '
            . 'birth DATE NULL, '
            . "note VARCHAR(20) NULL DEFAULT 'novo'"
        );

        return $this->model($table);
    }

    public function testNotNullColumnWithDefaultStillRequiresAValue(): void
    {
        $model = $this->model($this->createTable(
            "id INT AUTO_INCREMENT PRIMARY KEY, note VARCHAR(20) NOT NULL DEFAULT 'novo'"
        ));

        $this->expectException(\ModelException::class);
        $this->expectExceptionCode(0x2014);
        $model->saveRow([]);
    }

    public function testInvalidTableThrowsModelException(): void
    {
        $model = $this->model('gj_test_missing_table');

        $this->expectException(\ModelException::class);
        $this->expectExceptionCode(0x2003);
        $model->getData();
    }

    public function testInsertReturnsGeneratedPrimaryKey(): void
    {
        $model = $this->itemModel();

        $first  = $model->saveRow(['name' => 'Ana', 'status' => 1]);
        $second = $model->saveRow(['name' => 'Bia']);

        self::assertEquals(['id' => 1], $first);
        self::assertEquals(['id' => 2], $second);
        self::assertEquals(2, $model->countData());
    }

    public function testGetDataReturnsObjectsWithBrazilianDates(): void
    {
        $model = $this->itemModel();
        $model->saveRow(['name' => 'Ana', 'status' => 1, 'created' => '25/12/2024 10:30:00', 'birth' => '01/02/1990', 'kind' => 'b']);

        $rows = $model->getData(1);

        self::assertCount(1, $rows);
        self::assertInstanceOf(\stdClass::class, $rows[0]);
        self::assertSame('Ana', $rows[0]->name);
        self::assertSame('25/12/2024 10:30:00', $rows[0]->created);
        self::assertSame('01/02/1990', $rows[0]->birth);
        self::assertSame('b', $rows[0]->kind);
    }

    public function testOmittedNullableColumnIsWrittenAsNullInsteadOfDatabaseDefault(): void
    {
        $model = $this->itemModel();
        $model->saveRow(['name' => 'Ana']);

        self::assertNull($model->getData(1)[0]->note);
    }

    public function testGetDataWithoutResultsReturnsEmptyArray(): void
    {
        self::assertSame([], $this->itemModel()->getData(99));
    }

    public function testGetDataKeyFormsSortAndLimit(): void
    {
        $model = $this->itemModel();
        foreach (['Caio', 'Ana', 'Bia'] as $i => $name) {
            $model->saveRow(['name' => $name, 'status' => $i % 2]);
        }

        $names = static function (array $rows): array {
            return array_map(static function ($r) { return $r->name; }, $rows);
        };

        self::assertSame(['Ana', 'Bia', 'Caio'], $names($model->getData('', ['name'])));
        self::assertSame(['Caio', 'Bia'], $names($model->getData('status = 0', 'name DESC')));
        self::assertSame(['Ana'], $names($model->getData(['status' => 1])));
        self::assertSame(['Bia'], $names($model->getData('', ['name'], [1, 1])));
        self::assertSame(['Caio', 'Ana'], $names($model->getData('', ['id'], '2')));
    }

    public function testGetDataCanIndexByPrimaryKey(): void
    {
        $model = $this->itemModel();
        $model->saveRow(['name' => 'Ana']);
        $model->saveRow(['name' => 'Bia']);

        $rows = $model->getData('', '', '', true);

        self::assertSame(['1', '2'], array_map('strval', array_keys($rows)));
        self::assertSame('Bia', $rows[2]->name);
    }

    public function testUpdateExistingRow(): void
    {
        $model = $this->itemModel();
        $model->saveRow(['name' => 'Ana', 'status' => 1]);

        $result = $model->saveRow(['id' => 1, 'name' => 'Ana Maria', 'status' => '']);

        self::assertEquals(['id' => 1], $result);
        $row = $model->getData(1)[0];
        self::assertSame('Ana Maria', $row->name);
        self::assertNull($row->status);
        self::assertEquals(1, $model->countData());
    }

    public function testTextWithQuotesBackslashesAndDashesIsStoredIntact(): void
    {
        $model = $this->itemModel();
        $text  = "O'Brien \"x\" a\\b -- c * d";

        $model->saveRow(['name' => $text]);

        self::assertSame($text, $model->getData(1)[0]->name);
    }

    public function testValueShapedAsListedFunctionIsEvaluatedByTheDatabase(): void
    {
        $model = $this->itemModel();
        [, $deprecations] = $this->withDeprecations(function () use ($model) {
            return $model->saveRow(['name' => 'UPPER("abc")', 'created' => 'NOW()']);
        });
        self::assertCount(1, $deprecations);

        $row = $model->getData(1)[0];
        self::assertSame('ABC', $row->name);
        self::assertMatchesRegularExpression('#^\d{2}/\d{2}/\d{4} \d{2}:\d{2}:\d{2}$#', $row->created);
    }

    public function testCountDataAndDelete(): void
    {
        $model = $this->itemModel();
        $model->saveRow(['name' => 'Ana', 'status' => 1]);
        $model->saveRow(['name' => 'Bia', 'status' => 1]);
        $model->saveRow(['name' => 'Caio', 'status' => 0]);

        self::assertEquals(2, $model->countData(['status' => 1]));
        self::assertEquals(1, $model->countData('status = 0'));

        self::assertTrue($model->delete(2));
        self::assertEquals(2, $model->countData());

        self::assertTrue($model->delete(['status' => 0]));
        self::assertEquals(1, $model->countData());
    }

    public function testGetEmptyRowUsesColumnDefaults(): void
    {
        $row = $this->itemModel()->getEmptyRow(true);

        self::assertNull($row['id']);
        self::assertNull($row['status']);
        self::assertSame('novo', $row['note']);
    }

    public function testCommandBuilderRunsAgainstTheDatabase(): void
    {
        $model = $this->itemModel();
        $model->saveRow(['name' => 'Ana', 'status' => 1]);
        $model->saveRow(['name' => 'Bia', 'status' => 1]);
        $model->saveRow(['name' => 'Caio', 'status' => 0]);

        $model->field('status')->field('COUNT(*)', 'total')->group('status')->order('status', 'DESC');
        self::assertEquals(2, $model->countCommand());
        $model->resetCommand();

        $model->field('status')->field('COUNT(*)', 'total')->group('status')->order('status', 'DESC');
        $rows = $model->getCommandData(true, true);

        self::assertEquals([['status' => 1, 'total' => 2], ['status' => 0, 'total' => 1]], $rows);
    }
}
