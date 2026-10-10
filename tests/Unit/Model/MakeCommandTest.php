<?php

namespace Gojira\Tests\Unit\Model;

/**
 * Caracterização do construtor de consulta (Model::makeCommand),
 * lido por getCommandObject()->command.
 *
 * @group legacy-sql
 */
final class MakeCommandTest extends ModelTestCase
{
    private function command(\Recording_Model $model): string
    {
        return $model->getCommandObject()->command;
    }

    public function testWithoutFieldsThrowsModelException(): void
    {
        $this->expectException(\ModelException::class);
        $this->expectExceptionCode(0x2011);

        $this->model()->getCommandObject();
    }

    public function testFieldsAliasesAndDefaultTable(): void
    {
        $model = $this->model();
        $model->field('id')->field('name', 'n')->field('status', '', 'a');

        self::assertSame('SELECT id, name AS "n", `a`.status FROM `recording` as `a` ', $this->command($model));
    }

    public function testFromOverridesTableAndAlias(): void
    {
        $model = $this->model();
        $model->field('*')->from('other', 'o');

        self::assertSame('SELECT * FROM `other` as `o` ', $this->command($model));
    }

    public function testWhereValuesAndOperatorsAreConcatenatedVerbatim(): void
    {
        $model = $this->model();
        $model->field('id')
            ->where('status', '=', 1)->wAnd()
            ->where('name', 'LIKE', '"A%"')->wOr()
            ->where('id', 'IS', 'NULL');

        self::assertSame(
            'SELECT id FROM `recording` as `a`  WHERE status = 1 AND name LIKE "A%" OR id IS NULL',
            $this->command($model)
        );
    }

    public function testJoinsUseSequentialLetterAliasesUnlessAliasIsGiven(): void
    {
        $model = $this->model();
        $model->field('a.id')
            ->join('left', 'other', 'a.id = b.recording_id')
            ->join('inner', 'third', 'a.id = t.recording_id', 't')
            ->join('right', 'fourth', 'a.id = c.recording_id');

        self::assertSame(
            'SELECT a.id FROM `recording` as `a`  LEFT JOIN `other` as b on a.id = b.recording_id'
            . ' INNER JOIN `third` as `t` on a.id = t.recording_id'
            . ' RIGHT JOIN `fourth` as c on a.id = c.recording_id',
            $this->command($model)
        );
    }

    public function testJoinTypeIsUppercasedWithoutValidation(): void
    {
        $model = $this->model();
        $model->field('id')->join('natural', 'other', '1=1');

        self::assertSame('SELECT id FROM `recording` as `a`  NATURAL JOIN `other` as b on 1=1', $this->command($model));
    }

    public function testGroupHavingOrderAndLimit(): void
    {
        $model = $this->model();
        $model->field('status')->field('COUNT(*)', 'total')
            ->group('status')
            ->having('COUNT(*) > 1')->hAnd()->having('status > 0')
            ->order('status', 'DESC')->order('a.name')
            ->limit(10, 20);

        self::assertSame(
            'SELECT status, COUNT(*) AS "total" FROM `recording` as `a`  GROUP BY status'
            . ' HAVING  COUNT(*) > 1  AND  status > 0 '
            . ' ORDER BY `status` DESC, a.name ASC LIMIT 10, 20',
            $this->command($model)
        );
    }

    /** @dataProvider orderExpressions */
    public function testOrderExpressionQuoting(string $expression, string $expected, int $deprecationCount): void
    {
        $model = $this->model();
        $model->field('id')->order($expression);

        [$command, $deprecations] = $this->withDeprecations(function () use ($model) {
            return $this->command($model);
        });

        self::assertSame('SELECT id FROM `recording` as `a`  ORDER BY ' . $expected, $command);
        self::assertCount($deprecationCount, $deprecations);
    }

