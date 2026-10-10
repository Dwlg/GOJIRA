<?php

namespace Gojira\Tests\Unit\Model;

/**
 * Caracterização de getData, countData e delete: o SQL é capturado no
 * count()/execute() herdados da Connection, sem banco.
 *
 * @group legacy-sql
 */
final class GetDataTest extends ModelTestCase
{
    private const SELECT = 'SELECT a.`id` as "id", a.`name` as "name", a.`status` as "status", '
        . 'DATE_FORMAT(a.`created`,"%d/%m/%Y %H:%i:%s") as "created", '
        . 'DATE_FORMAT(a.`birth`,"%d/%m/%Y") as "birth" FROM `recording` as a';

    private function getDataSql(...$args): string
    {
        $model = $this->model();
        $data  = $model->getData(...$args);

        self::assertSame([], $data, 'count() devolve 0, então getData não consulta e devolve []');
        self::assertCount(1, $model->recorded);

        return $model->lastRecorded();
    }

    public function testWithoutKeySelectsAllColumnsWithDateFormatting(): void
    {
        self::assertSame(self::SELECT . ' WHERE 1=1', $this->getDataSql());
    }

    public function testNumericKey(): void
    {
        self::assertSame(self::SELECT . ' WHERE id="3"', $this->getDataSql(3));
    }

    public function testStringKeyIsUsedAsWhere(): void
    {
        self::assertSame(self::SELECT . ' WHERE status = 1', $this->getDataSql('status = 1'));
    }

    public function testArrayKey(): void
    {
        self::assertSame(self::SELECT . ' WHERE `name` = "Ana"', $this->getDataSql(['name' => 'Ana']));
    }

    public function testNullKeyOmitsWhere(): void
    {
        self::assertSame(self::SELECT, $this->getDataSql(null));
    }

    public function testArraySortWrapsEachColumnInBackticks(): void
    {
        self::assertSame(self::SELECT . ' WHERE 1=1 ORDER BY `name`, `id`', $this->getDataSql('', ['name', 'id']));
    }

    public function testStringSortWithColumnListIsConcatenatedVerbatimWithoutDeprecation(): void
    {
        [$sql, $deprecations] = $this->withDeprecations(function () {
            return $this->getDataSql('', 'name DESC, id');
        });

        self::assertSame(self::SELECT . ' WHERE 1=1 ORDER BY name DESC, id', $sql);
        self::assertSame([], $deprecations);
    }

    public function testFreeExpressionSortIsKeptButDeprecated(): void
    {
        [$sql, $deprecations] = $this->withDeprecations(function () {
            return $this->getDataSql('', 'LENGTH(name) DESC');
        });

        self::assertSame(self::SELECT . ' WHERE 1=1 ORDER BY LENGTH(name) DESC', $sql);
        self::assertCount(1, $deprecations);
        self::assertStringContainsString('sort as a free SQL expression', $deprecations[0]);
    }

    public function testBacktickInArraySortColumnIsDoubled(): void
    {
        self::assertSame(self::SELECT . ' WHERE 1=1 ORDER BY `a``b`', $this->getDataSql('', ['a`b']));
    }

    /** @dataProvider limits */
    public function testLimitForms($limit, string $expected): void
    {
        self::assertSame(self::SELECT . ' WHERE 1=1' . $expected, $this->getDataSql('', '', $limit));
    }

    public function limits(): array
    {
        return [
            'inteiro'           => [10, ' LIMIT 10'],
            'string'            => ['5, 10', ' LIMIT 5, 10'],
            'array com offset'  => [[5, 10], ' LIMIT 5, 10'],
            'array sem offset'  => [[5], ' LIMIT 5'],
            'array de strings'  => [['5', '10'], ' LIMIT 5, 10'],
            'string numérica'   => ['7', ' LIMIT 7'],
            'string sem espaço' => ['5,10', ' LIMIT 5, 10'],
            'vazio'             => ['', ''],
            'zero'              => [0, ''],
            'null'              => [null, ''],
        ];
    }

