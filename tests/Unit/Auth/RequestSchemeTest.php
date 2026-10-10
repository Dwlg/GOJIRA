<?php

namespace Gojira\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;

/**
 * Gojira_RequestScheme: HTTPS direto, X-Forwarded-Proto só de proxy
 * confiável e Secure forçado.
 */
final class RequestSchemeTest extends TestCase
{
    /** @var array<string, mixed> */
    private $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['REMOTE_ADDR']);
        \Gojira_RequestScheme::configure();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        \Gojira_RequestScheme::reset();
    }

    private function forwarded(string $remote, string $proto = 'https'): void
    {
        $_SERVER['REMOTE_ADDR']            = $remote;
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = $proto;
    }

    public function testPlainHttpIsNotHttps(): void
    {
        self::assertFalse(\Gojira_RequestScheme::isHttps());
    }

    /** @return array<string, array{string, bool}> */
    public function httpsValues(): array
    {
        return ['on' => ['on', true], '1' => ['1', true], 'off' => ['off', false], 'OFF' => ['OFF', false], 'vazio' => ['', false]];
    }

    /** @dataProvider httpsValues */
    public function testHttpsServerVariable(string $value, bool $expected): void
    {
        $_SERVER['HTTPS'] = $value;

        self::assertSame($expected, \Gojira_RequestScheme::isHttps());
    }

    public function testForwardedProtoIsIgnoredWithoutTrustedProxies(): void
    {
        $this->forwarded('10.0.0.5');

        self::assertFalse(\Gojira_RequestScheme::isHttps());
    }

    /** @return array<string, array{string[], string, bool}> */
    public function proxyCases(): array
    {
        return [
            'IP exato'               => [['10.0.0.5'], '10.0.0.5', true],
            'outro IP'               => [['10.0.0.5'], '10.0.0.6', false],
            'faixa /16'              => [['10.1.0.0/16'], '10.1.200.3', true],
            'fora da faixa'          => [['10.1.0.0/16'], '10.2.0.1', false],
            'faixa /27'              => [['192.168.1.32/27'], '192.168.1.63', true],
            'fora da /27'            => [['192.168.1.32/27'], '192.168.1.64', false],
            'IPv6 exato'             => [['fd00::1'], 'fd00::1', true],
            'IPv6 faixa'             => [['fd00::/8'], 'fd12:3456::9', true],
            'IPv6 fora'              => [['fd00::/8'], '2001:db8::1', false],
            'IPv4 contra faixa IPv6' => [['fd00::/8'], '10.0.0.1', false],
            'entrada inválida'       => [['não-é-ip', '10.0.0.0/99'], '10.0.0.1', false],
        ];
    }

    /**
     * @dataProvider proxyCases
     *
     * @param string[] $trusted
     */
    public function testForwardedProtoOnlyFromTrustedProxy(array $trusted, string $remote, bool $expected): void
    {
        \Gojira_RequestScheme::configure(false, $trusted);
        $this->forwarded($remote);

        self::assertSame($expected, \Gojira_RequestScheme::isHttps());
    }

    public function testTrustedProxyForwardingHttpIsNotHttps(): void
    {
        \Gojira_RequestScheme::configure(false, ['10.0.0.5']);
        $this->forwarded('10.0.0.5', 'http');

        self::assertFalse(\Gojira_RequestScheme::isHttps());
    }

    public function testLastForwardedValueWins(): void
    {
        \Gojira_RequestScheme::configure(false, ['10.0.0.5']);

        $this->forwarded('10.0.0.5', 'http, HTTPS');
        self::assertTrue(\Gojira_RequestScheme::isHttps());

        $this->forwarded('10.0.0.5', 'https, http');
        self::assertFalse(\Gojira_RequestScheme::isHttps(), 'o primeiro valor pode ter vindo do cliente');
    }

    public function testZeroPrefixTrustsEveryAddress(): void
    {
        \Gojira_RequestScheme::configure(false, ['0.0.0.0/0']);
        $this->forwarded('203.0.113.9');

        self::assertTrue(\Gojira_RequestScheme::isHttps());
    }

    public function testForcedSecureIgnoresTheRequest(): void
    {
        \Gojira_RequestScheme::configure(true);

        self::assertTrue(\Gojira_RequestScheme::isHttps());
    }
}
