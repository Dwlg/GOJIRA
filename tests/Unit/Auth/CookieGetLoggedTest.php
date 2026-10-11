<?php

namespace Gojira\Tests\Unit\Auth;

use Gojira\Tests\Support\Deprecations;
use Gojira\Tests\Support\FakeLogin;
use PHPUnit\Framework\TestCase;

/**
 * Login::getLogged() no modo cookie (obsoleto): o valor vem do navegador, sem
 * assinatura, e sai escapado para HTML. No modo sessão nada muda.
 */
final class CookieGetLoggedTest extends TestCase
{
    use Deprecations;

    /** @var array<string, mixed> */
    private $cookie;

    protected function setUp(): void
    {
        $this->cookie = $_COOKIE;
        $_COOKIE      = [];
    }

    protected function tearDown(): void
    {
        $_COOKIE = $this->cookie;
        if (isset($_SESSION)) {
            unset($_SESSION['ADMName']);
        }
    }

    private function cookieLogin(): FakeLogin
    {
        $login = new FakeLogin();
        $this->withDeprecations(static function () use ($login) {
            $login->setCookie(true);
        });
        return $login;
    }

    public function testCookieValuesWithMarkupAreEscaped(): void
    {
        $_COOKIE['admname']  = '<b class="m">marcador</b>';
        $_COOKIE['admemail'] = "a'b@ex.test";

        $login = $this->cookieLogin();

        self::assertSame('&lt;b class=&quot;m&quot;&gt;marcador&lt;/b&gt;', $login->getLogged('Name', 'adm'));
        self::assertSame('a&#039;b@ex.test', $login->getLogged('Email', 'adm'));
    }

    public function testSimpleCookieValuesAreUnchanged(): void
    {
        $_COOKIE['admname'] = 'Nome Ágil';
        $_COOKIE['admcod']  = '42';

        $login = $this->cookieLogin();

        self::assertSame('Nome Ágil', $login->getLogged('Name', 'adm'));
        self::assertSame('42', $login->getLogged('Cod', 'adm'));
    }

    public function testNonScalarCookieIsIgnored(): void
    {
        $_COOKIE['admname'] = ['x'];

        self::assertSame('', $this->cookieLogin()->getLogged('Name', 'adm'));
    }

    public function testSessionModeIsUnchanged(): void
    {
        $_SESSION['ADMName'] = '<b>sessao</b>';

        self::assertSame('<b>sessao</b>', (new FakeLogin())->getLogged('Name', 'adm'));
    }
}
