<?php

namespace Gojira\Tests\Unit\Connection;

use PHPUnit\Framework\TestCase;

/**
 * Validação dos setters de charset e lc_time_names da Connection (sem banco).
 */
final class ConnectionLocaleConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        \Connection::setCharset(null);
        \Connection::setLcTimeNames(null);
    }

    public function testDefaultsMatchVersionOne(): void
    {
        self::assertSame('utf8', \Connection::getCharset());
        self::assertSame('pt_BR', \Connection::getLcTimeNames());
    }

    public function testEmptyValuesResetToDefaults(): void
    {
        \Connection::setLcTimeNames('en_US');
        \Connection::setLcTimeNames('');
        self::assertSame('pt_BR', \Connection::getLcTimeNames());

        \Connection::setCharset('');
        self::assertSame('utf8', \Connection::getCharset());
    }

    /** @return array<string, array{string}> */
    public function invalidCharsets(): array
    {
        return [
            'aspas'   => ["utf8'"],
            'espaço'  => ['utf8 mb4'],
            'ponto'   => ['utf8.mb4'],
            'longo'   => [str_repeat('a', 33)],
            'newline' => ["utf8mb4\n"],
        ];
    }

    /** @dataProvider invalidCharsets */
    public function testRejectsInvalidCharsetNames(string $charset): void
    {
        try {
            \Connection::setCharset($charset);
            self::fail('Charset inválido aceito: ' . var_export($charset, true));
        } catch (\InvalidArgumentException $e) {
            self::assertSame('utf8', \Connection::getCharset());
        }
    }

    /** @return array<string, array{string}> */
    public function invalidLcTimeNames(): array
    {
        return [
            'sem região'  => ['pt'],
            'hífen'       => ['pt-BR'],
            'minúsculas'  => ['pt_br'],
            'aspas'       => ["pt_BR'"],
            'espaço'      => ['pt_BR x'],
            'newline'     => ["pt_BR\n"],
        ];
    }

    /** @dataProvider invalidLcTimeNames */
    public function testRejectsInvalidLcTimeNames(string $name): void
    {
        try {
            \Connection::setLcTimeNames($name);
            self::fail('lc_time_names inválido aceito: ' . var_export($name, true));
        } catch (\InvalidArgumentException $e) {
            self::assertSame('pt_BR', \Connection::getLcTimeNames());
        }
    }
}
