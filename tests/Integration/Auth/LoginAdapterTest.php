<?php

namespace Gojira\Tests\Integration\Auth;

use Gojira\Tests\Support\AuthFixtures;
use Gojira\Tests\Support\CountingHasher;
use Gojira\Tests\Support\InMemoryUserProvider;
use Gojira\Tests\Support\IntegrationTestCase;

/**
 * Login como adapter do UserProvider: códigos de erro, provider próprio,
 * login sem tabela de tipos, tempo de resposta para usuário inexistente e
 * migração de hash pelo provider.
 */
final class LoginAdapterTest extends IntegrationTestCase
{
    use AuthFixtures;

    protected function setUp(): void
    {
        $_SESSION = [];
        $_COOKIE  = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_COOKIE  = [];
        parent::tearDown();
    }

    private function catchAuth(callable $fn): \Gojira_AuthException
    {
        try {
            $fn();
        } catch (\Gojira_AuthException $e) {
            return $e;
        }
        self::fail('Gojira_AuthException não lançada');
    }

    /** @return array<string, array{string, string, int, int, string}> */
    public function failures(): array
    {
        return [
            'usuário inexistente' => ['ninguem', 'segredo', 2, 0x9001, \Gojira_AuthException::INVALID_USER],
            'senha errada'        => ['ana', 'outra', 2, 0x9003, \Gojira_AuthException::INVALID_PASSWORD],
            'conta inativa'       => ['ana', 'segredo', 1, 0x9002, \Gojira_AuthException::INACTIVE],
            'conta pendente'      => ['ana', 'segredo', 0, 0x9004, \Gojira_AuthException::PENDING],
        ];
    }

    /** @dataProvider failures */
    public function testFailuresKeepLegacyCodeAndAddAuthCode(string $login, string $password, int $status, int $code, string $authCode): void
    {
        $this->createAuthTables();
        $this->addUser('ana', 'segredo', 1, $status);
        $auth = $this->newLogin();

        $e = $this->catchAuth(static function () use ($auth, $login, $password) {
            $auth->login($login, $password, 'ADM');
        });

        self::assertInstanceOf(\Exception::class, $e, 'quem captura Exception continua capturando');
        self::assertSame($code, $e->getCode());
        self::assertSame($authCode, $e->getAuthCode());
        self::assertSame([], $_SESSION);
    }

    public function testPendingWithActiveColumnSchema(): void
    {
        $this->createAuthTables(true, 'active');
        $this->addUser('ana', 'segredo', 1, -1);
        $auth = $this->newLogin();

        $e = $this->catchAuth(static function () use ($auth) {
            $auth->login('ana', 'segredo', 'ADM');
        });

        self::assertSame(0x9004, $e->getCode());
    }

    public function testLoginWorksWithoutTypeTable(): void
    {
        $this->createAuthTables(false);
        $id = $this->addUser('ana', 'segredo', 'ADM');
        $this->addUser('carlos', 'segredo', 'CLI');
        $auth = $this->newLogin();

        self::assertEquals($id, $auth->login('ana', 'segredo', 'ADM'));
        self::assertTrue($_SESSION['ADMLogged']);
        self::assertEquals($id, $_SESSION['ADMCod']);
        self::assertSame('ADM', $_SESSION['ADMType']);
        self::assertTrue($auth->isLogged('ADM'));

        $e = $this->catchAuth(static function () use ($auth) {
            $auth->login('carlos', 'segredo', 'ADM');
        });
        self::assertSame(0x9001, $e->getCode());
    }

    public function testCustomProviderReplacesTheTable(): void
    {
        $this->createAuthTables();
        $auth     = $this->newLogin();
        $provider = new InMemoryUserProvider();
        $hash     = (new \Gojira_LegacyHasher())->hash('segredo');
        $provider->add(new \Gojira_GenericUser(77, 'externo', 'externo@example.test', $hash, 1, 'ADM', 'Externo', true));

        $auth->setUserProvider($provider);
        $auth->configure($this->userTable, $this->typeTable);

        self::assertSame($provider, $auth->getUserProvider(), 'configure() preserva o provider injetado');
        self::assertSame(77, $auth->login('externo', 'segredo', 'ADM'));
        self::assertSame('Externo', $_SESSION['ADMName']);
    }

    public function testCustomProviderUserWithoutStatusDetailIsInactive(): void
    {
        $this->createAuthTables();
        $auth     = $this->newLogin();
        $provider = new InMemoryUserProvider();
        $provider->add(new \Gojira_GenericUser(5, 'x', '', 'h', 1, 'ADM', '', false));
        $auth->setUserProvider($provider);

        $e = $this->catchAuth(static function () use ($auth) {
            $auth->login('x', 'segredo', 'ADM');
        });

        self::assertSame(\Gojira_AuthException::INACTIVE, $e->getAuthCode());
    }

    public function testUnknownUserSpendsOneHashOperation(): void
    {
        $this->createAuthTables();
        $auth   = $this->newLogin();
        $hasher = new CountingHasher();
        $auth->setHasher($hasher);

        $this->catchAuth(static function () use ($auth) {
            $auth->login('ninguem', 'segredo', 'ADM');
        });

        self::assertSame(1, $hasher->calls['hash'], 'usuário inexistente gasta o custo de um hash');
    }

    public function testUnknownUserWithServerSideHashSpendsNothing(): void
    {
        $this->createAuthTables();
        $auth   = $this->newLogin();
        $hasher = new CountingHasher();
        $auth->setHasher($hasher);

        $this->catchAuth(static function () use ($auth) {
            $auth->login('ninguem', str_repeat('a', 128), 'ADM', true);
        });

        self::assertSame(0, $hasher->calls['hash']);
    }

    public function testUnknownUserDoesNotWriteAnything(): void
    {
        $this->createAuthTables();
        $id     = $this->addUser('ana', 'segredo');
        $before = $this->storedHash($id);
        $auth   = $this->newLogin();
        $auth->setPasswordRehash(true);

        $this->catchAuth(static function () use ($auth) {
            $auth->login('ninguem', 'segredo', 'ADM');
        });

        self::assertSame($before, $this->storedHash($id));
    }

    public function testRehashGoesThroughTheProviderWithTheVerifiedHash(): void
    {
        $this->createAuthTables();
        $auth     = $this->newLogin();
        $provider = new InMemoryUserProvider();
        $legacy   = (new \Gojira_LegacyHasher())->hash('segredo');
        $provider->add(new \Gojira_GenericUser(9, 'ext', 'ext@example.test', $legacy, 1, 'ADM', 'Ext', true));
        $auth->setUserProvider($provider);
        $auth->setPasswordRehash(true);

        $auth->login('ext', 'segredo', 'ADM');

        self::assertCount(1, $provider->updates);
        [$id, $newHash, $expected] = $provider->updates[0];
        self::assertSame(9, $id);
        self::assertSame($legacy, $expected);
        self::assertTrue(password_verify('segredo', $newHash));
    }

    public function testRehashIsNotAttemptedWhenTheFlagIsOff(): void
    {
        $this->createAuthTables();
        $auth     = $this->newLogin();
        $provider = new InMemoryUserProvider();
        $provider->add(new \Gojira_GenericUser(9, 'ext', '', (new \Gojira_LegacyHasher())->hash('segredo'), 1, 'ADM', '', true));
        $auth->setUserProvider($provider);

        $auth->login('ext', 'segredo', 'ADM');

        self::assertSame([], $provider->updates);
    }
}
