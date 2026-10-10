<?php

namespace Gojira\Tests\Integration;

use Gojira\Tests\Support\IntegrationTestCase;

/**
 * Login::login contra banco real: escape do usuário e senha sempre com hash
 * no fluxo do formulário.
 */
final class LoginTest extends IntegrationTestCase
{
    /** @var string */
    private $userTable;

    /** @var string */
    private $typeTable;

    /** @var \Login */
    private $login;

    /** @var array<string, int> */
    private $codes = [];

    protected function setUp(): void
    {
        $_SESSION = [];
        $_COOKIE  = [];

        $this->typeTable = $this->createTable(
            'id INT PRIMARY KEY, shortcode VARCHAR(20) NOT NULL, name VARCHAR(50) NOT NULL'
        );
        $this->userTable = $this->createTable(
            'id INT AUTO_INCREMENT PRIMARY KEY, login VARCHAR(50) NOT NULL, email VARCHAR(100) NOT NULL,'
            . ' password VARCHAR(255) NOT NULL, type INT NOT NULL, status INT NOT NULL, name VARCHAR(50) NULL'
        );

        self::$connection->execute(
            'INSERT INTO `' . $this->typeTable . '` (id, shortcode, name) VALUES (1, "ADM", "admin"), (2, "CLI", "client")'
        );

        $this->login = new \Login(self::$connection, self::$globals->tools, $this->userTable, $this->typeTable);

        $this->codes['ana']     = $this->addUser('ana', 'ana@example.test', 'segredo', 1, 2);
        $this->codes['o"neil']  = $this->addUser('o"neil', 'oneil@example.test', 'segredo', 1, 2);
        $this->codes["d'avila"] = $this->addUser("d'avila", 'davila@example.test', 'segredo', 1, 2);
        $this->codes['bia']     = $this->addUser('bia', 'bia@example.test', 'segredo', 1, 1);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_COOKIE  = [];
        parent::tearDown();
    }

    private function addUser(string $login, string $email, string $password, int $type, int $status): int
    {
        $c = self::$connection;
        $c->execute(
            'INSERT INTO `' . $this->userTable . '` (login, email, password, type, status, name) VALUES ("'
            . $c->escape($login) . '", "' . $c->escape($email) . '", "' . $c->escape($this->login->toPassword($password))
            . '", ' . $type . ', ' . $status . ', "' . $c->escape($login) . '")'
        );

        return (int) $c->fetch($c->query('SELECT LAST_INSERT_ID() AS id'))['id'];
    }

    private function storedHash(string $login): string
    {
        $c = self::$connection;

        return $c->fetch($c->query(
            'SELECT password FROM `' . $this->userTable . '` WHERE login = "' . $c->escape($login) . '"'
        ))['password'];
    }

    public function testValidCredentialsReturnUserCodeAndFillSession(): void
    {
        $code = $this->login->login('ana', 'segredo', 'admin');

        self::assertEquals($this->codes['ana'], $code);
        self::assertTrue($_SESSION['ADMLogged']);
        self::assertEquals($this->codes['ana'], $_SESSION['ADMCod']);
    }

    public function testLoginByEmailStillWorks(): void
    {
        self::assertEquals($this->codes['ana'], $this->login->login('ana@example.test', 'segredo', 'admin'));
    }

    public function testWrongPasswordIsRejected(): void
    {
        $this->expectExceptionCode(0x9003);
        $this->login->login('ana', 'outra', 'admin');
    }

    public function testInactiveUserIsRejected(): void
    {
        $this->expectExceptionCode(0x9002);
        $this->login->login('bia', 'segredo', 'admin');
    }

    /** @return array<string, array{string}> */
    public function quotedLogins(): array
    {
        return [
            'aspas duplas'  => ['o"neil'],
            'aspas simples' => ["d'avila"],
        ];
    }

    /** @dataProvider quotedLogins */
    public function testQuotesInLoginAreMatchedLiterally(string $login): void
    {
        self::assertEquals($this->codes[$login], $this->login->login($login, 'segredo', 'admin'));
    }

    /** @return array<string, array{string}> */
    public function unknownQuotedLogins(): array
    {
        return [
            'aspas duplas no fim'  => ['ana"'],
            'aspas simples no fim' => ["ana'"],
            'barra invertida'      => ['ana\\'],
            'aspas no meio'        => ['an"a'],
        ];
    }

