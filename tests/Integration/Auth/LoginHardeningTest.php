<?php

namespace Gojira\Tests\Integration\Auth;

use Gojira\Tests\Support\AuthFixtures;
use Gojira\Tests\Support\IntegrationTestCase;
use Gojira\Tests\Support\RecordingGuard;
use Gojira\Tests\Support\RecordingLogin;

/**
 * Endurecimento do Login: flags dos cookies de identidade, id exato no modo
 * cookie, aviso de obsolescência do modo cookie e senha temporária com
 * random_int. A troca do id de sessão fica em SessionRegenerationTest.
 */
final class LoginHardeningTest extends IntegrationTestCase
{
    use AuthFixtures;

    /** @var RecordingLogin */
    private $auth;

    /** @var int */
    private $anaId;

    /** @var string[] */
    private $deprecations = [];

    protected function setUp(): void
    {
        $_SESSION = [];
        $_COOKIE  = [];
        unset($_SERVER['HTTPS']);

        $this->deprecations = [];
        set_error_handler(function (int $errno, string $errstr): bool {
            $this->deprecations[] = $errstr;

            return true;
        }, E_USER_DEPRECATED);

        $this->createAuthTables();
        $this->anaId = $this->addUser('ana', 'segredo', 1, 2);
        $this->addUser('bia', 'segredo', 1, 1);
        $this->auth = new RecordingLogin(self::$connection, self::$globals->tools, $this->userTable, $this->typeTable);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        \Gojira_RequestScheme::reset();
        unset($_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['REMOTE_ADDR']);
        $_SESSION = [];
        $_COOKIE  = [];
        unset($_SERVER['HTTPS']);
        parent::tearDown();
    }

    private function resetCookieModeWarning(): void
    {
        $flag = new \ReflectionProperty(\Login::class, 'cookieModeWarned');
        $flag->setAccessible(true);
        $flag->setValue(null, false);
    }

    // Cookies de identidade (modo setCookie(true))

    public function testCookieModeLoginSetsHardenedLowercaseCookies(): void
    {
        $this->auth->setCookie(true);
        $this->auth->login('ana', 'segredo', 'ADM');

        $cookies = $this->auth->cookiesByName();
        self::assertSame(
            ['admlogged', 'admcod', 'admlogin', 'admemail', 'admname', 'admtype', 'admtypecod'],
            array_keys($cookies)
        );
        foreach ($cookies as $name => $cookie) {
            self::assertTrue($cookie['options']['httponly'], $name . ' sem HttpOnly');
            self::assertSame('Lax', $cookie['options']['samesite'], $name);
            self::assertSame('/', $cookie['options']['path'], $name);
            self::assertFalse($cookie['options']['secure'], $name . ' Secure em HTTP');
        }
        self::assertSame((string) $this->anaId, $cookies['admcod']['value']);
        self::assertSame([], $_SESSION, 'no modo cookie a sessão não recebe a identidade');
    }

    public function testCookieModeCookiesAreSecureOverHttps(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $this->auth->setCookie(true);
        $this->auth->login('ana', 'segredo', 'ADM');

        foreach ($this->auth->cookies as $cookie) {
            self::assertTrue($cookie['options']['secure'], $cookie['name']);
        }
    }

    public function testCookieModeLogoutClearsWithTheSamePath(): void
    {
        $this->auth->setCookie(true);
        $this->auth->login('ana', 'segredo', 'ADM');
        foreach ($this->auth->cookiesByName() as $name => $cookie) {
            $_COOKIE[$name] = $cookie['value'];
        }
        $this->auth->cookies = [];

        $this->auth->logout('ADM');

        $cleared = $this->auth->cookiesByName();
        self::assertArrayHasKey('admcod', $cleared);
        foreach ($cleared as $name => $cookie) {
            self::assertSame('', $cookie['value'], $name);
            self::assertSame('/', $cookie['options']['path'], $name);
            self::assertLessThan(time(), $cookie['options']['expires'], $name);
            self::assertTrue($cookie['options']['httponly'], $name);
        }
        self::assertSame([], $_COOKIE);
        self::assertFalse($this->auth->isLogged('ADM'));
    }

    public function testSessionModeSendsNoCookies(): void
    {
        $this->auth->login('ana', 'segredo', 'ADM');
        $this->auth->logout('ADM');

        self::assertSame([], $this->auth->cookies);
    }

    /** @param int|string $cod */
    private function setIdentityCookies($cod): void
    {
        $_COOKIE = [];
        foreach (['Logged' => '1', 'Cod' => (string) $cod, 'Name' => 'ana', 'Login' => 'ana', 'Email' => 'ana@example.test'] as $field => $value) {
            $_COOKIE[$this->auth->cookieName('ADM', $field)] = $value;
        }
    }

    /** @return array<string, array{string}> */
    public function idsThatMysqlWouldCoerce(): array
    {
        return [
            'sufixo de texto' => ['%dx'],
            'zero à esquerda' => ['0%d'],
            'decimal'         => ['%d.0'],
            'espaço antes'    => [' %d'],
            'espaço depois'   => ['%d '],
            'sinal'           => ['+%d'],
            'expoente'        => ['%de0'],
        ];
    }

