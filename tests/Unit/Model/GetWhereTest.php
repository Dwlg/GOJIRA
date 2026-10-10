<?php

namespace Gojira\Tests\Unit\Model;

use Gojira\Tests\Support\TableColumn;

/**
 * Caracterização de Model::getWhere: fixa o SQL gerado. Desde a PR5 os
 * valores do array são escapados; o resto do SQL não mudou.
 *
 * @group legacy-sql
 */
final class GetWhereTest extends ModelTestCase
{
    public function testEmptyStringKeyMatchesAllRows(): void
    {
        self::assertSame('1=1', $this->model()->getWhere(''));
    }

    public function testNullKeyProducesEmptyClause(): void
    {
        self::assertSame('', $this->model()->getWhere(null));
    }

    public function testIntegerKeyComparesFirstPrimaryKeyWithoutBackticks(): void
    {
        self::assertSame('id="5"', $this->model()->getWhere(5));
    }

    public function testNumericStringKeyIsTreatedAsPrimaryKey(): void
    {
        self::assertSame('id="12"', $this->model()->getWhere('12'));
    }

    public function testZeroKeyIsTreatedAsPrimaryKey(): void
    {
        self::assertSame('id="0"', $this->model()->getWhere(0));
    }

    public function testNonNumericStringKeyIsUsedVerbatimAsWhereClause(): void
    {
        self::assertSame('status = 1 AND name <> ""', $this->model()->getWhere('status = 1 AND name <> ""'));
    }

    public function testAssociativeArrayBuildsEqualityPerColumn(): void
    {
        self::assertSame(
            '`name` = "Ana" AND `status` = "1"',
            $this->model()->getWhere(['name' => 'Ana', 'status' => 1])
        );
    }

    public function testListArrayMapsPositionsToPrimaryKeyColumns(): void
    {
        self::assertSame('`id` = "7"', $this->model()->getWhere([7]));
    }

    public function testListArrayWithCompositePrimaryKey(): void
    {
        $structure = TableColumn::structure([
            TableColumn::make('order_id', 'int', ['primary' => true]),
            TableColumn::make('line', 'int', ['primary' => true]),
            TableColumn::make('qty', 'int'),
        ]);

        self::assertSame('`order_id` = "10" AND `line` = "2"', $this->model($structure)->getWhere([10, 2]));
    }

    public function testListArrayWithMoreItemsThanPrimaryKeysRepeatsThePreviousClause(): void
    {
        // O segundo item não tem chave primária correspondente; a variável
        // temporária mantém o trecho anterior, que é anexado de novo.
        self::assertSame('`id` = "1" AND `id` = "1"', $this->model()->getWhere([1, 2]));
    }

    public function testArrayValuesAreEscaped(): void
    {
        // Antes os valores entravam sem escape ('`name` = "say "hi""').
        self::assertSame('`name` = "say \\"hi\\""', $this->model()->getWhere(['name' => 'say "hi"']));
        self::assertSame("`name` = \"d\\'avila \\\\ x\"", $this->model()->getWhere(['name' => "d'avila \\ x"]));
    }

    public function testListArrayValuesAreEscaped(): void
    {
        self::assertSame('`id` = "7\\""', $this->model()->getWhere(['7"']));
    }

    public function testArrayColumnNamesAreWrappedInBackticks(): void
    {
        self::assertSame('`a.b` = "1"', $this->model()->getWhere(['a.b' => 1]));
    }

    public function testBacktickInArrayColumnNameIsDoubled(): void
    {
        self::assertSame('`a``b` = "1"', $this->model()->getWhere(['a`b' => 1]));
    }

    public function testExplicitStructureArgumentOverridesModelStructure(): void
    {
        $other = TableColumn::structure([
            TableColumn::make('code', 'varchar', ['primary' => true]),
        ]);

        self::assertSame('code="9"', $this->model()->getWhere(9, $other));
    }
}
