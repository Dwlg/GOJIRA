<?php

namespace Gojira\Tests\Integration\Auth;

use Gojira\Tests\Support\AuthFixtures;
use Gojira\Tests\Support\IntegrationTestCase;
use Gojira\Tests\Support\RecordingGuard;

/**
 * Remember-me selector/validator: emissão, uso com rotação, revogação e
 * cookies com HttpOnly, SameSite=Lax e Secure em HTTPS.
 */
final class RememberMeTest extends IntegrationTestCase
{
    use AuthFixtures;

    /** @var \Login */
    private $auth;

    /** @var \Gojira_TableRememberTokenStore */
    private $store;

    /** @var string */
    private $tokenTable;

    /** @var string[] tabelas criadas pelo próprio store, removidas no tearDown */
    private $storeTables = [];

    /** @var array<string, int> */
    private $ids = [];

    protected function setUp(): void
    {
        $_SESSION = [];
        $_COOKIE  = [];
        unset($_SERVER['HTTPS']);

        $this->createAuthTables();
        $this->ids['ana']    = $this->addUser('ana', 'segredo', 1, 2);
        $this->ids['carlos'] = $this->addUser('carlos', 'segredo', 2, 2);
        $this->auth          = $this->newLogin();

        $this->tokenTable = $this->newStoreTable();
        $this->store      = new \Gojira_TableRememberTokenStore(self::$connection, $this->tokenTable);
        $this->store->createTableIfMissing();
    }

