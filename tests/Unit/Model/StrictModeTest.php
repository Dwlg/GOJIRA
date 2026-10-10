<?php

namespace Gojira\Tests\Unit\Model;

/**
 * Modo estrito do Model (opt-in): cada caminho que aceita SQL do chamador é
 * testado nos dois modos. Fora do estrito o comportamento antigo continua, com
 * E_USER_DEPRECATED; no estrito ele lança ModelException 0x201A e o SQL só
 * entra por Model::raw() / Gojira_Raw, com os valores vinculados.
 */
final class StrictModeTest extends ModelTestCase
{
    private const SELECT = 'SELECT a.`id` as "id", a.`name` as "name", a.`status` as "status", '
        . 'DATE_FORMAT(a.`created`,"%d/%m/%Y %H:%i:%s") as "created", '
        . 'DATE_FORMAT(a.`birth`,"%d/%m/%Y") as "birth" FROM `recording` as a';

    private function strict(): void
    {
        \Model::setStrict(true);
    }

    private function assertRefused(callable $fn, int $code = 0x201A): void
    {
        try {
            $fn();
        } catch (\ModelException $e) {
            self::assertSame($code, $e->getCode(), $e->getMessage());
            return;
        }
        self::fail('Esperava ModelException ' . sprintf('0x%X', $code));
    }

    private function command(\Recording_Model $model): string
    {
        return $model->getCommandObject()->command;
    }

    // ---- chave do modo -------------------------------------------------------