    /**
     * Um usuário com aspas que não existe é só um usuário inválido: a consulta
     * continua a mesma e não há erro de SQL nem login.
     *
     * @dataProvider unknownQuotedLogins
     */
    public function testUnknownLoginWithQuotesIsInvalidUser(string $login): void
    {
        $this->expectExceptionCode(0x9001);
        $this->login->login($login, 'segredo', 'admin');
    }

    public function testStoredHashSentThroughFormDoesNotAuthenticate(): void
    {
        $hash = $this->storedHash('ana');
        self::assertSame(128, strlen($hash));

        $this->expectExceptionCode(0x9003);
        $this->login->login('ana', $hash, 'admin');
    }

    public function testServerSideHashFlagAuthenticatesWithStoredHash(): void
    {
        $code = $this->login->login('ana', $this->storedHash('ana'), 'admin', true);

        self::assertEquals($this->codes['ana'], $code);
    }

    public function testServerSideHashFlagRejectsPlainPassword(): void
    {
        $this->expectExceptionCode(0x9003);
        $this->login->login('ana', 'segredo', 'admin', true);
    }

    public function testGetTypeEscapesTypeValues(): void
    {
        self::assertSame('("1")', $this->login->getType('admin'));
        self::assertSame('("1","2")', $this->login->getType('ADM,client'));
        self::assertSame('()', $this->login->getType('admin"'));
    }

    public function testCookieNamesAreLowercase(): void
    {
        self::assertSame('admcod', $this->login->cookieName('ADM', 'Cod'));
        self::assertSame('admtypecod', $this->login->cookieName('adm', 'TypeCod'));
    }

    /** @param int|string $cod */
    private function setLoginCookies($cod): void
    {
        $_COOKIE = [];
        foreach (['Logged' => '1', 'Cod' => (string) $cod, 'Name' => 'ana', 'Login' => 'ana', 'Email' => 'ana@example.test'] as $field => $value) {
            $_COOKIE[$this->login->cookieName('ADM', $field)] = $value;
        }
    }

    public function testCookieModeRecognizesActiveUser(): void
    {
        $this->login->setCookie(true);
        $this->setLoginCookies($this->codes['ana']);

        self::assertTrue($this->login->isLogged('ADM'));
        self::assertEquals($this->codes['ana'], $this->login->getLogged('Cod', 'ADM'));
    }

    /** @return array<string, array{string}> */
    public function tamperedCookieCodes(): array
    {
        return [
            'usuário inexistente' => ['999999'],
            'aspas duplas'        => ['"x'],
            'aspas simples'       => ["'x"],
            'texto'               => ['abc'],
        ];
    }

    /** @dataProvider tamperedCookieCodes */
    public function testCookieModeRejectsTamperedCode(string $cod): void
    {
        $this->login->setCookie(true);
        $this->setLoginCookies($cod);

        self::assertFalse($this->login->isLogged('ADM'));
    }

    public function testCookieModeRejectsInactiveUser(): void
    {
        $this->login->setCookie(true);
        $this->setLoginCookies($this->codes['bia']);

        self::assertFalse($this->login->isLogged('ADM'));
    }

    public function testCookieModeIgnoresMixedCaseNames(): void
    {
        $this->login->setCookie(true);
        $_COOKIE = ['admLogged' => '1', 'admCod' => (string) $this->codes['ana'], 'admName' => 'ana', 'admLogin' => 'ana', 'admEmail' => 'ana@example.test'];

        self::assertFalse($this->login->isLogged('ADM'));
    }

    public function testCookieModeIsOffByDefault(): void
    {
        $this->setLoginCookies($this->codes['ana']);

        self::assertFalse($this->login->isLogged('ADM'));
    }

    private function loginWithoutTypeTable(): \Login
    {
        return new \Login(self::$connection, self::$globals->tools, $this->userTable, 'gj_test_missing_type_table');
    }

    public function testGetTypeEscapesValueWithoutTypeTable(): void
    {
        $login = $this->loginWithoutTypeTable();

        self::assertSame('("1")', $login->getType('1'));
        self::assertSame('("1\\"")', $login->getType('1"'));
        self::assertSame('1"', $login->getType('1"', true));
    }

    public function testLoginWithoutTypeTableTreatsQuotedTypeAsValue(): void
    {
        $this->expectExceptionCode(0x9001);
        $this->loginWithoutTypeTable()->login('ana', 'segredo', 'adm"');
    }

