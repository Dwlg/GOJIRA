<?php

namespace Gojira\Tests\Integration\Auth;

use Gojira\Tests\Support\AuthFixtures;
use Gojira\Tests\Support\IntegrationTestCase;
use Gojira\Tests\Support\RacingRememberStore;
use Gojira\Tests\Support\RecordingGuard;
use Gojira\Tests\Support\RecordingLogin;

/**
 * Revogação do remember-me pelo Login (logout direto e troca de senha) e
 * rotação atômica do token, com o reuso do mesmo cookie tratado como cópia.
 */
final class RememberRevocationTest extends IntegrationTestCase
{
    use AuthFixtures;

    /** @var RecordingLogin */
    private $auth;

    /** @var \Gojira_TableRememberTokenStore */
    private $store;

    /** @var string */
    private $tokenTable;

    /** @var array<string, int> */
    private $ids = [];

    protected function setUp(): void
    {
        $_SESSION = [];
        $_COOKIE  = [];

        $this->createAuthTables();
        $this->ids['ana']    = $this->addUser('ana', 'segredo', 1, 2);
        $this->ids['carlos'] = $this->addUser('carlos', 'segredo', 2, 2);
        $this->auth          = new RecordingLogin(self::$connection, self::$globals->tools, $this->userTable, $this->typeTable);

        $this->tokenTable = $this->createTable(
            'selector CHAR(24) NOT NULL PRIMARY KEY, validator_hash CHAR(64) NOT NULL, user_id INT NOT NULL,'
            . ' expires_at INT NOT NULL, user_agent VARCHAR(255) NULL, created_at INT NOT NULL'
        );
        $this->store = new \Gojira_TableRememberTokenStore(self::$connection, $this->tokenTable);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_COOKIE  = [];
        parent::tearDown();
    }

    /** @return array<int, array<string, mixed>> */
    private function tokens(?int $userId = null): array
    {
        $rows = self::$connection->runAll('SELECT * FROM `' . $this->tokenTable . '`');
        if ($userId === null) {
            return $rows;
        }

        return array_values(array_filter($rows, static function ($row) use ($userId) {
            return (int) $row['user_id'] === $userId;
        }));
    }

    private function guard($store = null, string $type = 'ADM'): RecordingGuard
    {
        return new RecordingGuard($this->auth, $type, $store ?? $this->store);
    }

    /** Login com remember e o cookie de volta, como se lido de outro navegador. */
    private function rememberAna(): string
    {
        $guard = $this->guard();
        $guard->attempt('ana', 'segredo', [], true);

        return $guard->lastCookie()['value'];
    }

    private function newVisit(): void
    {
        $_SESSION = [];
        $_COOKIE  = [];
    }

    // Revogação pelo Login

    public function testGuardWithStoreRegistersItInLogin(): void
    {
        $this->auth->guard('ADM', $this->store);

        self::assertSame($this->store, $this->auth->getRememberTokenStore());
    }

    public function testGuardWithoutStoreUsesTheRegisteredOne(): void
    {
        $this->auth->setRememberTokenStore($this->store);

        $property = new \ReflectionProperty(\Gojira_SessionGuard::class, 'rememberStore');
        $property->setAccessible(true);

        self::assertSame($this->store, $property->getValue($this->auth->guard('ADM')));
    }

    public function testDirectLogoutRevokesTheCookieToken(): void
    {
        $this->auth->setRememberTokenStore($this->store);
        $this->rememberAna();
        self::assertCount(1, $this->tokens());

        $this->auth->logout('ADM');

        self::assertSame([], $this->tokens());
        self::assertArrayNotHasKey('remember_adm', $_COOKIE);
        $cleared = $this->auth->cookiesByName()['remember_adm'];
        self::assertSame('', $cleared['value']);
        self::assertSame('/', $cleared['options']['path']);
        self::assertLessThan(time(), $cleared['options']['expires']);
    }

    public function testDirectLogoutRevokesEvenAfterTheSessionExpired(): void
    {
        $this->auth->setRememberTokenStore($this->store);
        $value    = $this->rememberAna();
        $_SESSION = [];

        $this->auth->logout('ADM');

        self::assertSame([], $this->tokens());
        self::assertNull($this->guard()->loginByRemember($value));
    }

    public function testDirectLogoutUsesTheRegisteredCookieName(): void
    {
        $this->auth->guard('ADM', $this->store, 'lembrar');
        [$selector, $validator] = $this->store->issue($this->ids['ana'], 3600);
        $_COOKIE['lembrar']     = $selector . ':' . $validator;

        $this->auth->logout('ADM');

        self::assertSame([], $this->tokens());
        self::assertArrayNotHasKey('lembrar', $_COOKIE);
    }

