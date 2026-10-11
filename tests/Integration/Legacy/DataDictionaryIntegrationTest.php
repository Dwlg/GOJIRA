<?php

namespace Gojira\Tests\Integration\Legacy;

use Gojira\Tests\Support\IntegrationTestCase;

require_once GOJIRA_SYSTEM_PATH . 'class/datadictionary.class.php';

/**
 * DataDictionary contra banco real: nomes com aspas e barra chegam como dado
 * na consulta ao information_schema (antes geravam SQL inválido).
 */
final class DataDictionaryIntegrationTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        set_error_handler(static function (): bool {
            return true;
        }, E_USER_DEPRECATED);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        parent::tearDown();
    }

    public function testSyncDbRunsOnTheTestDatabase(): void
    {
        $this->createTable('id INT PRIMARY KEY');

        (new \DataDictionary(self::$connection))->syncDb(self::$globals->database->name);

        $this->addToAssertionCount(1);
    }

    public function testNamesWithQuotesAndBackslashAreQueriedAsData(): void
    {
        $dictionary = new \DataDictionary(self::$connection);

        foreach (['a"b', "a'b", 'a\\b', 'x" OR "1"="1'] as $name) {
            $dictionary->syncDb($name);
            $dictionary->syncTable(self::$globals->database->name, $name);
        }

        $query = self::$connection->query(
            "SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'x\\\" OR \\\"1\\\"=\\\"1'"
        );
        self::assertSame(0, (int) self::$connection->fetch($query)['n']);
    }
}