    private static function isModernHash(string $hash): bool
    {
        return !empty(password_get_info($hash)['algo']);
    }

    /** @var string|null */
    private $errorLogFile;

    /** @var string|false */
    private $previousErrorLog = false;

    /** Desvia o error_log para um arquivo temporário e devolve o caminho. */
    private function captureErrorLog(): string
    {
        $this->errorLogFile     = (string) tempnam(sys_get_temp_dir(), 'gjlog');
        $this->previousErrorLog = ini_set('error_log', $this->errorLogFile);

        return $this->errorLogFile;
    }

    /** @after */
    public function restoreErrorLog(): void
    {
        if ($this->errorLogFile !== null) {
            ini_set('error_log', (string) $this->previousErrorLog);
            @unlink($this->errorLogFile);
            $this->errorLogFile = null;
        }
    }

    private function lastCommandIsNotUpdate(): void
    {
        self::assertStringNotContainsString('UPDATE', (string) self::$connection->get_lastCommand(), 'o login não deve regravar a senha');
    }

    // Regravação desligada (padrão da 1.x)

    public function testRehashIsOffByDefault(): void
    {
        self::assertFalse(LGN_PASSWORD_REHASH);
    }

    public function testLegacyHashAuthenticatesAndIsKeptByDefault(): void
    {
        $legacy = $this->storedHash('ana');
        self::assertSame($this->login->toPassword('segredo'), $legacy);

        self::assertEquals($this->codes['ana'], $this->login->login('ana', 'segredo', 'admin'));
        $this->lastCommandIsNotUpdate();
        self::assertSame($legacy, $this->storedHash('ana'));
    }

    public function testModernHashAuthenticatesWithRehashOff(): void
    {
        $modern = password_hash('segredo', PASSWORD_BCRYPT, ['cost' => 4]);
        self::$connection->run('UPDATE `' . $this->userTable . '` SET password = ? WHERE id = ?', [$modern, $this->codes['ana']]);

        self::assertEquals($this->codes['ana'], $this->login->login('ana', 'segredo', 'admin'));
        self::assertSame($modern, $this->storedHash('ana'));
    }

    public function testServerSideHashFlagWithLegacyHashAndRehashOff(): void
    {
        $legacy = $this->storedHash('ana');

        self::assertEquals($this->codes['ana'], $this->login->login('ana', $legacy, 'admin', true));
        self::assertSame($legacy, $this->storedHash('ana'));
    }

    // Regravação ligada (opt-in)

    public function testLegacyHashIsRehashedWithPasswordHashWhenEnabled(): void
    {
        $legacy = $this->storedHash('ana');

        $this->login->setPasswordRehash(true);
        self::assertEquals($this->codes['ana'], $this->login->login('ana', 'segredo', 'admin'));

        $stored = $this->storedHash('ana');
        self::assertNotSame($legacy, $stored);
        self::assertTrue(self::isModernHash($stored), 'o hash legado deve ser regravado com password_hash');
        self::assertTrue(password_verify('segredo', $stored));
        self::assertSame(\Gojira_BcryptHasher::DEFAULT_COST, password_get_info($stored)['options']['cost']);
    }

    public function testSetPasswordRehashCanBeTurnedOffAgain(): void
    {
        $legacy = $this->storedHash('ana');

        $this->login->setPasswordRehash(true);
        $this->login->setPasswordRehash(false);
        $this->login->login('ana', 'segredo', 'admin');

        self::assertSame($legacy, $this->storedHash('ana'));
    }

    public function testRehashTouchesOnlyTheAuthenticatedUser(): void
    {
        $before = $this->storedHash('o"neil');

        $this->login->setPasswordRehash(true);
        $this->login->login('ana', 'segredo', 'admin');

        self::assertSame($before, $this->storedHash('o"neil'));
    }

    public function testModernHashAuthenticatesAndIsKept(): void
    {
        $this->login->setPasswordRehash(true);
        $this->login->login('ana', 'segredo', 'admin');
        $modern   = $this->storedHash('ana');
        $_SESSION = [];

        self::assertEquals($this->codes['ana'], $this->login->login('ana', 'segredo', 'admin'));
        $this->lastCommandIsNotUpdate();
        self::assertSame($modern, $this->storedHash('ana'), 'hash moderno atualizado não é regravado');
    }

