<?php

namespace Gojira\Tests\Integration;

use Gojira\Tests\Support\IntegrationTestCase;

/**
 * Charset e lc_time_names configuráveis na Connection, contra MySQL/MariaDB real.
 */
final class ConnectionCharsetTest extends IntegrationTestCase
{
    /** Texto com emoji (4 bytes em UTF-8) e acentos. */
    private const TEXT = "Olá, GOJIRA \u{1F996}\u{1F525} ação";

    protected function tearDown(): void
    {
        parent::tearDown();
        \Connection::setCharset(null);
        \Connection::setLcTimeNames(null);
        self::$connection->reconnect();
    }

    /** @return array<string, string> */
    private function sessionVars(): array
    {
        return self::$connection->fetch(self::$connection->query(
            'SELECT @@character_set_client AS client, @@character_set_connection AS conn, '
            . '@@character_set_results AS results, @@lc_time_names AS lc'
        ));
    }

    public function testWithoutOptionConnectionStaysUtf8(): void
    {
        self::$connection->reconnect();
        $vars = $this->sessionVars();

        self::assertSame('utf8', \Connection::getCharset());
        self::assertContains($vars['client'], ['utf8', 'utf8mb3']);
        self::assertContains($vars['conn'], ['utf8', 'utf8mb3']);
        self::assertContains($vars['results'], ['utf8', 'utf8mb3']);
        self::assertSame('pt_BR', $vars['lc']);
        self::assertContains(self::$connection->connection->character_set_name(), ['utf8', 'utf8mb3']);
    }

    public function testUtf8mb4SetsSessionAndMysqliCharset(): void
    {
        \Connection::setCharset('utf8mb4');
        self::$connection->reconnect();
        $vars = $this->sessionVars();

        self::assertSame('utf8mb4', $vars['client']);
        self::assertSame('utf8mb4', $vars['conn']);
        self::assertSame('utf8mb4', $vars['results']);
        self::assertSame('pt_BR', $vars['lc']);
        // set_charset: o real_escape_string conhece o charset da conexão
        self::assertSame('utf8mb4', self::$connection->connection->character_set_name());
    }

    public function testUtf8mb4RoundTripsEmojiWithBoundParameters(): void
    {
        \Connection::setCharset('utf8mb4');
        self::$connection->reconnect();
        $table = $this->createTable('id INT AUTO_INCREMENT PRIMARY KEY, body VARCHAR(100) NOT NULL');

        self::assertTrue(self::$connection->run('INSERT INTO `' . $table . '` (body) VALUES (?)', [self::TEXT]));
        $row = self::$connection->runGet('SELECT body, CHAR_LENGTH(body) AS chars FROM `' . $table . '`');

        self::assertSame(self::TEXT, $row['body']);
        self::assertSame(mb_strlen(self::TEXT, 'UTF-8'), (int) $row['chars']);
    }

    public function testUtf8mb4RoundTripsEmojiWithEscapedValuesAndModel(): void
    {
        \Connection::setCharset('utf8mb4');
        self::$connection->reconnect();
        $table = $this->createTable('id INT AUTO_INCREMENT PRIMARY KEY, body VARCHAR(100) NOT NULL');

        self::assertTrue(self::$connection->execute(
            'INSERT INTO `' . $table . "` (body) VALUES ('" . self::$connection->escape(self::TEXT) . "')"
        ));
        $rows = $this->fetchAllRows('SELECT body FROM `' . $table . '`');
        self::assertSame(self::TEXT, $rows[0]['body']);

        $model = $this->model($table);
        self::assertSame(self::TEXT, $model->getData(1)[0]->body);
    }

    public function testSetCharsetAppliesToOpenConnection(): void
    {
        self::$connection->reconnect();
        \Connection::setCharset('utf8mb4');

        self::assertSame('utf8mb4', $this->sessionVars()['client']);
    }

    public function testCharsetUnknownToServerFailsAndKeepsPrevious(): void
    {
        self::$connection->reconnect();

        try {
            \Connection::setCharset('nao_existe');
            self::fail('Charset desconhecido aceito');
        } catch (\InvalidArgumentException $e) {
            self::fail('Nome válido rejeitado pela validação local');
        } catch (\Exception $e) {
            self::assertSame('utf8', \Connection::getCharset());
        }
    }

    public function testConnectWithCharsetUnknownToServerThrows(): void
    {
        $property = new \ReflectionProperty(\Connection::class, 'charset');
        $property->setAccessible(true);
        $property->setValue(null, 'nao_existe');

        try {
            // connect() sem disconnect(): a conexão atual continua aberta para o tearDown
            self::$connection->connect();
            self::fail('connect() aceitou charset desconhecido');
        } catch (\Exception $e) {
            self::assertStringContainsString('nao_existe', $e->getMessage());
        } finally {
            $property->setValue(null, 'utf8');
        }
    }

    public function testLcTimeNamesIsConfigurable(): void
    {
        \Connection::setLcTimeNames('en_US');
        self::$connection->reconnect();

        $row = self::$connection->runGet("SELECT @@lc_time_names AS lc, DATE_FORMAT('2024-01-15', '%M') AS month");
        self::assertSame('en_US', $row['lc']);
        self::assertSame('January', $row['month']);

        \Connection::setLcTimeNames(null);
        $row = self::$connection->runGet("SELECT DATE_FORMAT('2024-01-15', '%M') AS month");
        self::assertSame('janeiro', $row['month']);
    }
}
