<?php

namespace Gojira\Tests\Unit\Model;

use Gojira\Tests\Support\TableColumn;

/**
 * Caracterização do SQL de Model::saveRow com $retCommand = true.
 * O exist() é substituído para escolher entre INSERT e UPDATE.
 *
 * @group legacy-sql
 */
final class SaveRowTest extends ModelTestCase
{
    public function testInsertSkipsNullAutoIncrementAndEscapesText(): void
    {
        $model = $this->model();
        $sql   = $model->saveRow(['name' => "O'Brien", 'status' => 1], true);

        self::assertSame(
            'INSERT INTO `recording`(`name`, `status`, `created`, `birth`) VALUES("O\\\'Brien", 1, NULL, NULL)',
            $sql
        );
        self::assertSame([['recording', '', '', '`id`=""']], $model->existCalls);
    }

    public function testInsertAcceptsObjectRow(): void
    {
        $row         = new \stdClass();
        $row->name   = 'Ana';
        $row->status = '';

        self::assertSame(
            'INSERT INTO `recording`(`name`, `status`, `created`, `birth`) VALUES("Ana", NULL, NULL, NULL)',
            $this->model()->saveRow($row, true)
        );
    }

    public function testUpdateWhenPrimaryKeyRowExists(): void
    {
        $model              = $this->model();
        $model->existResult = true;

        $sql = $model->saveRow(['id' => 5, 'name' => 'Ana', 'status' => ''], true);

        self::assertSame(
            'UPDATE `recording` SET `id`=5, `name`="Ana", `status`=NULL, `created`=NULL, `birth`=NULL WHERE `id`="5"',
            $sql
        );
        self::assertSame([['recording', '', '', '`id`="5"']], $model->existCalls);
    }

    public function testInsertWithExplicitPrimaryKeyWhenRowDoesNotExist(): void
    {
        self::assertSame(
            'INSERT INTO `recording`(`id`, `name`, `status`, `created`, `birth`) VALUES(9, "Ana", NULL, NULL, NULL)',
            $this->model()->saveRow(['id' => 9, 'name' => 'Ana'], true)
        );
    }

    public function testDatesInBrazilianFormatAreConverted(): void
    {
        self::assertSame(
            'INSERT INTO `recording`(`name`, `status`, `created`, `birth`) VALUES("Ana", NULL, "20241225103000", "20241225")',
            $this->model()->saveRow(['name' => 'Ana', 'created' => '25/12/2024 10:30:00', 'birth' => '25/12/2024'], true)
        );
    }

    public function testValuesShapedAsListedFunctionAreWrittenUnquoted(): void
    {
        self::assertSame(
            'INSERT INTO `recording`(`name`, `status`, `created`, `birth`) VALUES(UPPER(name), NULL, NOW(), CURDATE())',
            $this->model()->saveRow(['name' => 'UPPER(name)', 'created' => 'NOW()', 'birth' => 'CURDATE()'], true)
        );
    }

    public function testTextKeepsCharactersThatAntiInjectionWouldRemoveFromOtherTypes(): void
    {
        self::assertSame(
            'INSERT INTO `recording`(`name`, `status`, `created`, `birth`) VALUES("a -- b * c", 12, NULL, NULL)',
            $this->model()->saveRow(['name' => 'a -- b * c', 'status' => '1--2'], true)
        );
    }

    public function testPrimaryKeyValueIsInterpolatedInExistCompareWithoutEscaping(): void
    {
        $structure = TableColumn::structure([
            TableColumn::make('code', 'varchar', ['primary' => true]),
            TableColumn::make('name', 'varchar'),
        ]);
        $model = $this->model($structure);
        $model->saveRow(['code' => 'a"b', 'name' => 'x'], true);

        self::assertSame('`code`="a"b"', $model->existCalls[0][3]);
    }

    public function testTimestampColumnIsWrittenAsNullWhenEmpty(): void
    {
        $structure = TableColumn::structure([
            TableColumn::make('id', 'int', ['primary' => true, 'auto_increment' => true]),
            TableColumn::make('updated', 'timestamp'),
        ]);

        self::assertSame(
            'INSERT INTO `recording`(`updated`) VALUES(NULL)',
            $this->model($structure)->saveRow([], true)
        );
    }

    public function testMissingRequiredFieldThrows(): void
    {
        $this->expectException(\ModelException::class);
        $this->expectExceptionCode(0x2014);

        $this->model()->saveRow(['status' => 1], true);
    }

    public function testPrimaryKeyWithoutValueAndWithoutAutoIncrementThrows(): void
    {
        $structure = TableColumn::structure([
            TableColumn::make('code', 'varchar', ['primary' => true]),
            TableColumn::make('name', 'varchar'),
        ]);

        $this->expectException(\ModelException::class);
        $this->expectExceptionCode(0x2013);

        $this->model($structure)->saveRow(['name' => 'x'], true);
    }

    public function testRetCommandDoesNotExecute(): void
    {
        $model = $this->model();
        $model->saveRow(['name' => 'Ana'], true);

        self::assertSame([], $model->recorded);
    }
}
