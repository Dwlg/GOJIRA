<?php

namespace Gojira\Tests\Integration\Auth;

use Gojira\Tests\Support\AuthFixtures;
use Gojira\Tests\Support\IntegrationTestCase;

/**
 * Gojira_SessionGuard: attempt, check, user e logout sobre a mesma sessão do
 * Login, com um guard por tipo de usuário.
 */
final class SessionGuardTest extends IntegrationTestCase
{
    use AuthFixtures;

    /** @var \Login */
    private $auth;

    /** @var array<string, int> */
    private $ids = [];

    protected function setUp(): void
    {
        $_SESSION = [];
        $_COOKIE  = [];

        $this->createAuthTables();
        $this->ids['ana']    = $this->addUser('ana', 'segredo', 1, 2);
        $this->ids['carlos'] = $this->addUser('carlos', 'segredo', 2, 2);
        $this->auth          = $this->newLogin();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_COOKIE  = [];
        parent::tearDown();
    }

    public function testGuardImplementsTheContract(): void
    {
        self::assertInstanceOf(\Gojira_Guard::class, $this->auth->guard('ADM'));
    }

    public function testAttemptReturnsTheAuthenticatedUser(): void
    {
        $user = $this->auth->guard('ADM')->attempt('ana', 'segredo');

        self::assertInstanceOf(\Gojira_Authenticatable::class, $user);
        self::assertEquals($this->ids['ana'], $user->getAuthId());
        self::assertSame(['ADM'], $user->getRoles());
    }

    public function testCheckIsFalseBeforeAndTrueAfterAttempt(): void
    {
        $guard = $this->auth->guard('ADM');

        self::assertFalse($guard->check());
        self::assertNull($guard->user());

        $guard->attempt('ana', 'segredo');

        self::assertTrue($guard->check());
        self::assertEquals($this->ids['ana'], $guard->user()->getAuthId());
    }

    public function testSessionOpenedByGuardIsSeenByLogin(): void
    {
        $this->auth->guard('ADM')->attempt('ana', 'segredo');

        self::assertTrue($this->auth->isLogged('ADM'));
        self::assertEquals($this->ids['ana'], $this->auth->getLogged('Cod', 'ADM'));
    }

    public function testSessionOpenedByLoginIsSeenByGuard(): void
    {
        $this->auth->login('ana', 'segredo', 'ADM');

        $guard = $this->auth->guard('ADM');
        self::assertTrue($guard->check());
        self::assertSame('ana', $guard->user()->getAuthLogin());
    }

    public function testLogoutClosesTheSessionForBothApis(): void
    {
        $guard = $this->auth->guard('ADM');
        $guard->attempt('ana', 'segredo');

        $guard->logout();

        self::assertFalse($guard->check());
        self::assertFalse($this->auth->isLogged('ADM'));
        self::assertArrayNotHasKey('ADMCod', $_SESSION);
    }

    public function testWrongPasswordThrowsAndLeavesNoSession(): void
    {
        $guard = $this->auth->guard('ADM');

        try {
            $guard->attempt('ana', 'outra');
            self::fail('senha errada autenticou');
        } catch (\Gojira_AuthException $e) {
            self::assertSame(\Gojira_AuthException::INVALID_PASSWORD, $e->getAuthCode());
        }

        self::assertFalse($guard->check());
    }

    public function testGuardOnlyAcceptsUsersOfItsType(): void
    {
        $this->expectException(\Gojira_AuthException::class);
        $this->expectExceptionCode(0x9001);

        $this->auth->guard('ADM')->attempt('carlos', 'segredo');
    }

    public function testExplicitTypesOverrideTheGuardType(): void
    {
        $user = $this->auth->guard('ADM')->attempt('carlos', 'segredo', ['CLI']);

        self::assertSame(['CLI'], $user->getRoles());
        self::assertTrue($this->auth->guard('CLI')->check());
    }

    public function testTwoTypesStayIsolated(): void
    {
        $adm = $this->auth->guard('ADM');
        $cli = $this->auth->guard('CLI');
        $adm->attempt('ana', 'segredo');
        $cli->attempt('carlos', 'segredo');

        $adm->logout();

        self::assertFalse($adm->check());
        self::assertTrue($cli->check());
        self::assertSame('carlos', $cli->user()->getAuthLogin());
    }

    public function testUserIsNullWhenTheAccountWasDeactivated(): void
    {
        $guard = $this->auth->guard('ADM');
        $guard->attempt('ana', 'segredo');

        self::$connection->run('UPDATE `' . $this->userTable . '` SET status = 1 WHERE id = ?', [$this->ids['ana']]);

        self::assertFalse($guard->check());
        self::assertNull($guard->user());
    }

    public function testRememberIsInertWithoutStore(): void
    {
        self::assertNull($this->auth->guard('ADM')->loginByRemember('abc:def'));
    }
}
