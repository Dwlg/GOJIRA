<?php

namespace Gojira\Tests\Unit\Legacy;

use PHPUnit\Framework\TestCase;

require_once GOJIRA_SYSTEM_PATH . 'class/datadictionary.class.php';

/**
 * DataDictionary: valores escapados nas consultas ao information_schema e aviso de descontinuação.
 */
final class DataDictionaryTest extends TestCase
{
    /** @var string[] */
    private $deprecations = [];

    protected function setUp(): void
    {
        $this->deprecations = [];
        set_error_handler(function (int $errno, string $errstr): bool {
            $this->deprecations[] = $errstr;

            return true;
        }, E_USER_DEPRECATED);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
    }

    public function testSyncTableQuotesValuesWithoutEscapeMethod(): void
    {
        $conn = new DataDictionaryFakeConnection();
        (new \DataDictionary($conn))->syncTable("ba\"se'", "ta\\b\nela");

        self::assertCount(1, $conn->sql);
        self::assertStringContainsString("TABLE_SCHEMA = 'ba\\\"se\\''", $conn->sql[0]);
        self::assertStringContainsString("TABLE_NAME = 'ta\\\\b\\nela'", $conn->sql[0]);
    }

    public function testSyncDbQuotesTheDatabaseName(): void
    {
        $conn = new DataDictionaryFakeConnection();
        (new \DataDictionary($conn))->syncDb('x" OR "1"="1');

        self::assertSame(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'x\\\" OR \\\"1\\\"=\\\"1'",
            $conn->sql[0]
        );
    }

    public function testUsesConnectionEscapeWhenAvailable(): void
    {
        $conn = new DataDictionaryEscapingFakeConnection();
        (new \DataDictionary($conn))->syncTable('db', 'tb');

        self::assertSame(['db', 'tb'], $conn->escaped);
        self::assertStringContainsString("TABLE_SCHEMA = '[db]'", $conn->sql[0]);
        self::assertStringContainsString("TABLE_NAME = '[tb]'", $conn->sql[0]);
    }

    /**
     * @runInSeparateProcess
     */
    public function testConstructorEmitsDeprecationOncePerRequest(): void
    {
        new \DataDictionary(new DataDictionaryFakeConnection());
        new \DataDictionary(new DataDictionaryFakeConnection());

        self::assertCount(1, $this->deprecations);
        self::assertStringContainsString('DataDictionary', $this->deprecations[0]);
    }
}

/** Conexão falsa sem escape(): grava o SQL e não devolve linhas. */
class DataDictionaryFakeConnection
{
    /** @var string[] */
    public $sql = [];

    public function query($sql)
    {
        $this->sql[] = $sql;

        return true;
    }

    public function fetch($query)
    {
        return null;
    }
}

/** Conexão falsa com escape() marcando o valor, como a Connection::escape. */
class DataDictionaryEscapingFakeConnection extends DataDictionaryFakeConnection
{
    /** @var string[] */
    public $escaped = [];

    public function escape($value)
    {
        $this->escaped[] = $value;

        return '[' . $value . ']';
    }
}