    /** @dataProvider idsThatMysqlWouldCoerce */
    public function testCookieIdMustMatchExactly(string $pattern): void
    {
        $this->auth->setCookie(true);
        $this->setIdentityCookies(sprintf($pattern, $this->anaId));

        self::assertFalse($this->auth->isLogged('ADM'));
    }

    public function testExactCookieIdStillWorks(): void
    {
        $this->auth->setCookie(true);
        $this->setIdentityCookies($this->anaId);

        self::assertTrue($this->auth->isLogged('ADM'));
    }

    public function testCookieOfUnknownTypeIsNotLogged(): void
    {
        $this->auth->setCookie(true);
        $_COOKIE = ['xyzlogged' => '1', 'xyzcod' => (string) $this->anaId, 'xyzname' => 'a', 'xyzlogin' => 'a', 'xyzemail' => 'a'];

        self::assertFalse($this->auth->isLogged('XYZ'));
    }

    public function testCookieModeWarnsOncePerRequest(): void
    {
        $this->resetCookieModeWarning();

        $this->auth->setCookie(false);
        self::assertSame([], $this->deprecations, 'desligar não avisa');

        $this->auth->setCookie(true);
        $this->auth->setCookie(true);
        $this->newLogin()->setCookie(true);

        self::assertCount(1, $this->deprecations);
        self::assertStringContainsString('Login::setCookie(true)', $this->deprecations[0]);
        self::assertStringContainsString('Gojira_RememberTokenStore', $this->deprecations[0]);
    }

    // Secure atrás de proxy

    public function testIdentityCookiesSecureBehindTrustedProxy(): void
    {
        \Gojira_RequestScheme::configure(false, ['10.0.0.5']);
        $_SERVER['REMOTE_ADDR']            = '10.0.0.5';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $this->auth->setCookie(true);
        $this->auth->login('ana', 'segredo', 'ADM');

        foreach ($this->auth->cookies as $cookie) {
            self::assertTrue($cookie['options']['secure'], $cookie['name']);
        }
    }

    public function testForwardedProtoFromUnknownAddressDoesNotMarkSecure(): void
    {
        \Gojira_RequestScheme::configure(false, ['10.0.0.5']);
        $_SERVER['REMOTE_ADDR']            = '203.0.113.9';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $this->auth->setCookie(true);
        $this->auth->login('ana', 'segredo', 'ADM');

        self::assertFalse($this->auth->cookies[0]['options']['secure']);
    }

    public function testRememberCookieFollowsForcedSecure(): void
    {
        \Gojira_RequestScheme::configure(true);
        $table = $this->createTable(
            'selector CHAR(24) NOT NULL PRIMARY KEY, validator_hash CHAR(64) NOT NULL, user_id INT NOT NULL,'
            . ' expires_at INT NOT NULL, user_agent VARCHAR(255) NULL, created_at INT NOT NULL'
        );
        $guard = new RecordingGuard($this->auth, 'ADM', new \Gojira_TableRememberTokenStore(self::$connection, $table));
        $guard->attempt('ana', 'segredo', [], true);

        self::assertTrue($guard->lastCookie()['options']['secure']);
    }

    // Troca de sessão sem sessão ativa

    public function testNoWarningWhenThereIsNoActiveSession(): void
    {
        self::assertNotSame(PHP_SESSION_ACTIVE, session_status());
        $this->auth->forceHeadersSent = true;

        // Um E_USER_WARNING aqui viraria exceção no PHPUnit.
        self::assertEquals($this->anaId, $this->auth->login('ana', 'segredo', 'ADM'));
    }

    // Senha temporária

    /** @return array<string, array{int}> */
    public function passwordLengths(): array
    {
        return ['4' => [4], '5' => [5], '8' => [8], '13' => [13], '32' => [32]];
    }

    /** @dataProvider passwordLengths */
    public function testGetPasswordHasTheRequestedLength(int $length): void
    {
        self::assertSame($length, strlen($this->auth->getPassword($length)));
    }

    public function testGetPasswordMixesAllCharacterClasses(): void
    {
        $pwd = $this->auth->getPassword(12);

        self::assertMatchesRegularExpression('/[0-9]/', $pwd);
        self::assertMatchesRegularExpression('/[a-z]/', $pwd);
        self::assertMatchesRegularExpression('/[A-Z]/', $pwd);
        self::assertMatchesRegularExpression('/[@#$%&+]/', $pwd);
    }

    public function testShortPasswordIsLowercaseOnly(): void
    {
        self::assertMatchesRegularExpression('/^[a-z]{4}$/', $this->auth->getPassword(4));
    }

    public function testGetPasswordDoesNotRepeat(): void
    {
        $passwords = [];
        for ($i = 0; $i < 50; $i++) {
            $passwords[] = $this->auth->getPassword(12);
        }

        self::assertCount(50, array_unique($passwords));
    }

    public function testGetPasswordDoesNotUseTheSeedableGenerator(): void
    {
        mt_srand(1234);
        $first = $this->auth->getPassword(16);
        mt_srand(1234);
        $second = $this->auth->getPassword(16);
        mt_srand();

        self::assertNotSame($first, $second, 'rand() com a mesma semente repetiria a senha');
    }
}
