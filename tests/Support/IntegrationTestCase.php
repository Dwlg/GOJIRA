<?php

namespace Gojira\Tests\Support;

use PHPUnit\Framework\TestCase;

/*
   Base dos testes de integração. Conecta num banco MySQL/MariaDB descartável
   indicado pelas variáveis de ambiente GOJIRA_TEST_DB_HOST, GOJIRA_TEST_DB_USER,
   GOJIRA_TEST_DB_PASSWORD e GOJIRA_TEST_DB_NAME.

   Sem banco, os testes falham com mensagem explícita. Eles não são pulados.
*/
abstract class IntegrationTestCase extends TestCase
{
    /** @var \Connection */
    protected static $connection;

    /** @var \stdClass */
    protected static $globals;

    /** @var string[] tabelas criadas pelo teste atual */
    private $createdTables = [];

    public static function setUpBeforeClass(): void
    {
        $config = self::dbConfig();

        $missing = array_keys(array_filter($config, static function ($v) {
            return $v === false;
        }));
        if ($missing !== []) {
            self::fail(
                'Banco de teste não configurado. Defina as variáveis de ambiente: '
                . implode(', ', $missing)
                . '. Localmente: docker compose -f tests/docker-compose.yml run --rm php84 tools/phpunit/vendor/bin/phpunit --testsuite integration'
            );
        }

        try {
            $connection = new \Connection(
                $config['GOJIRA_TEST_DB_HOST'],
                $config['GOJIRA_TEST_DB_USER'],
                $config['GOJIRA_TEST_DB_PASSWORD'],
                $config['GOJIRA_TEST_DB_NAME']
            );
            $connection->connect();
        } catch (\Throwable $e) {
            self::fail('Não foi possível conectar no banco de teste em ' . $config['GOJIRA_TEST_DB_HOST'] . ': ' . $e->getMessage());
        }

        self::$connection = $connection;

        $globals                     = new \stdClass();
        $globals->tools              = new \Tool();
        $globals->database           = new \stdClass();
        $globals->database->host     = $config['GOJIRA_TEST_DB_HOST'];
        $globals->database->user     = $config['GOJIRA_TEST_DB_USER'];
        $globals->database->password = $config['GOJIRA_TEST_DB_PASSWORD'];
        $globals->database->name     = $config['GOJIRA_TEST_DB_NAME'];
        $globals->connection         = $connection;
        self::$globals               = $globals;
    }

    protected function tearDown(): void
    {
        foreach ($this->createdTables as $table) {
            self::$connection->execute('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $this->createdTables = [];
    }

    /**
     * Cria uma tabela com nome único para o teste e a remove no tearDown.
     *
     * @param string $columnsDdl definição das colunas, sem o CREATE TABLE
     */
    protected function createTable(string $columnsDdl): string
    {
        $table = 'gj_test_' . bin2hex(random_bytes(4));
        $ok    = self::$connection->execute(
            'CREATE TABLE `' . $table . '` (' . $columnsDdl . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        self::assertTrue($ok, 'Falha ao criar a tabela de teste ' . $table);
        $this->createdTables[] = $table;

        return $table;
    }

    protected function model(string $table): \Fixture_Model
    {
        $model = new \Fixture_Model(self::$globals);
        $model->set_table($table);

        return $model;
    }

    protected function fetchAllRows(string $sql): array
    {
        return self::$connection->fetchAll(self::$connection->query($sql));
    }

    /** @return array<string, string|false> */
    private static function dbConfig(): array
    {
        $config = [];
        foreach (['GOJIRA_TEST_DB_HOST', 'GOJIRA_TEST_DB_USER', 'GOJIRA_TEST_DB_PASSWORD', 'GOJIRA_TEST_DB_NAME'] as $name) {
            $config[$name] = getenv($name);
        }

        return $config;
    }
}
