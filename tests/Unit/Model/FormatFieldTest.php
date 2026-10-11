<?php

namespace Gojira\Tests\Unit\Model;

use Gojira\Tests\Support\TableColumn;

/**
 * Caracterização de Model::formatField: normalização e conversão de datas
 * antes do sqlField. Desde a PR5 o antiInjection não é mais chamado aqui;
 * a proteção é o escape do sqlField.
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

    public function testNumericAndDateTypesAreTrimmedAndCastToString(): void
    {
        self::assertSame('12', $this->format('int', ' 12 '));
        self::assertSame('5', $this->format('int', 5));
        self::assertSame('1', $this->format('tinyint', true));
        self::assertSame('', $this->format('tinyint', false));
        self::assertSame('12.5', $this->format('decimal', 12.5));
    }

    public function testNonTextualValuesAreNoLongerRewritten(): void
    {
        // Antes o antiInjection apagava "--", "*", "\\", tags e palavras-chave
        // ('1--2' virava '12'). Agora o valor segue como veio e o sqlField
        // rejeita o que não é número.
        self::assertSame('1--2', $this->format('int', ' 1--2 '));
        self::assertSame('<b>5</b>', $this->format('int', '<b>5</b>'));
        self::assertSame('7\\', $this->format('decimal', '7\\'));
    }

    public function testOtherTypesAreKeptIntact(): void
    {
        $json = ' {"a": "x -- y * z \\\\n <b>"} ';
        self::assertSame($json, $this->format('json', $json));
        self::assertSame('a*b', $this->format('set', 'a*b'));
    }

    public function testAntiInjectionIsNotCalled(): void
    {
        [, $deprecations] = $this->withDeprecations(function () {
            $this->format('int', '1');
            $this->format('varchar', 'x');
        });

        self::assertSame([], $deprecations);
    }

    public function testTextualListIsMatchedBySubstring(): void
    {
        // A lista é uma string ("[char][varchar]...[mediumblob]..."), consultada
        // com strpos: "blob" casa dentro de "[mediumblob]" e é tratado como texto.
        self::assertSame('a--b', $this->format('blob', 'a--b'));
    }

    public function testKeywordsAreNoLongerRemoved(): void
    {
        self::assertSame('x Show Tables y', $this->format('int', 'x Show Tables y'));
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

    /** @dataProvider mysqlDates */
    public function testDateAlreadyInMysqlFormatIsKept(string $type, string $value): void
    {
        // Antes a data ISO era fatiada como se fosse dd/mm/aaaa: '2024-12-25' virava '2-254-20'.
        self::assertSame($value, $this->format($type, $value));
    }

    public function mysqlDates(): array
    {
        return [
            'date ISO'              => ['date', '2024-12-25'],
            'date compacta'         => ['date', '20241225'],
            'datetime ISO'          => ['datetime', '2024-12-25 10:30:00'],
            'datetime ISO sem seg.' => ['datetime', '2024-12-25 10:30'],
            'datetime com T'        => ['datetime', '2024-12-25T10:30:00'],
            'timestamp ISO'         => ['timestamp', '2024-12-25 10:30:00'],
            'datetime compacta'     => ['datetime', '20241225103000'],
        ];
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
