<?php

namespace Gojira\Tests\Integration;

use Gojira\Tests\Support\IntegrationTestCase;

/**
 * Mensagens de falha de conexão e de gravação sem credencial, host nem SQL;
 * o detalhe vai só para o log.
 */
final class ErrorMessageIntegrationTest extends IntegrationTestCase
{
    /** @var string */
    private $log;

    /** @var string|false */
    private $previousLog;

    protected function setUp(): void
    {
        $this->log         = sys_get_temp_dir() . '/gojira-errmsg-int-' . bin2hex(random_bytes(4)) . '.log';
        $this->previousLog = ini_set('error_log', $this->log);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->previousLog);
        @unlink($this->log);
        parent::tearDown();
    }

    public function testFalhaDeConexaoSemUsuarioNemHostNaMensagem(): void
    {
        $host = (string) getenv('GOJIRA_TEST_DB_HOST');
        $user = (string) getenv('GOJIRA_TEST_DB_USER');
        $name = (string) getenv('GOJIRA_TEST_DB_NAME');

        $message = null;
        try {
            // Connection guarda a conexão em propriedades estáticas: restaura no finally.
            $bad = new \Connection($host, $user, 'senha-errada-' . bin2hex(random_bytes(4)), $name);
            $bad->connect();
        } catch (\Exception $e) {
            $message = $e->getMessage();
            self::assertNull($e->getPrevious(), 'a exceção do mysqli não é encadeada');
        } finally {
            $good = new \Connection($host, $user, (string) getenv('GOJIRA_TEST_DB_PASSWORD'), $name);
            $good->connect();
        }

        self::assertNotNull($message, 'a conexão com senha errada deveria falhar');
        self::assertStringStartsWith('Erro ao conectar na base de dados', $message);
        self::assertStringContainsString('1045', $message, 'o código numérico continua na mensagem');
        self::assertStringNotContainsString($user . "'", $message);
        self::assertStringNotContainsString('@', $message);
        self::assertStringNotContainsString($host, $message);
        self::assertStringContainsString('Access denied', (string) file_get_contents($this->log));
    }

    public function testFalhaNoSaveRowSemSqlNaMensagem(): void
    {
        $model = $this->model($this->createTable(
            'id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(20) NOT NULL, UNIQUE KEY uq_code (code)'
        ));
        $model->saveRow(['code' => 'valor-unico']);

        // Com o relatório do mysqli desligado (padrão do PHP 8.0), a falha chega ao
        // ramo do Model em vez de virar mysqli_sql_exception.
        $driver   = new \mysqli_driver();
        $previous = $driver->report_mode;
        mysqli_report(MYSQLI_REPORT_OFF);

        try {
            $model->saveRow(['code' => 'valor-unico']);
            self::fail('a gravação duplicada deveria falhar');
        } catch (\ModelException $e) {
            self::assertSame(0x2015, $e->getCode());
            self::assertSame('Error while saving', $e->getMessage());
        } finally {
            mysqli_report($previous);
        }

        $log = (string) file_get_contents($this->log);
        self::assertStringContainsString('Model::saveRow', $log);
        self::assertStringContainsString('gj_test_', $log, 'o log identifica a tabela');
        self::assertStringNotContainsString('INSERT INTO', $log, 'o SQL montado não vai para o log');
    }

    public function testQueryLegadaInvalidaSemSqlNaMensagem(): void
    {
        try {
            self::$connection->query('SELECT marcador_sql_xyz FROM');
            self::fail('a consulta inválida deveria falhar');
        } catch (\Exception $e) {
            self::assertSame(DB_CONNECTION_INVALID_SQL, $e->getMessage());
            self::assertNull($e->getPrevious());
            self::assertNotInstanceOf(\mysqli_sql_exception::class, $e);
        }

        self::assertStringContainsString('1064', (string) file_get_contents($this->log), 'o erro do banco vai para o log');
    }

    public function testExecuteLegadoInvalidoSemSqlNaMensagem(): void
    {
        // PHP 8.0 (relatório desligado): devolve false, como antes. PHP 8.1+: lança,
        // como antes, mas uma ConnectionException genérica com o código do banco.
        try {
            $ret = self::$connection->execute('UPDATE marcador_sql_xyz SET');
            self::assertLessThan(80100, PHP_VERSION_ID, 'no PHP 8.1+ a falha continua lançando exceção');
            self::assertFalse($ret);
        } catch (\ConnectionException $e) {
            self::assertGreaterThanOrEqual(80100, PHP_VERSION_ID);
            self::assertSame(DB_CONNECTION_INVALID_SQL, $e->getMessage());
            self::assertSame(1064, $e->getCode());
            self::assertNull($e->getPrevious());
        }

        self::assertStringContainsString('1064', (string) file_get_contents($this->log));
    }
}
