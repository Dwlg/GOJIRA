<?php

namespace Gojira\Tests\Integration;

use Gojira\Tests\Support\IntegrationTestCase;

/**
 * Connection::escape usa o real_escape_string da conexão aberta.
 */
final class ConnectionEscapeTest extends IntegrationTestCase
{
    public function testEscapesQuotesAndBackslash(): void
    {
        self::assertSame('a\\"b\\\'c\\\\d', self::$connection->escape('a"b\'c\\d'));
    }

    public function testNullBecomesEmptyStringAndScalarsAreCast(): void
    {
        self::assertSame('', self::$connection->escape(null));
        self::assertSame('42', self::$connection->escape(42));
    }

    public function testEscapedValueRoundTripsInsideQuotes(): void
    {
        $table = $this->createTable('id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50) NOT NULL');
        $value = 'o"neil \'x\' \\ fim';

        self::$connection->execute('INSERT INTO `' . $table . '` (name) VALUES ("' . self::$connection->escape($value) . '")');
        $rows = $this->fetchAllRows(
            'SELECT name FROM `' . $table . '` WHERE name = "' . self::$connection->escape($value) . '"'
        );

        self::assertCount(1, $rows);
        self::assertSame($value, $rows[0]['name']);
    }
}
