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
    public function testValuesShapedAsListedFunctionPassThroughUnquoted(string $type, string $value): void
    {
        self::assertSame($value, $this->sql($type, $value));
    }

    public function functionValues(): array
    {
        return [
            'NOW() em datetime'          => ['datetime', 'NOW()'],
            'CURDATE() em date'          => ['date', 'CURDATE()'],
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

    public function testTextUsesAddslashes(): void
    {
        self::assertSame('"O\\\'Brien"', $this->sql('varchar', "O'Brien"));
        self::assertSame('"a\\"b"', $this->sql('text', 'a"b'));
        self::assertSame('"a\\\\b"', $this->sql('char', 'a\\b'));
    }

    public function testDateValueIsQuotedWithoutEscaping(): void
    {
        self::assertSame('"20241225"', $this->sql('date', '20241225'));
        self::assertSame('"2024"x"', $this->sql('datetime', '2024"x'));
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

    public function testNonNumericValueInIntegerColumnIsReturnedUnquoted(): void
    {
        self::assertSame('abc', $this->sql('int', 'abc'));
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

    public function testEnumStringIsQuotedWithoutEscaping(): void
    {
        self::assertSame('"a"b"', $this->sql('enum', 'a"b'));
    }
}