    public function orderExpressions(): array
    {
        // Antes a crase dependia de "$ord > 0", que no PHP 8 é verdadeiro para
        // qualquer nome iniciado por letra: 'name' saía sem crase e ' name' saía
        // como '` name`'. Agora nome simples sempre recebe crase.
        return [
            'nome simples'        => ['name', '`name` ASC', 0],
            'posição numérica'    => ['2', '2 ASC', 0],
            'com tabela'          => ['a.name', 'a.name ASC', 0],
            'já com crase'        => ['`name`', '`name` ASC', 0],
            'com espaço inicial'  => [' name', '`name` ASC', 0],
            'com função'          => ['LENGTH(name)', 'LENGTH(name) ASC', 1],
        ];
    }

    /** @dataProvider orderDirections */
    public function testOrderDirection(string $direction, string $expected): void
    {
        $model = $this->model();
        $model->field('id')->order('name', $direction);

        self::assertSame('SELECT id FROM `recording` as `a`  ORDER BY `name` ' . $expected, $this->command($model));
    }

    public function orderDirections(): array
    {
        return [
            'ASC'        => ['ASC', 'ASC'],
            'desc'       => ['desc', 'desc'],
            'vazia'      => ['', ''],
        ];
    }

    public function testInvalidOrderDirectionThrows(): void
    {
        $model = $this->model();
        $model->field('id')->order('name', 'DESC, id');

        $this->expectException(\ModelException::class);
        $this->expectExceptionCode(0x2019);
        $this->command($model);
    }

    public function testLimitWithoutOffset(): void
    {
        $model = $this->model();
        $model->field('id')->limit(5);

        self::assertSame('SELECT id FROM `recording` as `a`  LIMIT 5', $this->command($model));
    }

    public function testLimitAcceptsOffsetForm(): void
    {
        $model = $this->model();
        $model->field('id')->limit('5 OFFSET 2');

        self::assertSame('SELECT id FROM `recording` as `a`  LIMIT 5 OFFSET 2', $this->command($model));
    }

    public function testLimitAcceptsNumericStrings(): void
    {
        $model = $this->model();
        $model->field('id')->limit('5', '10');

        self::assertSame('SELECT id FROM `recording` as `a`  LIMIT 5, 10', $this->command($model));
    }

    /** @dataProvider invalidLimits */
    public function testInvalidLimitThrows($init, $end): void
    {
        // Antes o valor era concatenado como veio ('5 OFFSET 2' saía no SQL).
        $model = $this->model();
        $model->field('id')->limit($init, $end);

        $this->expectException(\ModelException::class);
        $this->expectExceptionCode(0x2016);
        $this->command($model);
    }

    public function invalidLimits(): array
    {
        return [
            'texto livre'   => ['5 ROWS', 0],
            'OFFSET e fim'  => ['5 OFFSET 2', 10],
            'negativo'      => [-1, 0],
            'fim com texto' => [5, '10 x'],
            'fim negativo'  => [5, -1],
        ];
    }

    public function testSubqueryObjectsInFromJoinAndWhere(): void
    {
        $inner = $this->model();
        $inner->field('recording_id')->from('other', 'o')->where('o.flag', '=', 1);
        $sub = $inner->getCommandObject();

        $model = $this->model();
        $model->field('id')->where('id', 'IN', $sub);

        self::assertSame(
            'SELECT id FROM `recording` as `a`  WHERE id IN (SELECT recording_id FROM `other` as `o`  WHERE o.flag = 1)',
            $this->command($model)
        );
    }

    public function testGetCommandObjectResetsTheBuilderByDefault(): void
    {
        $model = $this->model();
        $model->field('id')->where('id', '=', 1);
        $first = $model->getCommandObject();

        self::assertSame('SELECT id FROM `recording` as `a`  WHERE id = 1', $first->command);
        self::assertSame('recording', $first->table);

        $this->expectException(\ModelException::class);
        $model->getCommandObject();
    }

    public function testGetCommandObjectCanKeepTheBuilder(): void
    {
        $model = $this->model();
        $model->field('id');

        $model->getCommandObject(false);
        self::assertSame('SELECT id FROM `recording` as `a` ', $model->getCommandObject()->command);
    }

    public function testCountCommandWrapsTheSelectInConnectionCount(): void
    {
        $model = $this->model();
        $model->field('id')->where('status', '=', 1);
        $model->countCommand();

        self::assertSame('SELECT id FROM `recording` as `a`  WHERE status = 1', $model->lastRecorded());
    }
}
