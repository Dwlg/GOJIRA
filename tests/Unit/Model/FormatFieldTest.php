<?php

namespace Gojira\Tests\Unit\Model;

use Gojira\Tests\Support\TableColumn;

/**
 * Caracterização de Model::formatField: limpeza do antiInjection e
 * conversão de datas antes do sqlField.
 *
 * @group legacy-sql
 */
final class FormatFieldTest extends ModelTestCase
{
    private function format(string $type, $value)
    {
        return $this->model()->formatField(TableColumn::make('col', $type), $value);
    }

    public function testNullIsKept(): void
    {
        self::assertNull($this->format('varchar', null));
    }

    /** @dataProvider textualTypes */
    public function testTextualTypesAreKeptIntact(string $type): void
    {
        $value = ' a -- b * c \\ <b>d</b> ';
        self::assertSame($value, $this->format($type, $value));
    }

    public function textualTypes(): array
    {
        return [['char'], ['varchar'], ['enum'], ['text'], ['mediumtext'], ['longtext'], ['tinytext']];
    }

    public function testNonTextualTypesRemoveAntiInjectionPatternsTagsAndSpaces(): void
    {
        self::assertSame('12', $this->format('int', ' 1--2 '));
        self::assertSame('5', $this->format('int', '<b>5</b>'));
        self::assertSame('3', $this->format('int', '3*'));
        self::assertSame('7', $this->format('decimal', '7\\'));
    }

    public function testTextualListIsMatchedBySubstring(): void
    {
        // A lista é uma string ("[char][varchar]...[mediumblob]..."), consultada
        // com strpos: "blob" casa dentro de "[mediumblob]" e é tratado como texto.
        self::assertSame('a--b', $this->format('blob', 'a--b'));
    }

    public function testKeywordListIsRemovedCaseInsensitively(): void
    {
        self::assertSame('x  y', $this->format('int', 'x Show Tables y'));
    }

    public function testBrazilianDateIsConvertedToCompactMysqlDate(): void
    {
        self::assertSame('20241225', $this->format('date', '25/12/2024'));
    }

    public function testBrazilianDateTimeIsConvertedToCompactMysqlDateTime(): void
    {
        self::assertSame('20241225103000', $this->format('datetime', '25/12/2024 10:30:00'));
        self::assertSame('20241225103000', $this->format('datetime', '25/12/2024 10:30'));
        self::assertSame('20241225103000', $this->format('timestamp', '25/12/2024 10:30:00'));
    }

    public function testIsoDateIsSlicedAsIfItWereBrazilianFormat(): void
    {
        self::assertSame('2-254-20', $this->format('date', '2024-12-25'));
    }

    public function testDateWithUnexpectedLengthIsKept(): void
    {
        self::assertSame('2024', $this->format('date', '2024'));
    }

    public function testFunctionValueInDateColumnIsNotConverted(): void
    {
        self::assertSame('NOW()', $this->format('datetime', 'NOW()'));
    }
}
