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
}
