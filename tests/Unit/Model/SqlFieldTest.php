<?php

namespace Gojira\Tests\Unit\Model;

use Gojira\Tests\Support\TableColumn;

/**
 * Caracterização de Model::sqlField: como cada valor vira literal SQL.
 *
 * @group legacy-sql
 */
final class SqlFieldTest extends ModelTestCase
{
    private function sql(string $type, $value, bool $nullable = false)
    {
        return $this->model()->sqlField(TableColumn::make('col', $type, ['null' => $nullable]), $value);
    }

    public function testNullBecomesNullKeyword(): void
    {
        self::assertSame('NULL', $this->sql('varchar', null));
    }

    /** @dataProvider literalKeywords */
    public function testLiteralKeywordsPassThroughUnquoted(string $value): void
    {
        self::assertSame($value, $this->sql('varchar', $value));
    }

    public function literalKeywords(): array
    {
        return [
            'NULL'              => ['NULL'],
            'null minúsculo'    => ['null'],
            'CURRENT_TIMESTAMP' => ['CURRENT_TIMESTAMP'],
        ];
    }

    /** @dataProvider functionValues */
    public function testValuesShapedAsListedFunctionPassThroughUnquotedWithDeprecation(string $type, string $value): void
    {
        [$sql, $deprecations] = $this->withDeprecations(function () use ($type, $value) {
            return $this->sql($type, $value);
        });

        self::assertSame($value, $sql);
        self::assertCount(1, $deprecations);
        self::assertStringContainsString('SQL function with arguments', $deprecations[0]);
    }

    /** @dataProvider argumentlessFunctionValues */
    public function testFunctionsWithoutArgumentsPassThroughWithoutDeprecation(string $type, string $value): void
    {
        [$sql, $deprecations] = $this->withDeprecations(function () use ($type, $value) {
            return $this->sql($type, $value);
        });

        self::assertSame($value, $sql);
        self::assertSame([], $deprecations);
    }

    public function argumentlessFunctionValues(): array
    {
        return [
            'NOW() em datetime'  => ['datetime', 'NOW()'],
            'CURDATE() em date'  => ['date', 'CURDATE()'],
            'UUID() em varchar'  => ['varchar', 'UUID()'],
            'CURRENT_TIMESTAMP'  => ['timestamp', 'CURRENT_TIMESTAMP'],
        ];
    }

    public function testFunctionDeprecationIsEmittedOncePerRequest(): void
    {
        [, $deprecations] = $this->withDeprecations(function () {
            $this->sql('varchar', 'UPPER(a)');
            $this->sql('varchar', 'LOWER(b)');
        });

        self::assertCount(1, $deprecations);
    }

    public function functionValues(): array
    {
        return [
            'UPPER com argumento'        => ['varchar', 'UPPER(name)'],
            'minúsculas'                 => ['varchar', 'lower(name)'],
            'função aninhada'            => ['varchar', 'DATE_FORMAT(NOW(), "%Y")'],
            'função em coluna inteira'   => ['int', 'ROUND(price)'],
        ];
    }

    public function testUnlistedFunctionIsQuotedAsText(): void
    {
        self::assertSame('"CONCAT(a, b)"', $this->sql('varchar', 'CONCAT(a, b)'));
    }

    public function testTextIsEscapedWithConnectionEscape(): void
    {
        self::assertSame('"O\\\'Brien"', $this->sql('varchar', "O'Brien"));
        self::assertSame('"a\\"b"', $this->sql('text', 'a"b'));
        self::assertSame('"a\\\\b"', $this->sql('char', 'a\\b'));
    }

    public function testDateValueIsQuotedAndEscaped(): void
    {
        self::assertSame('"20241225"', $this->sql('date', '20241225'));
        self::assertSame('"2024-12-25 10:30:00"', $this->sql('datetime', '2024-12-25 10:30:00'));
        // Antes: '"2024"x"' (sem escape).
        self::assertSame('"2024\\"x"', $this->sql('datetime', '2024"x'));
    }

    public function testEmptyDateBecomesNullWhenColumnAcceptsNull(): void
    {
        self::assertSame('NULL', $this->sql('date', '', true));
        self::assertSame('""', $this->sql('date', '', false));
    }

    public function testIntegerValueIsReturnedAsGiven(): void
    {
        self::assertSame('42', $this->sql('int', '42'));
        self::assertSame(42, $this->sql('int', 42));
        self::assertSame('12.50', $this->sql('decimal', '12.50'));
    }

    public function testNumericFormsAreAcceptedInNumericColumns(): void
    {
        self::assertSame('-3', $this->sql('int', '-3'));
        self::assertSame(1.5, $this->sql('double', 1.5));
        self::assertSame('1e3', $this->sql('float', '1e3'));
        self::assertSame(1, $this->sql('tinyint', true));
        self::assertSame(0, $this->sql('tinyint', false));
    }

    /** @dataProvider nonNumericValues */
    public function testNonNumericValueInNumericColumnIsQuotedEscapedAndDeprecated(string $type, string $value, string $expected): void
    {
        // Antes o valor entrava no SQL sem aspas e sem escape. Agora vai como
        // texto escapado e o banco faz a conversão.
        [$sql, $deprecations] = $this->withDeprecations(function () use ($type, $value) {
            return $this->sql($type, $value);
        });

        self::assertSame($expected, $sql);
        self::assertCount(1, $deprecations);
        self::assertStringContainsString('non-numeric value', $deprecations[0]);
    }

    public function nonNumericValues(): array
    {
        return [
            'vírgula decimal' => ['decimal', '10,5', '"10,5"'],
            'moeda'           => ['decimal', 'R$ 10', '"R$ 10"'],
            'texto'           => ['int', 'abc', '"abc"'],
            'número e texto'  => ['int', '1 OR 1', '"1 OR 1"'],
            'aspas'           => ['decimal', '1"', '"1\\""'],
        ];
    }

    public function testNonScalarValueThrows(): void
    {
        $this->expectException(\ModelException::class);
        $this->expectExceptionCode(0x2017);

        $this->sql('int', [1]);
    }

    public function testEmptyIntegerBecomesNullOrZero(): void
    {
        self::assertSame('NULL', $this->sql('int', '', true));
        self::assertSame(0, $this->sql('int', '', false));
    }

    public function testEnumHandlesIntegersAndStrings(): void
    {
        self::assertSame(2, $this->sql('enum', 2));
        self::assertSame('NULL', $this->sql('enum', 0, true));
        self::assertSame(0, $this->sql('enum', 0, false));
        self::assertSame('"a"', $this->sql('enum', 'a'));
    }

    public function testEnumStringIsQuotedAndEscaped(): void
    {
        // Antes: '"a"b"' (sem escape).
        self::assertSame('"a\\"b"', $this->sql('enum', 'a"b'));
    }
}