    public function testDirectLogoutLeavesOtherTypesTokens(): void
    {
        $this->auth->setRememberTokenStore($this->store);
        $this->rememberAna();
        $cli = $this->guard(null, 'CLI');
        $cli->attempt('carlos', 'segredo', [], true);

        $this->auth->logout('ADM');

        self::assertCount(0, $this->tokens($this->ids['ana']));
        self::assertCount(1, $this->tokens($this->ids['carlos']));
        self::assertArrayHasKey('remember_cli', $_COOKIE);
    }

    public function testLogoutWithoutStoreDoesNotTouchRememberCookie(): void
    {
        $_COOKIE['remember_adm'] = 'a:b';

        $this->auth->logout('ADM');

        self::assertSame('a:b', $_COOKIE['remember_adm']);
        self::assertSame([], $this->auth->cookies);
    }

    public function testStolenTokenStopsWorkingAfterPasswordChange(): void
    {
        $this->auth->setRememberTokenStore($this->store);
        $stolen = $this->rememberAna();
        $this->guard(null, 'CLI')->attempt('carlos', 'segredo', [], true);
        $this->newVisit();

        $this->auth->changePass($this->ids['ana'], 'senha-nova');

        self::assertSame([], $this->tokens($this->ids['ana']), 'todos os dispositivos da ana');
        self::assertCount(1, $this->tokens($this->ids['carlos']), 'os outros usuários não são afetados');
        self::assertNull($this->guard()->loginByRemember($stolen));
        self::assertSame([], $_SESSION);
    }

    public function testPasswordChangeThroughGuardRegistrationRevokes(): void
    {
        $this->auth->guard('ADM', $this->store);
        $this->store->issue($this->ids['ana'], 3600);
        $this->store->issue($this->ids['ana'], 3600);

        $this->auth->changePass($this->ids['ana'], 'senha-nova');

        self::assertSame([], $this->tokens());
    }

    public function testPasswordChangeWithoutStoreStillChangesThePassword(): void
    {
        $this->store->issue($this->ids['ana'], 3600);

        $this->auth->changePass($this->ids['ana'], 'senha-nova');

        self::assertCount(1, $this->tokens(), 'sem store registrado, o Login não conhece os tokens');
        self::assertEquals($this->ids['ana'], $this->auth->login('ana', 'senha-nova', 'ADM'));
    }

    // Rotação atômica

    public function testRotateConsumesTheTokenOnlyOnce(): void
    {
        [$selector] = $this->store->issue($this->ids['ana'], 3600);
        $hash       = $this->store->findBySelector($selector)->getValidatorHash();

        $first  = $this->store->rotate($selector, $hash, $this->ids['ana'], 3600);
        $second = $this->store->rotate($selector, $hash, $this->ids['ana'], 3600);

        self::assertIsArray($first);
        self::assertNull($second);
        self::assertCount(1, $this->tokens(), 'só o token emitido pela primeira rotação');
        self::assertSame($first[0], $this->tokens()[0]['selector']);
    }

    public function testRotateRequiresTheValidatorHash(): void
    {
        [$selector] = $this->store->issue($this->ids['ana'], 3600);

        self::assertNull($this->store->rotate($selector, str_repeat('0', 64), $this->ids['ana'], 3600));
        self::assertCount(1, $this->tokens(), 'o token não é apagado com hash errado');
        self::assertSame($selector, $this->tokens()[0]['selector']);
    }

    public function testSameCookieUsedTwiceAtOnceRevokesEveryToken(): void
    {
        $value = $this->rememberAna();
        $this->store->issue($this->ids['ana'], 3600);       // outro dispositivo
        $this->store->issue($this->ids['carlos'], 3600);
        $this->newVisit();
        $guard = $this->guard(new RacingRememberStore($this->store));

        self::assertNull($guard->loginByRemember($value));

        self::assertSame([], $this->tokens($this->ids['ana']), 'reuso do cookie revoga todos os tokens do usuário');
        self::assertCount(1, $this->tokens($this->ids['carlos']));
        self::assertSame([], $_SESSION, 'quem perdeu a disputa não abre sessão');
        self::assertSame('', $guard->lastCookie()['value'], 'e o cookie é apagado');
    }

    public function testWithoutRaceTheSameStoreWrapperLogsIn(): void
    {
        $value = $this->rememberAna();
        $this->newVisit();
        $racing       = new RacingRememberStore($this->store);
        $racing->race = false;

        self::assertNotNull($this->guard($racing)->loginByRemember($value));
        self::assertTrue($this->auth->isLogged('ADM'));
    }

    public function testFailedRotationOpensNoSessionAndIssuesNoCookie(): void
    {
        $value = $this->rememberAna();
        $this->newVisit();
        $guard = $this->guard(new RacingRememberStore($this->store));

        $guard->loginByRemember($value);

        foreach ($guard->cookies as $cookie) {
            self::assertSame('', $cookie['value'], 'nenhum token novo enviado');
        }
        self::assertFalse($this->auth->isLogged('ADM'));
    }
}