    protected function tearDown(): void
    {
        foreach ($this->storeTables as $table) {
            self::$connection->execute('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $_SESSION = [];
        $_COOKIE  = [];
        unset($_SERVER['HTTPS']);
        parent::tearDown();
    }

    private function newStoreTable(): string
    {
        $table               = 'gj_test_' . bin2hex(random_bytes(4));
        $this->storeTables[] = $table;

        return $table;
    }

    private function guard(string $type = 'ADM', bool $withStore = true): RecordingGuard
    {
        return new RecordingGuard($this->auth, $type, $withStore ? $this->store : null);
    }

    /** @return array<int, array<string, mixed>> */
    private function tokens(): array
    {
        return self::$connection->runAll('SELECT * FROM `' . $this->tokenTable . '`');
    }

    /** Faz login com remember e simula uma visita nova (sessão vazia). */
    private function rememberedVisit(): string
    {
        $first = $this->guard();
        $first->attempt('ana', 'segredo', [], true);
        $value    = $first->lastCookie()['value'];
        $_SESSION = [];
        $_COOKIE  = [];

        return $value;
    }

    private function assertClearsCookie(RecordingGuard $guard): void
    {
        $cookie = $guard->lastCookie();
        self::assertNotNull($cookie);
        self::assertSame('', $cookie['value']);
        self::assertLessThan(time(), $cookie['options']['expires']);
    }

    public function testStoreDoesNotCreateItsTableByItself(): void
    {
        $table = $this->newStoreTable();
        new \Gojira_TableRememberTokenStore(self::$connection, $table);

        self::assertFalse(self::$connection->tableExist($table));
    }

    public function testCreateTableIfMissingCreatesOnceAndIsIdempotent(): void
    {
        self::assertTrue(self::$connection->tableExist($this->tokenTable));

        $this->store->issue($this->ids['ana'], 3600);
        $this->store->createTableIfMissing();

        self::assertCount(1, $this->tokens(), 'chamar de novo não recria a tabela');
    }

    public function testNoCookieWithoutRememberFlag(): void
    {
        $guard = $this->guard();
        $guard->attempt('ana', 'segredo');

        self::assertSame([], $guard->cookies);
        self::assertSame([], $this->tokens());
    }

    public function testRememberIsInertWithoutStore(): void
    {
        $guard = $this->guard('ADM', false);
        $guard->attempt('ana', 'segredo', [], true);

        self::assertSame([], $guard->cookies);
        self::assertTrue($guard->check(), 'o login em si continua funcionando');
        self::assertNull($guard->attemptFromRememberCookie());
    }

    public function testRememberIssuesSelectorAndValidatorCookie(): void
    {
        $guard = $this->guard();
        $guard->attempt('ana', 'segredo', [], true);

        $cookie = $guard->lastCookie();
        self::assertSame('remember_adm', $cookie['name']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{24}:[0-9a-f]{64}$/', $cookie['value']);
        self::assertSame($cookie['value'], $_COOKIE['remember_adm']);
    }

    public function testRememberCookieFlagsOverHttp(): void
    {
        $guard = $this->guard();
        $guard->attempt('ana', 'segredo', [], true);

        $options = $guard->lastCookie()['options'];
        self::assertTrue($options['httponly']);
        self::assertSame('Lax', $options['samesite']);
        self::assertSame('/', $options['path']);
        self::assertFalse($options['secure'], 'em HTTP o navegador descartaria um cookie Secure');
        self::assertEqualsWithDelta(time() + 2592000, $options['expires'], 5);
    }

    /** @return array<string, array{string, bool}> */
    public function httpsValues(): array
    {
        return ['on' => ['on', true], '1' => ['1', true], 'off' => ['off', false], 'vazio' => ['', false]];
    }

    /** @dataProvider httpsValues */
    public function testRememberCookieIsSecureOnlyOverHttps(string $https, bool $secure): void
    {
        $_SERVER['HTTPS'] = $https;
        $guard            = $this->guard();
        $guard->attempt('ana', 'segredo', [], true);

        self::assertSame($secure, $guard->lastCookie()['options']['secure']);
    }

    public function testDatabaseKeepsOnlyTheValidatorHash(): void
    {
        $guard = $this->guard();
        $guard->attempt('ana', 'segredo', [], true);
        [$selector, $validator] = explode(':', $guard->lastCookie()['value']);

        $rows = $this->tokens();
        self::assertCount(1, $rows);
        self::assertSame($selector, $rows[0]['selector']);
        self::assertSame(hash('sha256', $validator), $rows[0]['validator_hash']);
        self::assertStringNotContainsString($validator, implode('|', $rows[0]));
        self::assertEquals($this->ids['ana'], $rows[0]['user_id']);
    }

    public function testRememberCookieOpensANewSession(): void
    {
        $value = $this->rememberedVisit();
        $guard = $this->guard();

        $user = $guard->loginByRemember($value);

        self::assertNotNull($user);
        self::assertEquals($this->ids['ana'], $user->getAuthId());
        self::assertTrue($this->auth->isLogged('ADM'), 'a sessão aberta pelo remember-me é a mesma do Login');
    }

    /**
     * Remember-me completo com o modo estrito ligado pela constante: login com
     * remember, visita nova pelo cookie, rotação, isLogged e logout.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRememberMeFlowWorksWithStrictConstant(): void
    {
        define('GOJIRA_MODEL_STRICT', true);
        self::assertTrue(\Connection::isStrict());

        $first = $this->guard();
        $first->attempt('ana', 'segredo', [], true);
        self::assertTrue($first->check());
        self::assertTrue((bool) $this->auth->isLogged('ADM'));

        $value    = $first->lastCookie()['value'];
        $_SESSION = [];
        $_COOKIE  = [];
        self::assertFalse((bool) $this->auth->isLogged('ADM'));

        $guard = $this->guard();
        $user  = $guard->loginByRemember($value);
        self::assertNotNull($user);
        self::assertEquals($this->ids['ana'], $user->getAuthId());
        self::assertTrue((bool) $this->auth->isLogged('ADM'));
        self::assertNotSame($value, $guard->lastCookie()['value'], 'o token roda');

        $guard->logout();
        self::assertSame([], $this->tokens());
        self::assertNull($this->guard()->loginByRemember($value), 'o token antigo não volta');
    }

    public function testEachUseRotatesTheToken(): void
    {
        $value = $this->rememberedVisit();
        $guard = $this->guard();
        $guard->loginByRemember($value);

        $newValue = $guard->lastCookie()['value'];
        self::assertNotSame($value, $newValue);
        self::assertCount(1, $this->tokens(), 'o token antigo é apagado na rotação');
        self::assertSame(explode(':', $newValue)[0], $this->tokens()[0]['selector']);
    }

    public function testOldTokenDoesNotWorkAfterRotation(): void
    {
        $value = $this->rememberedVisit();
        $this->guard()->loginByRemember($value);
        $_SESSION = [];

        self::assertNull($this->guard()->loginByRemember($value));
        self::assertFalse($this->auth->isLogged('ADM'));
    }

    public function testWrongValidatorRevokesTheTokenAndClearsTheCookie(): void
    {
        $value    = $this->rememberedVisit();
        $selector = explode(':', $value)[0];
        $guard    = $this->guard();

        self::assertNull($guard->loginByRemember($selector . ':' . str_repeat('0', 64)));

        self::assertSame([], $this->tokens(), 'validator errado revoga o token');
        $this->assertClearsCookie($guard);
        self::assertNull($this->guard()->loginByRemember($value), 'nem o validator certo serve depois');
    }

    public function testExpiredTokenIsIgnoredAndRemoved(): void
    {
        $value = $this->rememberedVisit();
        self::$connection->run('UPDATE `' . $this->tokenTable . '` SET expires_at = ?', [time() - 10]);

        self::assertNull($this->guard()->loginByRemember($value));
        self::assertSame([], $this->tokens());
    }

    public function testDeactivatedAccountCannotComeBackByCookie(): void
    {
        $value = $this->rememberedVisit();
        self::$connection->run('UPDATE `' . $this->userTable . '` SET status = 1 WHERE id = ?', [$this->ids['ana']]);
        $guard = $this->guard();

        self::assertNull($guard->loginByRemember($value));
        self::assertSame([], $this->tokens());
        self::assertSame([], $_SESSION);
        $this->assertClearsCookie($guard);
    }

    public function testTokenOfAnotherTypeIsRejected(): void
    {
        $value = $this->rememberedVisit();

        self::assertNull($this->guard('CLI')->loginByRemember($value));
        self::assertSame([], $this->tokens());
    }

    /** @return array<string, array{string}> */
    public function malformedValues(): array
    {
        return [
            'vazio'                => [''],
            'sem separador'        => ['abc'],
            'selector vazio'       => [':' . str_repeat('a', 64)],
            'validator vazio'      => [str_repeat('a', 24) . ':'],
            'longo demais'         => [str_repeat('a', 24) . ':' . str_repeat('a', 500)],
            'selector inexistente' => [str_repeat('a', 24) . ':' . str_repeat('b', 64)],
        ];
    }

    /** @dataProvider malformedValues */
    public function testMalformedCookieIsAnonymous(string $value): void
    {
        $this->rememberedVisit();

        self::assertNull($this->guard()->loginByRemember($value));
        self::assertCount(1, $this->tokens(), 'cookie malformado não revoga token de ninguém');
        self::assertSame([], $_SESSION);
    }

    public function testAttemptFromRememberCookieReadsTheConfiguredCookie(): void
    {
        $value = $this->rememberedVisit();
        $guard = $this->guard();

        self::assertNull($guard->attemptFromRememberCookie(), 'sem cookie, visitante');

        $_COOKIE['remember_adm'] = $value;
        self::assertNotNull($guard->attemptFromRememberCookie());
        self::assertTrue($guard->check());
    }

    public function testCustomCookieNameAndTtl(): void
    {
        $guard = new RecordingGuard($this->auth, 'ADM', $this->store, 'lembrar', 60);
        $guard->attempt('ana', 'segredo', [], true);

        self::assertSame('lembrar', $guard->lastCookie()['name']);
        self::assertEqualsWithDelta(time() + 60, (int) $this->tokens()[0]['expires_at'], 5);
    }

    public function testLoginGuardFactoryPassesTheStore(): void
    {
        $guard = $this->auth->guard('ADM', $this->store);
        $guard->attempt('ana', 'segredo');

        self::assertInstanceOf(\Gojira_SessionGuard::class, $guard);
        self::assertSame([], $this->tokens(), 'sem remember=true, nenhum token');
    }

    public function testLogoutRevokesTheCurrentToken(): void
    {
        $guard = $this->guard();
        $guard->attempt('ana', 'segredo', [], true);

        $guard->logout();

        self::assertSame([], $this->tokens());
        self::assertArrayNotHasKey('remember_adm', $_COOKIE);
        $this->assertClearsCookie($guard);
        self::assertFalse($guard->check());
    }

    public function testRevokeAllForUserSignsOutEveryDevice(): void
    {
        $this->store->issue($this->ids['ana'], 3600);
        $this->store->issue($this->ids['ana'], 3600);
        $this->store->issue($this->ids['carlos'], 3600);

        $this->store->revokeAllForUser($this->ids['ana']);

        $rows = $this->tokens();
        self::assertCount(1, $rows);
        self::assertEquals($this->ids['carlos'], $rows[0]['user_id']);
    }

    public function testPurgeExpiredKeepsValidTokens(): void
    {
        [$valid] = $this->store->issue($this->ids['ana'], 3600);
        $this->store->issue($this->ids['ana'], -10);

        $this->store->purgeExpired();

        $rows = $this->tokens();
        self::assertCount(1, $rows);
        self::assertSame($valid, $rows[0]['selector']);
    }

    public function testIssuedSelectorsAreUnique(): void
    {
        $selectors = [];
        for ($i = 0; $i < 20; $i++) {
            $selectors[] = $this->store->issue($this->ids['ana'], 3600)[0];
        }

        self::assertCount(20, array_unique($selectors));
    }

    public function testLoginAsRejectsInactiveUser(): void
    {
        $user = new \Gojira_GenericUser(1, 'x', '', '', 1, 'ADM', '', false);

        $this->expectExceptionCode(0x9002);
        $this->auth->loginAs($user);
    }

    public function testIdentityStaysInTheSessionWithRememberMe(): void
    {
        $guard = $this->guard();
        $guard->attempt('ana', 'segredo', [], true);

        self::assertSame(['remember_adm'], array_keys($_COOKIE), 'o modo de cookie antigo continua desligado');
        self::assertTrue($_SESSION['ADMLogged']);
    }
}