    public function testStrictIsOffByDefaultAndCanBeToggled(): void
    {
        self::assertFalse(\Model::isStrict());
        \Model::setStrict(true);
        self::assertTrue(\Model::isStrict());
        self::assertTrue(\Connection::isStrict(), 'a chave vale para Model e Connection');
        \Model::setStrict(null);
        self::assertFalse(\Model::isStrict(), 'null volta a seguir a constante, ausente aqui');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testConstantTurnsStrictModeOn(): void
    {
        define('GOJIRA_MODEL_STRICT', true);
        self::assertTrue(\Model::isStrict());

        $this->assertRefused(function () {
            $this->model()->getWhere('status = 1');
        });

        \Model::setStrict(false);
        self::assertFalse(\Model::isStrict(), 'setStrict(false) tem precedência sobre a constante');
    }

    // ---- 1. função SQL como valor no saveRow ---------------------------------

    public function testFunctionWithArgumentsValueLegacyEntersRawWithDeprecation(): void
    {
        $this->captureDeprecations();
        $sql = $this->model()->saveRow(['name' => 'UPPER(name)'], true);

        self::assertStringContainsString('VALUES(UPPER(name)', $sql);
        $this->assertDeprecated('SQL function with arguments');
    }

    /** @dataProvider refusedFunctionValues */
    public function testStrictRefusesFunctionValues(string $value): void
    {
        $this->strict();
        $model = $this->model();

        $this->assertRefused(function () use ($model, $value) {
            $model->saveRow(['name' => $value], true);
        });
        self::assertSame([], $model->recorded, 'nada foi executado');
    }

    public function refusedFunctionValues(): array
    {
        return [
            'com argumento'              => ['UPPER(name)'],
            'subconsulta como argumento' => ['LOWER((SELECT 1))'],
            'sem argumento fora da lista' => ['PI()'],
        ];
    }

    /** @dataProvider acceptedStrictValues */
    public function testStrictAcceptsLiteralsAndFixedNoArgumentFunctions(string $value): void
    {
        $this->strict();
        $sql = $this->model()->saveRow(['name' => 'x', 'created' => $value], true);

        self::assertStringContainsString(', ' . $value . ', ', $sql);
    }

    public function acceptedStrictValues(): array
    {
        return [
            'NOW()'             => ['NOW()'],
            'now() minúsculo'   => ['now()'],
            'UUID()'            => ['UUID()'],
            'CURDATE()'         => ['CURDATE()'],
            'CURRENT_TIMESTAMP' => ['CURRENT_TIMESTAMP'],
            'NULL'              => ['NULL'],
        ];
    }

    public function testRawValueIsBoundInBothModes(): void
    {
        foreach ([false, true] as $strict) {
            \Model::setStrict($strict);
            $sql = $this->model()->saveRow([
                'name'    => \Model::raw('UPPER(?)', ["o'neil"]),
                'created' => \Gojira_Raw::sql('NOW()'),
            ], true);

            self::assertSame(
                "INSERT INTO `recording`(`name`, `status`, `created`, `birth`) VALUES(UPPER('o\\'neil'), NULL, NOW(), NULL)",
                $sql
            );
        }
    }

    public function testRawPrimaryKeyIsUsedInTheExistCheck(): void
    {
        $this->strict();
        $model = $this->model();
        $model->saveRow(['id' => \Model::raw('?', [7]), 'name' => 'x'], true);

        self::assertSame('`id`=7', $model->existCalls[0][3]);
    }

    // ---- 2. chave em string crua ---------------------------------------------

    public function testLegacyStringKeyIsUsedVerbatimWithDeprecation(): void
    {
        $this->captureDeprecations();
        $model = $this->model();

        self::assertSame('status = 1', $model->getWhere('status = 1'));
        $model->getData('status = 1');
        $model->countData('status = 1');
        $model->delete('status = 1');

        self::assertSame([
            self::SELECT . ' WHERE status = 1',
            'SELECT 1 FROM `recording` WHERE status = 1',
            'DELETE FROM `recording` WHERE status = 1',
        ], $model->recorded);
        self::assertCount(1, $this->capturedDeprecations(), 'um aviso por requisição');
        $this->assertDeprecated('raw SQL string as key');
    }

    /** @dataProvider keyMethods */
    public function testStrictRefusesStringKey(string $method): void
    {
        $this->strict();
        $model = $this->model();

        $this->assertRefused(function () use ($model, $method) {
            $model->$method('status = 1');
        });
        self::assertSame([], $model->recorded);
    }

    public function keyMethods(): array
    {
        return [
            'getWhere'  => ['getWhere'],
            'getData'   => ['getData'],
            'countData' => ['countData'],
            'delete'    => ['delete'],
        ];
    }

    public function testStrictKeepsSafeKeyForms(): void
    {
        $this->strict();
        $model = $this->model();

        self::assertSame('1=1', $model->getWhere(''));
        self::assertSame('id="5"', $model->getWhere('5'), 'string numérica é a chave primária');
        self::assertSame('`name` = "Ana"', $model->getWhere(['name' => 'Ana']));
        self::assertSame("status = 1 AND name = 'a\\'b'", $model->getWhere(\Model::raw('status = ? AND name = ?', [1, "a'b"])));

        $model->delete(\Model::raw('1=1'));
        self::assertSame('DELETE FROM `recording` WHERE 1=1', $model->lastRecorded());
    }

    // ---- 3. sort do getData e order() ----------------------------------------

    public function testStrictRefusesFreeSortAndKeepsColumnLists(): void
    {
        $this->strict();
        $model = $this->model();

        $this->assertRefused(function () use ($model) {
            $model->getData('', 'LENGTH(name)');
        });

        $model->getData('', 'name DESC, id');
        self::assertSame(self::SELECT . ' WHERE 1=1 ORDER BY name DESC, id', $model->lastRecorded());

        $model->getData('', ['name']);
        self::assertSame(self::SELECT . ' WHERE 1=1 ORDER BY `name`', $model->lastRecorded());

        $model->getData('', \Model::raw('LENGTH(name)'));
        self::assertSame(self::SELECT . ' WHERE 1=1 ORDER BY LENGTH(name)', $model->lastRecorded());
    }

    public function testOrderExpressionLegacyAndStrict(): void
    {
        $this->captureDeprecations();
        $model = $this->model();
        $model->field('id')->order('LENGTH(name)');
        self::assertSame('SELECT id FROM `recording` as `a`  ORDER BY LENGTH(name) ASC', $this->command($model));
        $this->assertDeprecated('order(): a free SQL expression');

        $this->strict();
        $model->field('id')->order('LENGTH(name)');
        $this->assertRefused(function () use ($model) {
            $this->command($model);
        });

        $model->resetCommand();
        $model->field('id')->order('a.name', 'DESC')->order('2')->order(\Model::raw('FIELD(status, ?, ?)', [2, 1]));
        self::assertSame(
            'SELECT id FROM `recording` as `a`  ORDER BY a.name DESC, 2 ASC, FIELD(status, 2, 1) ASC',
            $this->command($model)
        );
    }

    // ---- 4. construtor de consulta -------------------------------------------

    public function testStrictWhereEscapesPlainValues(): void
    {
        $this->strict();
        $model = $this->model();
        $model->field('id')
            ->where('name', 'like', "A'%")->wAnd()
            ->where('a.status', 'IN', [1, '2'])->wAnd()
            ->where('status', 'NOT IN', 3)->wAnd()
            ->where('birth', 'IS NOT', null)->wOr()
            ->where('created', '>', \Model::raw('NOW()'));

        self::assertSame(
            "SELECT id FROM `recording` as `a`  WHERE name LIKE 'A\\'%' AND a.status IN (1, '2') AND"
            . " status NOT IN (3) AND birth IS NOT NULL OR created > NOW()",
            $this->command($model)
        );
    }

    public function testStrictWhereAcceptsSubqueryObjects(): void
    {
        $this->strict();
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

    /** @dataProvider refusedWhere */
    public function testStrictRefusesWhereOutsideTheContract($field, $op, $value): void
    {
        $this->strict();
        $model = $this->model();
        $model->field('id')->where($field, $op, $value);

        $this->assertRefused(function () use ($model) {
            $this->command($model);
        });
    }

    public function refusedWhere(): array
    {
        return [
            'operador fora da lista' => ['id', 'REGEXP', 'x'],
            'operador com SQL'       => ['id', '= 1 OR id =', 1],
            'campo com expressão'    => ['LOWER(name)', '=', 'x'],
            'IS com texto'           => ['id', 'IS', 'x'],
        ];
    }

    public function testWhereLegacyKeepsRawValueWithDeprecation(): void
    {
        $this->captureDeprecations();
        $model = $this->model();
        $model->field('id')->where('name', 'LIKE', '"A%"')->wAnd()->where('LOWER(name)', 'REGEXP', '"a"');

        self::assertSame(
            'SELECT id FROM `recording` as `a`  WHERE name LIKE "A%" AND LOWER(name) REGEXP "a"',
            $this->command($model)
        );
        $this->assertDeprecated('where(): a raw SQL value');
        $this->assertDeprecated('where(): a SQL expression as field');
        $this->assertDeprecated('where(): an operator outside');
    }

    public function testWhereRawAndHavingRawBindInBothModes(): void
    {
        foreach ([false, true] as $strict) {
            \Model::setStrict($strict);
            $this->captureDeprecations();
            $model = $this->model();
            $model->field('status')->field(\Model::raw('COUNT(*)'), 'total')
                ->whereRaw('a.name <> ? OR a.status IN (?)', ["x'y", [1, 2]])->wAnd()
                ->where('id', '>', 0)
                ->group('status')
                ->havingRaw('COUNT(*) > ?', [1])->hAnd()->havingRaw('status <> ?', [9]);

            self::assertSame(
                'SELECT status, COUNT(*) AS "total" FROM `recording` as `a`  WHERE a.name <> \'x\\\'y\' OR a.status IN (1, 2) AND id > 0'
                . ' GROUP BY status HAVING  COUNT(*) > 1  AND  status <> 9 ',
                $this->command($model)
            );
            self::assertSame([], $this->capturedDeprecations());
            $this->releaseDeprecationsAndStrictMode();
        }
    }

    /** @dataProvider refusedBuilder */
    public function testStrictRefusesRawBuilderParts(callable $build, string $deprecation): void
    {
        // Fora do estrito: funciona e avisa.
        $this->captureDeprecations();
        $model = $this->model();
        $build($model);
        $this->command($model);
        $this->assertDeprecated($deprecation);

        // No estrito: recusa.
        $this->strict();
        $model = $this->model();
        $build($model);
        $this->assertRefused(function () use ($model) {
            $this->command($model);
        });
    }

    public function refusedBuilder(): array
    {
        return [
            'field com expressão' => [static function ($m) { $m->field('COUNT(*)'); }, 'field(): a SQL expression'],
            'having em string'    => [static function ($m) { $m->field('id')->having('COUNT(*) > 1'); }, 'having(): a raw SQL string'],
            'group com expressão' => [static function ($m) { $m->field('id')->group('YEAR(created)'); }, 'group(): a SQL expression'],
            'join com tipo livre' => [static function ($m) { $m->field('id')->join('natural', 'other', 'a.id = b.rid'); }, 'join(): a join type outside'],
            'join com ON livre'   => [static function ($m) { $m->field('id')->join('left', 'other', '1=1'); }, 'join(): a SQL expression as join condition'],
        ];
    }

    public function testStrictAcceptsColumnJoinsAndRawJoinConditions(): void
    {
        $this->strict();
        $model = $this->model();
        $model->field('a.id')->field('b.*')
            ->join('left outer', 'other', 'a.id = b.recording_id AND a.status = b.status')
            ->join('INNER', 'third', \Model::raw('t.recording_id = a.id AND t.kind = ?', ['x']), 't');

        self::assertSame(
            'SELECT a.id, b.* FROM `recording` as `a`  LEFT OUTER JOIN `other` as b on a.id = b.recording_id AND a.status = b.status'
            . " INNER JOIN `third` as `t` on t.recording_id = a.id AND t.kind = 'x'",
            $this->command($model)
        );
    }

    /** @dataProvider refusedNames */
    public function testStrictRefusesInvalidTableAndAliasNames(callable $build): void
    {
        $this->strict();
        $model = $this->model();
        $build($model);

        $this->assertRefused(function () use ($model) {
            $this->command($model);
        });
    }

    public function refusedNames(): array
    {
        return [
            'alias do field'  => [static function ($m) { $m->field('id', 'x" FROM y --'); }],
            'tabela do field' => [static function ($m) { $m->field('id', '', 'a`b'); }],
            'from'            => [static function ($m) { $m->field('id')->from('a` b'); }],
            'tabela do join'  => [static function ($m) { $m->field('id')->join('left', 'a` b', 'a.id = b.id'); }],
            'alias do join'   => [static function ($m) { $m->field('id')->join('left', 'other', 'a.id = c.id', 'c`'); }],
        ];
    }

    // ---- bind ----------------------------------------------------------------

    public function testBindSqlSkipsQuotedQuestionMarks(): void
    {
        $sql = $this->model()->bindSql(
            "a = ? AND b = '?' AND c = \"it\\\"s?\" AND `d?` = ? AND e IN (?)",
            ['x', null, [true, 1.5]]
        );

        self::assertSame("a = 'x' AND b = '?' AND c = \"it\\\"s?\" AND `d?` = NULL AND e IN (1, 1.5)", $sql);
    }

    /** @dataProvider invalidBinds */
    public function testBindSqlRejectsMismatchesAndInvalidValues(string $sql, array $params): void
    {
        $model = $this->model();
        $this->assertRefused(function () use ($model, $sql, $params) {
            $model->bindSql($sql, $params);
        }, 0x201B);
    }

    public function invalidBinds(): array
    {
        return [
            'faltando valor'  => ['a = ? AND b = ?', [1]],
            'sobrando valor'  => ['a = ?', [1, 2]],
            'aspas abertas'   => ["a = 'x AND b = ?", [1]],
            'objeto'          => ['a = ?', [new \stdClass()]],
            'lista vazia'     => ['a IN (?)', [[]]],
            'lista aninhada'  => ['a IN (?)', [[[1]]]],
            'INF'             => ['a = ?', [INF]],
        ];
    }

    public function testRawRequiresNonEmptySql(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        \Gojira_Raw::sql(' ');
    }
}