    public function testWrongPasswordAgainstModernHashIsRejected(): void
    {
        $this->login->setPasswordRehash(true);
        $this->login->login('ana', 'segredo', 'admin');

        $this->expectExceptionCode(0x9003);
        $this->login->login('ana', 'outra', 'admin');
    }

    public function testModernHashSentThroughFormDoesNotAuthenticate(): void
    {
        $this->login->setPasswordRehash(true);
        $this->login->login('ana', 'segredo', 'admin');

        $this->expectExceptionCode(0x9003);
        $this->login->login('ana', $this->storedHash('ana'), 'admin');
    }

    public function testFailedLoginDoesNotRehash(): void
    {
        $legacy = $this->storedHash('ana');
        $this->login->setPasswordRehash(true);

        try {
            $this->login->login('ana', 'outra', 'admin');
            self::fail('senha errada deveria ser rejeitada');
        } catch (\Exception $e) {
            self::assertSame(0x9003, $e->getCode());
        }

        self::assertSame($legacy, $this->storedHash('ana'));
    }

    /**
     * Login por $pwd_hash = true (hash calculado pelo servidor) antes e depois
     * da migração: ele compara o hash gravado, então depois da migração só o
     * hash novo vale.
     */
    public function testServerSideHashFlagBeforeAndAfterRehash(): void
    {
        $legacy = $this->storedHash('ana');
        $this->login->setPasswordRehash(true);

        self::assertEquals($this->codes['ana'], $this->login->login('ana', $legacy, 'admin', true));
        self::assertSame($legacy, $this->storedHash('ana'), '$pwd_hash = true não regrava');

        $this->login->login('ana', 'segredo', 'admin');
        $modern = $this->storedHash('ana');
        self::assertTrue(self::isModernHash($modern));

        self::assertEquals($this->codes['ana'], $this->login->login('ana', $modern, 'admin', true));

        $this->expectExceptionCode(0x9003);
        $this->login->login('ana', $legacy, 'admin', true);
    }

    public function testOutdatedModernHashIsRehashed(): void
    {
        $old = password_hash('segredo', PASSWORD_BCRYPT, ['cost' => 4]);
        self::$connection->run('UPDATE `' . $this->userTable . '` SET password = ? WHERE id = ?', [$old, $this->codes['ana']]);

        $this->login->setPasswordRehash(true);
        $this->login->setHasher(new \Gojira_BcryptHasher(new \Gojira_LegacyHasher(), PASSWORD_BCRYPT, ['cost' => 5]));
        $this->login->login('ana', 'segredo', 'admin');

        $stored = $this->storedHash('ana');
        self::assertNotSame($old, $stored);
        self::assertSame(5, password_get_info($stored)['options']['cost']);
    }

    /**
     * Outra requisição troca a senha entre a verificação e a regravação: a
     * regravação não sobrescreve a senha nova, e o login segue válido.
     */
    public function testRehashDoesNotOverwritePasswordChangedConcurrently(): void
    {
        $log      = $this->captureErrorLog();
        $userId   = $this->codes['ana'];
        $table    = $this->userTable;
        $replaced = password_hash('trocada', PASSWORD_BCRYPT, ['cost' => 4]);

        $this->login->setPasswordRehash(true);
        $this->login->setHasher(new CallbackHasher(static function () use ($table, $userId, $replaced): void {
            self::$connection->run('UPDATE `' . $table . '` SET password = ? WHERE id = ?', [$replaced, $userId]);
        }));

        self::assertEquals($userId, $this->login->login('ana', 'segredo', 'admin'));
        self::assertSame($replaced, $this->storedHash('ana'));
        self::assertStringContainsString('não regravado', (string) file_get_contents($log));
    }

    /** Uma falha ao gravar o hash novo não impede um login válido. */
    public function testRehashFailureDoesNotBlockLogin(): void
    {
        $log    = $this->captureErrorLog();
        $legacy = $this->storedHash('ana');

        $this->login->setPasswordRehash(true);
        $this->login->setHasher(new CallbackHasher(null, str_repeat('x', 300)));

        self::assertEquals($this->codes['ana'], $this->login->login('ana', 'segredo', 'admin'));
        self::assertTrue($_SESSION['ADMLogged']);
        self::assertSame($legacy, $this->storedHash('ana'));
        self::assertStringContainsString('falha ao regravar', (string) file_get_contents($log));
    }