    /** @dataProvider invalidLimits */
    public function testInvalidLimitThrows($limit): void
    {
        // Antes qualquer texto era concatenado depois do LIMIT.
        $this->expectException(\ModelException::class);
        $this->expectExceptionCode(0x2016);

        $this->model()->getData('', '', $limit);
    }

    public function invalidLimits(): array
    {
        return [
            'texto livre'        => ['5 OFFSET 1'],
            'negativo'           => [-1],
            'string negativa'    => ['-1'],
            'decimal'            => [1.5],
            'texto'              => ['abc'],
            'três partes'        => ['1, 2, 3'],
            'array com texto'    => [[5, 'x']],
            'array com três'     => [[1, 2, 3]],
            'array vazio'        => [[]],
        ];
    }

    public function testCountDataWithoutKey(): void
    {
        $model = $this->model();
        $model->countResult = 4;

        self::assertSame(4, $model->countData());
        self::assertSame('SELECT 1 FROM `recording` WHERE 1=1', $model->lastRecorded());
    }

    public function testCountDataWithArrayKey(): void
    {
        $model = $this->model();
        $model->countData(['name' => 'Ana', 'status' => 1]);

        self::assertSame('SELECT 1 FROM `recording` WHERE `name` = "Ana" AND `status` = "1"', $model->lastRecorded());
    }

    public function testCountDataWithNullKeyOmitsWhere(): void
    {
        $model = $this->model();
        $model->countData(null);

        self::assertSame('SELECT 1 FROM `recording`', $model->lastRecorded());
    }

    public function testDeleteByNumericKey(): void
    {
        $model = $this->model();

        self::assertTrue($model->delete(3));
        self::assertSame('DELETE FROM `recording` WHERE id="3"', $model->lastRecorded());
    }

    /** @dataProvider emptyDeleteKeys */
    public function testDeleteWithoutKeyThrows($key): void
    {
        // Antes delete('') montava "WHERE 1=1" e apagava a tabela inteira;
        // delete(null) e delete([]) montavam um SQL inválido.
        $model = $this->model();

        try {
            $model->delete($key);
            self::fail('delete sem chave deveria lançar ModelException');
        } catch (\ModelException $e) {
            self::assertSame(0x2018, $e->getCode());
        }
        self::assertSame([], $model->recorded);
    }

    public function emptyDeleteKeys(): array
    {
        return ['string vazia' => [''], 'null' => [null], 'array vazio' => [[]]];
    }

    public function testDeleteAllRowsRequiresAnExplicitCondition(): void
    {
        $model = $this->model();
        $model->delete('1=1');

        self::assertSame('DELETE FROM `recording` WHERE 1=1', $model->lastRecorded());
    }

    public function testDeleteEscapesArrayValues(): void
    {
        $model = $this->model();
        $model->delete(['name' => 'a"b']);

        self::assertSame('DELETE FROM `recording` WHERE `name` = "a\\"b"', $model->lastRecorded());
    }

    public function testDeleteWithStringKeyUsesItAsWhere(): void
    {
        $model = $this->model();
        $model->delete('status = 0');

        self::assertSame('DELETE FROM `recording` WHERE status = 0', $model->lastRecorded());
    }

    public function testGetEmptyRowUsesDefaultsWithoutQuotes(): void
    {
        $structure                          = $this->defaultStructure();
        $structure['name']->default_value   = "'novo'";
        $structure['name']->default_is_null = '0';
        $structure['status']->default_value = '1';
        $structure['status']->default_is_null = '0';

        $row = $this->model($structure)->getEmptyRow();

        self::assertNull($row->id);
        self::assertSame('novo', $row->name);
        self::assertSame('1', $row->status);
        self::assertSame(['id' => null, 'name' => 'novo', 'status' => '1', 'created' => null, 'birth' => null],
            $this->model($structure)->getEmptyRow(true));
    }
}
