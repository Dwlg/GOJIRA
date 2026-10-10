<?php

namespace Gojira\Tests\Integration\Auth;

use Gojira\Tests\Support\AuthFixtures;
use Gojira\Tests\Support\IntegrationTestCase;
use Gojira\Tests\Support\RecordingGuard;
use Gojira\Tests\Support\RecordingLogin;

/**
 * Troca do id de sessão no login, no logout e no remember-me, com uma sessão
 * real do PHP.
 *
 * Cada teste roda em processo próprio: no processo principal o PHPUnit já
 * escreveu na saída, e depois disso o PHP não troca o id da sessão.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SessionRegenerationTest extends IntegrationTestCase
{
    use AuthFixtures;

    /** @var RecordingLogin */
    private $auth;

    /** @var int */
    private $anaId;

    protected function setUp(): void
    {
        // Sessão sem cookie e sem cache limiter: o CLI não tem cabeçalhos HTTP.
        ini_set('session.use_cookies', '0');
        ini_set('session.cache_limiter', '');
        ini_set('session.save_path', sys_get_temp_dir());

        $_SESSION = [];
        $_COOKIE  = [];

        $this->createAuthTables();
        $this->anaId = $this->addUser('ana', 'segredo', 1, 2);
        $this->auth  = new RecordingLogin(self::$connection, self::$globals->tools, $this->userTable, $this->typeTable);
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $_SESSION = [];
        parent::tearDown();
    }

    /** Sessão real do PHP, aberta pelo construtor do Login. */
    private function startRealSession(): string
    {
        if (headers_sent()) {
            self::fail('cabeçalhos já enviados; a troca do id de sessão não pode ser testada');
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];

        return session_id();
    }

    public function testLoginRegeneratesTheSessionIdAndKeepsData(): void
    {
        $before                  = $this->startRealSession();
        $_SESSION['carrinho']    = [42];

        $this->auth->login('ana', 'segredo', 'ADM');

        self::assertNotSame($before, session_id());
        self::assertSame([42], $_SESSION['carrinho']);
        self::assertTrue($_SESSION['ADMLogged']);
    }

    public function testLogoutRegeneratesTheSessionId(): void
    {
        $this->startRealSession();
        $this->auth->login('ana', 'segredo', 'ADM');
        $afterLogin = session_id();

        $this->auth->logout('ADM');

        self::assertNotSame($afterLogin, session_id());
        self::assertArrayNotHasKey('ADMCod', $_SESSION);
    }

    public function testFailedLoginKeepsTheSessionId(): void
    {
        $before = $this->startRealSession();

        try {
            $this->auth->login('ana', 'outra', 'ADM');
        } catch (\Gojira_AuthException $e) {
        }

        self::assertSame($before, session_id());
    }

    public function testRememberLoginRegeneratesTheSessionId(): void
    {
        $table = $this->createTable(
            'selector CHAR(24) NOT NULL PRIMARY KEY, validator_hash CHAR(64) NOT NULL, user_id INT NOT NULL,'
            . ' expires_at INT NOT NULL, user_agent VARCHAR(255) NULL, created_at INT NOT NULL'
        );
        $store = new \Gojira_TableRememberTokenStore(self::$connection, $table);
        [$selector, $validator] = $store->issue($this->anaId, 3600);
        $before                 = $this->startRealSession();

        $user = (new RecordingGuard($this->auth, 'ADM', $store))->loginByRemember($selector . ':' . $validator);

        self::assertNotNull($user);
        self::assertNotSame($before, session_id());
    }

    public function testWithoutActiveSessionNothingIsRegenerated(): void
    {
        session_write_close();
        self::assertNotSame(PHP_SESSION_ACTIVE, session_status());
        $_SESSION = [];

        self::assertEquals($this->anaId, $this->auth->login('ana', 'segredo', 'ADM'));
        self::assertTrue($_SESSION['ADMLogged']);
    }
}