    public function testLegacyHasherKeepsLegacyHashes(): void
    {
        $legacy = $this->storedHash('ana');

        $this->login->setPasswordRehash(true);
        $this->login->setHasher(new \Gojira_LegacyHasher());
        self::assertEquals($this->codes['ana'], $this->login->login('ana', 'segredo', 'admin'));
        $this->lastCommandIsNotUpdate();

        self::assertSame($legacy, $this->storedHash('ana'));
    }

    // Gravação de senha: uma única flag decide o formato

    public function testChangePassStoresLegacyHashByDefault(): void
    {
        self::assertTrue($this->login->changePass($this->codes['ana'], "nova'senha"));

        self::assertSame($this->login->toPassword("nova'senha"), $this->storedHash('ana'));
        self::assertEquals($this->codes['ana'], $this->login->login('ana', "nova'senha", 'admin'));
    }

    public function testChangePassStoresBcryptWhenEnabled(): void
    {
        $this->login->setPasswordRehash(true);
        self::assertTrue($this->login->changePass($this->codes['ana'], "nova'senha"));

        $stored = $this->storedHash('ana');
        self::assertTrue(self::isModernHash($stored));
        self::assertTrue(password_verify("nova'senha", $stored));
        self::assertSame($this->login->toPassword('segredo'), $this->storedHash('o"neil'));

        self::assertEquals($this->codes['ana'], $this->login->login('ana', "nova'senha", 'admin'));
    }

    public function testHashPasswordFollowsTheFlag(): void
    {
        self::assertSame($this->login->toPassword('x'), $this->login->hashPassword('x'));

        $this->login->setPasswordRehash(true);
        $hash = $this->login->hashPassword('x');
        self::assertTrue(self::isModernHash($hash));
        self::assertTrue(password_verify('x', $hash));
    }

    /**
     * Simulação de rollback: senha trocada com a flag desligada continua
     * entrando num Login que só conhece o esquema legado.
     */
    public function testPasswordChangedWithFlagOffWorksWithPureLegacyHasher(): void
    {
        $this->login->changePass($this->codes['ana'], 'nova');
        $_SESSION = [];

        $legacyOnly = new \Login(self::$connection, self::$globals->tools, $this->userTable, $this->typeTable);
        $legacyOnly->setHasher(new \Gojira_LegacyHasher());

        self::assertEquals($this->codes['ana'], $legacyOnly->login('ana', 'nova', 'admin'));
    }

    public function testInjectedHasherTakesPrecedenceOverFlagOff(): void
    {
        $this->login->setHasher(new \Gojira_BcryptHasher(new \Gojira_LegacyHasher(), PASSWORD_BCRYPT, ['cost' => 4]));
        $this->login->changePass($this->codes['ana'], 'nova');

        $stored = $this->storedHash('ana');
        self::assertTrue(self::isModernHash($stored));
        self::assertSame(4, password_get_info($stored)['options']['cost']);
    }

    public function testInjectedHasherTakesPrecedenceOverFlagOn(): void
    {
        $this->login->setPasswordRehash(true);
        $this->login->setHasher(new \Gojira_LegacyHasher());
        $this->login->changePass($this->codes['ana'], 'nova');

        self::assertSame($this->login->toPassword('nova'), $this->storedHash('ana'));
    }
}

/**
 * Hasher de teste: valida como o padrão (bcrypt com fallback legado), sempre
 * pede rehash e, ao gerar o hash novo, roda um callback ou devolve um valor fixo.
 */
final class CallbackHasher implements \Gojira_PasswordHasher
{
    /** @var callable|null */
    private $onHash;

    /** @var string|null */
    private $fixedHash;

    /** @var \Gojira_BcryptHasher */
    private $inner;

    public function __construct(?callable $onHash, ?string $fixedHash = null)
    {
        $this->onHash    = $onHash;
        $this->fixedHash = $fixedHash;
        $this->inner     = new \Gojira_BcryptHasher(new \Gojira_LegacyHasher(), PASSWORD_BCRYPT, ['cost' => 4]);
    }

    public function hash(string $plain): string
    {
        if ($this->onHash !== null) {
            ($this->onHash)();
        }

        return $this->fixedHash ?? $this->inner->hash($plain);
    }

    public function verify(string $plain, string $hash): bool
    {
        return $this->inner->verify($plain, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return true;
    }
}
