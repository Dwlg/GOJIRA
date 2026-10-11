<?php

namespace Gojira\Tests\Unit\Bootstrap;

use PHPUnit\Framework\TestCase;

/**
 * Parâmetros do cookie de sessão que o Gojira_Session aplica. A abertura real
 * da sessão é coberta pelo BootstrapHttpTest (o CLI do PHPUnit já enviou saída).
 */
final class SessionCookieParamsTest extends TestCase
{
    /** @var array */
    private $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
        \Gojira_RequestScheme::configure(false, []);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        \Gojira_RequestScheme::reset();
    }

    public function testHttpOnlyESameSiteLaxSemSecureEmHttp(): void
    {
        $p = \Gojira_Session::cookieParams();

        self::assertTrue($p['httponly']);
        self::assertSame('Lax', $p['samesite']);
        self::assertFalse($p['secure']);
    }

    public function testSecureEmHttps(): void
    {
        $_SERVER['HTTPS'] = 'on';

        self::assertTrue(\Gojira_Session::cookieParams()['secure']);
    }

    public function testSecureAtrasDeProxyConfiavel(): void
    {
        \Gojira_RequestScheme::configure(false, ['10.0.0.5']);
        $_SERVER['REMOTE_ADDR']            = '10.0.0.5';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        self::assertTrue(\Gojira_Session::cookieParams()['secure']);
    }

    public function testXForwardedProtoDeClienteQualquerNaoMarcaSecure(): void
    {
        $_SERVER['REMOTE_ADDR']            = '203.0.113.9';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        self::assertFalse(\Gojira_Session::cookieParams()['secure']);
    }

    public function testMantemOutrosParametrosDoPhpIni(): void
    {
        $current = session_get_cookie_params();
        $p       = \Gojira_Session::cookieParams();

        self::assertSame((int) $current['lifetime'], $p['lifetime']);
        self::assertSame((string) $current['path'], $p['path']);
        self::assertSame((string) $current['domain'], $p['domain']);
    }

    public function testModoSobDemandaDesligadoPorPadrao(): void
    {
        self::assertFalse(\Gojira_Session::onDemand());
        // Sem a opção, o Login não abre nem reabre sessão por conta própria.
        self::assertSame(\Gojira_Session::isActive(), \Gojira_Session::ensureStarted());
        self::assertSame(\Gojira_Session::isActive(), \Gojira_Session::resumeIfPresent());
    }
}
