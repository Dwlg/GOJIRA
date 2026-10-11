<?php

namespace Gojira\Tests\Support;

/**
 * Tabelas de usuário e de tipo para os testes de autenticação.
 *
 * Usa as tabelas descartáveis do IntegrationTestCase. Os tipos padrão são
 * ADM (id 1) e CLI (id 2); o status segue a convenção do Login
 * (0 pendente, 1 inativo, 2 ativo) ou, com a coluna active, -1, 0 e 1.
 */
trait AuthFixtures
{
    /** @var string */
    protected $userTable;

    /** @var string */
    protected $typeTable;

    /**
     * @param bool   $withTypeTable cria a tabela de tipos (sem ela, a coluna type guarda o código do tipo)
     * @param string $statusColumn  'status' ou 'active'
     * @param bool   $withName      cria a coluna name
     */
    protected function createAuthTables(bool $withTypeTable = true, string $statusColumn = 'status', bool $withName = true): void
    {
        if ($withTypeTable) {
            $this->typeTable = $this->createTable(
                'id INT PRIMARY KEY, shortcode VARCHAR(20) NOT NULL, name VARCHAR(50) NOT NULL'
            );
            self::$connection->execute(
                'INSERT INTO `' . $this->typeTable . '` (id, shortcode, name) VALUES (1, "ADM", "admin"), (2, "CLI", "client")'
            );
        } else {
            $this->typeTable = 'gj_test_missing_' . bin2hex(random_bytes(4));
        }

        $this->userTable = $this->createTable(
            'id INT AUTO_INCREMENT PRIMARY KEY, login VARCHAR(50) NOT NULL, email VARCHAR(100) NOT NULL,'
            . ' password VARCHAR(255) NOT NULL, type ' . ($withTypeTable ? 'INT' : 'VARCHAR(20)') . ' NOT NULL,'
            . ' `' . $statusColumn . '` INT NOT NULL'
            . ($withName ? ', name VARCHAR(50) NULL' : '')
        );
    }

    /**
     * Grava um usuário com a senha no formato legado (o mesmo de Login::toPassword).
     *
     * @param int|string $type
     */
    protected function addUser(string $login, string $password, $type = 1, int $status = 2, ?string $email = null, ?string $hash = null): int
    {
        $legacy = new \Gojira_LegacyHasher();
        $hash   = $hash ?? $legacy->hash($password);
        $email  = $email ?? $login . '@example.test';

        $columns = $this->columnNames();
        $fields  = ['login' => $login, 'email' => $email, 'password' => $hash, 'type' => (string) $type];
        $fields[in_array('status', $columns, true) ? 'status' : 'active'] = $status;
        if (in_array('name', $columns, true)) {
            $fields['name'] = 'Nome ' . $login;
        }

        self::$connection->run(
            'INSERT INTO `' . $this->userTable . '` (`' . implode('`, `', array_keys($fields)) . '`) VALUES ('
            . implode(', ', array_fill(0, count($fields), '?')) . ')',
            array_values($fields)
        );

        return (int) self::$connection->runGet('SELECT LAST_INSERT_ID() AS id')['id'];
    }

    protected function storedHash(int $id): string
    {
        return (string) self::$connection->runGet(
            'SELECT password FROM `' . $this->userTable . '` WHERE id = ?',
            [$id]
        )['password'];
    }

    protected function newLogin(): \Login
    {
        return new \Login(self::$connection, self::$globals->tools, $this->userTable, $this->typeTable);
    }

    /** @return string[] */
    private function columnNames(): array
    {
        return array_map(static function ($row) {
            return $row['Field'];
        }, self::$connection->runAll('SHOW COLUMNS FROM `' . $this->userTable . '`'));
    }
}
