<?php

namespace Gojira\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

require_once GOJIRA_SYSTEM_PATH . 'class/config.class.php';
require_once GOJIRA_SYSTEM_PATH . 'class/env.class.php';
require_once GOJIRA_SYSTEM_PATH . 'class/host.class.php';

/**
 * Gojira_Host: TRUSTED_HOSTS e resolução do BASE_URL sem gravar o Host.
 */
final class HostTest extends TestCase
{
    private const SECTION = 'TESTE_ENGINE';

    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/gojira-host-' . bin2hex(random_bytes(6)) . '/';
        mkdir($this->dir);
        putenv('GOJIRA_BASE_URL');
        putenv('GOJIRA_TRUSTED_HOSTS');
        putenv('GOJIRA_STRICT_CONFIG');
        unset($_ENV['GOJIRA_BASE_URL'], $_ENV['GOJIRA_TRUSTED_HOSTS'], $_SERVER['GOJIRA_BASE_URL'], $_SERVER['GOJIRA_TRUSTED_HOSTS']);
        \Gojira_Env::reset();
        $this->errorLog = ini_set('error_log', $this->dir . 'error.log');
    }

    /** @var string|false */
    private $errorLog;

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->errorLog);
        putenv('GOJIRA_BASE_URL');
        putenv('GOJIRA_TRUSTED_HOSTS');
        putenv('GOJIRA_STRICT_CONFIG');
        @unlink($this->dir . 'app_configs.ini');
        @unlink($this->dir . 'error.log');
        rmdir($this->dir);
    }

    private function config(?string $ini = null): \Config
    {
        if ($ini !== null) {
            file_put_contents($this->dir . 'app_configs.ini', $ini);
        }
        return new \Config($this->dir);
    }

    private function iniContent(): string
    {
        return (string) file_get_contents($this->dir . 'app_configs.ini');
    }

    /** @return array<string, array{array<string, string>, string}> */
    public function requestHostProvider(): array
    {
        return [
            'Host com porta'       => [['HTTP_HOST' => 'Exemplo.COM:8080'], 'exemplo.com'],
            'sem Host'             => [['SERVER_NAME' => 'srv.local'], 'srv.local'],
            'Host tem prioridade'  => [['HTTP_HOST' => 'a.com', 'SERVER_NAME' => 'b.com'], 'a.com'],
            'IPv6 com porta'       => [['HTTP_HOST' => '[::1]:8080'], '[::1]'],
            'ponto final'          => [['HTTP_HOST' => 'exemplo.com.'], 'exemplo.com'],
            'nada'                 => [[], ''],
            'IPv6 sem porta'       => [['HTTP_HOST' => '[2001:DB8::1]'], '[2001:db8::1]'],
            'cerquilha'            => [['HTTP_HOST' => 'evil.com#.exemplo.com'], ''],
            'barra'                => [['HTTP_HOST' => 'evil.com/.exemplo.com'], ''],
            'arroba'               => [['HTTP_HOST' => 'user@exemplo.com'], ''],
            'espaço interno'       => [['HTTP_HOST' => 'exemplo.com evil'], ''],
            'quebra de linha'      => [['HTTP_HOST' => "exemplo.com\nX: y"], ''],
            'porta não numérica'   => [['HTTP_HOST' => 'exemplo.com:abc'], ''],
            'pontos seguidos'      => [['HTTP_HOST' => 'a..exemplo.com'], ''],
            'começa com ponto'     => [['HTTP_HOST' => '.exemplo.com'], ''],
            'IPv6 malformado'      => [['HTTP_HOST' => '[::1'], ''],
            'Host inválido, sem fallback para SERVER_NAME' => [['HTTP_HOST' => 'a b', 'SERVER_NAME' => 'ok.com'], ''],
        ];
    }

    /**
     * @dataProvider requestHostProvider
     * @param array<string, string> $server
     */
    public function testRequestHost(array $server, string $expected): void
    {
        self::assertSame($expected, \Gojira_Host::requestHost($server));
    }

    /** @return array<string, array{string, string, bool}> */
    public function trustedProvider(): array
    {
        return [
            'lista vazia aceita tudo'      => ['qualquer.com', '', true],
            'exato'                        => ['app.com', 'app.com', true],
            'exato não casa subdomínio'    => ['x.app.com', 'app.com', false],
            'fora da lista'                => ['atacante.com', 'app.com, www.app.com', false],
            'segundo da lista'             => ['www.app.com', 'app.com, www.app.com', true],
            'ponto: domínio'               => ['app.com', '.app.com', true],
            'ponto: subdomínio'            => ['a.b.app.com', '.app.com', true],
            'ponto: sufixo sem separador'  => ['evilapp.com', '.app.com', false],
            'curinga: subdomínio'          => ['www.app.com', '*.app.com', true],
            'curinga: não casa o domínio'  => ['app.com', '*.app.com', false],
            'curinga: sufixo sem ponto'    => ['evilapp.com', '*.app.com', false],
            'host vazio com lista'         => ['', 'app.com', false],
        ];
    }

    /** @dataProvider trustedProvider */
    public function testIsTrusted(string $host, string $list, bool $expected): void
    {
        self::assertSame($expected, \Gojira_Host::isTrusted($host, \Gojira_Host::parseList($list)));
    }

    public function testHostWithInvalidCharactersIsNotTrusted(): void
    {
        $host = \Gojira_Host::requestHost(['HTTP_HOST' => 'evil.com#.exemplo.com']);
        self::assertSame('', $host);
        self::assertFalse(\Gojira_Host::isTrusted($host, \Gojira_Host::parseList('.exemplo.com, *.exemplo.com')));
    }

    public function testDetectBaseUrlIgnoresInvalidServerName(): void
    {
        self::assertSame('/app/', \Gojira_Host::detectBaseUrl(['SERVER_NAME' => 'evil.com#x', 'SCRIPT_NAME' => '/app/configs.php']));
    }

    /** @return string[] */
    private function captureWarnings(callable $fn): array
    {
        $warnings = [];
        set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
            $warnings[] = $msg;
            return true;
        }, E_USER_WARNING);
        try {
            $fn();
        } finally {
            restore_error_handler();
        }
        return $warnings;
    }

    public function testResolveReportsTheSource(): void
    {
        $cfg = $this->config();
        \Gojira_Host::resolveBaseUrl($cfg, self::SECTION, null, ['SERVER_NAME' => 'a.com', 'SCRIPT_NAME' => '/i.php'], 'fpm-fcgi', $source);
        self::assertSame(\Gojira_Host::SOURCE_DETECTED, $source);

        \Gojira_Host::resolveBaseUrl($cfg, self::SECTION, null, [], 'cli', $source);
        self::assertSame(\Gojira_Host::SOURCE_CLI, $source);

        putenv('GOJIRA_BASE_URL=env.com');
        \Gojira_Host::resolveBaseUrl($cfg, self::SECTION, null, [], 'fpm-fcgi', $source);
        self::assertSame(\Gojira_Host::SOURCE_ENV, $source);
    }

    public function testDetectedWithoutTrustedHostsInDevIsSilent(): void
    {
        $warnings = $this->captureWarnings(static function (): void {
            self::assertSame('none', \Gojira_Host::checkBaseUrlSource(\Gojira_Host::SOURCE_DETECTED, [], 'DEV'));
        });
        self::assertSame([], $warnings);
    }

    public function testDetectedWithoutTrustedHostsOutsideDevWarns(): void
    {
        $warnings = $this->captureWarnings(static function (): void {
            self::assertSame('warning', \Gojira_Host::checkBaseUrlSource(\Gojira_Host::SOURCE_DETECTED, [], 'HOMOLOG'));
        });
        self::assertCount(1, $warnings);
        self::assertStringContainsString('TRUSTED_HOSTS', $warnings[0]);
        self::assertStringContainsString('TRUSTED_HOSTS', (string) file_get_contents($this->dir . 'error.log'));
    }

    /** @return array<string, array{string}> */
    public function refusingEnvironmentProvider(): array
    {
        return ['PROD' => ['PROD'], 'PRODUCTION' => ['PRODUCTION']];
    }

    /** @dataProvider refusingEnvironmentProvider */
    public function testDetectedWithoutTrustedHostsInProductionRefusesTheBoot(string $environment): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('GOJIRA_BASE_URL');
        \Gojira_Host::checkBaseUrlSource(\Gojira_Host::SOURCE_DETECTED, [], $environment);
    }

    public function testDetectedWithoutTrustedHostsAndStrictFlagRefusesTheBoot(): void
    {
        putenv('GOJIRA_STRICT_CONFIG=1');
        $this->expectException(\RuntimeException::class);
        \Gojira_Host::checkBaseUrlSource(\Gojira_Host::SOURCE_DETECTED, [], 'DEV');
    }

    public function testConfiguredOrTrustedSourcesPassInProduction(): void
    {
        putenv('GOJIRA_STRICT_CONFIG=1');
        foreach ([\Gojira_Host::SOURCE_EXPLICIT, \Gojira_Host::SOURCE_ENV, \Gojira_Host::SOURCE_CONFIG, \Gojira_Host::SOURCE_CLI] as $source) {
            self::assertSame('none', \Gojira_Host::checkBaseUrlSource($source, [], 'PROD'));
        }
        self::assertSame('none', \Gojira_Host::checkBaseUrlSource(\Gojira_Host::SOURCE_DETECTED, ['app.com'], 'PROD'));
    }

    public function testParseListNormalizes(): void
    {
        self::assertSame(['a.com', 'b.com'], \Gojira_Host::parseList(" A.com ,b.com  a.com,,"));
        self::assertSame(['a.com'], \Gojira_Host::parseList(['A.com', 3, '']));
        self::assertSame([], \Gojira_Host::parseList(null));
    }

    public function testTrustedHostsFromIniWithoutWritingTheFile(): void
    {
        $cfg = $this->config("[TESTE_ENGINE]\nTRUSTED_HOSTS=\"app.com%2C.app.com\"\n");
        $before = $this->iniContent();

        self::assertSame(['app.com', '.app.com'], \Gojira_Host::trustedHosts($cfg, self::SECTION));
        self::assertSame($before, $this->iniContent());
    }

    public function testTrustedHostsEnvironmentWins(): void
    {
        putenv('GOJIRA_TRUSTED_HOSTS=env.com');
        $cfg = $this->config("[TESTE_ENGINE]\nTRUSTED_HOSTS=\"ini.com\"\n");

        self::assertSame(['env.com'], \Gojira_Host::trustedHosts($cfg, self::SECTION));
    }

    public function testMissingTrustedHostsKeyIsNotCreated(): void
    {
        $cfg = $this->config();
        self::assertSame([], \Gojira_Host::trustedHosts($cfg, self::SECTION));
        self::assertStringNotContainsString('TRUSTED_HOSTS', $this->iniContent());
    }

    /** @return array<string, array{array<string, string>, string}> */
    public function detectProvider(): array
    {
        return [
            'script na raiz'           => [['SERVER_NAME' => 'app.com', 'SCRIPT_NAME' => '/configs.php', 'REQUEST_URI' => '/home/index/'], 'app.com/'],
            'script em subpasta'       => [['SERVER_NAME' => 'app.com', 'SCRIPT_NAME' => '/project_demo/configs.php', 'REQUEST_URI' => '/project_demo/home/index/'], 'app.com/project_demo/'],
            'sem SCRIPT_NAME (legado)' => [['SERVER_NAME' => 'app.com', 'REQUEST_URI' => '/project_demo/'], 'app.com/project_demo/'],
        ];
    }

    /**
     * @dataProvider detectProvider
     * @param array<string, string> $server
     */
    public function testDetectBaseUrl(array $server, string $expected): void
    {
        self::assertSame($expected, \Gojira_Host::detectBaseUrl($server));
    }

    public function testNormalizeBaseUrl(): void
    {
        self::assertSame('app.com/sub/', \Gojira_Host::normalizeBaseUrl(' https://app.com/sub?x=1 '));
        self::assertSame('app.com/', \Gojira_Host::normalizeBaseUrl('app.com'));
        self::assertSame('', \Gojira_Host::normalizeBaseUrl(''));
    }

    public function testDetectedBaseUrlIsNotWritten(): void
    {
        $cfg = $this->config();
        $server = ['SERVER_NAME' => 'forjado.example', 'HTTP_HOST' => 'forjado.example', 'SCRIPT_NAME' => '/configs.php'];

        self::assertSame('forjado.example/', \Gojira_Host::resolveBaseUrl($cfg, self::SECTION, null, $server, 'fpm-fcgi'));
        self::assertStringNotContainsString('BASE_URL', $this->iniContent());

        // Requisição seguinte com outro Host não herda o anterior.
        $cfg2 = new \Config($this->dir);
        $server['SERVER_NAME'] = 'legitimo.example';
        self::assertSame('legitimo.example/', \Gojira_Host::resolveBaseUrl($cfg2, self::SECTION, null, $server, 'fpm-fcgi'));
    }

    public function testStoredBaseUrlStillWins(): void
    {
        $cfg = $this->config("[TESTE_ENGINE]\nBASE_URL=\"app.com%2Fdemo%2F\"\n");
        $server = ['SERVER_NAME' => 'outro.example', 'SCRIPT_NAME' => '/configs.php'];

        self::assertSame('app.com/demo/', \Gojira_Host::resolveBaseUrl($cfg, self::SECTION, null, $server, 'fpm-fcgi'));
    }

    public function testEnvironmentBaseUrlOverridesTheIni(): void
    {
        putenv('GOJIRA_BASE_URL=https://env.example/app');
        $cfg = $this->config("[TESTE_ENGINE]\nBASE_URL=\"antigo.example%2F\"\n");
        $before = $this->iniContent();

        self::assertSame('env.example/app/', \Gojira_Host::resolveBaseUrl($cfg, self::SECTION, null, [], 'fpm-fcgi'));
        self::assertSame($before, $this->iniContent());
    }

    public function testExplicitBaseUrlIsPersistedOnlyWhenItChanges(): void
    {
        $cfg = $this->config();

        self::assertSame('app.com/', \Gojira_Host::resolveBaseUrl($cfg, self::SECTION, 'app.com/', [], 'fpm-fcgi'));
        self::assertStringContainsString('BASE_URL="app.com%2F"', $this->iniContent());

        // Mesmo valor: o arquivo não é regravado.
        $mtimeMarker = $this->dir . 'app_configs.ini';
        file_put_contents($mtimeMarker, $this->iniContent() . "; marcador\n");
        $cfg2 = new \Config($this->dir);
        \Gojira_Host::resolveBaseUrl($cfg2, self::SECTION, 'app.com/', [], 'fpm-fcgi');
        self::assertStringContainsString('; marcador', $this->iniContent());
    }

    public function testCliWithoutConfigurationKeepsCliScheme(): void
    {
        $cfg = $this->config();
        self::assertSame('cli://', \Gojira_Host::resolveBaseUrl($cfg, self::SECTION, null, [], 'cli'));
        self::assertStringNotContainsString('BASE_URL', $this->iniContent());
    }
}
