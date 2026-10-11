<?php

namespace Gojira\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

require_once GOJIRA_SYSTEM_PATH . 'class/config.class.php';

/**
 * Config::applyLocale: timezone do PHP e lc_time_names da conexão vindos do
 * app_configs.ini, com os padrões da 1.0 quando as chaves não existem.
 */
final class ConfigLocaleTest extends TestCase
{
    private const SECTION = 'TEST_ENGINE';

    /** @var string */
    private $dir;

    /** @var string */
    private $previousTimezone;

    /** @var string[] */
    private $warnings = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/gojira-config-' . bin2hex(random_bytes(4)) . '/';
        mkdir($this->dir);
        $this->previousTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        \Connection::setLcTimeNames(null);
        date_default_timezone_set($this->previousTimezone);
        @unlink($this->dir . 'app_configs.ini');
        @rmdir($this->dir);
    }

    private function config(string $ini = ''): \Config
    {
        if ($ini !== '') {
            file_put_contents($this->dir . 'app_configs.ini', $ini);
        }

        return new \Config($this->dir);
    }

    private function applyCapturingWarnings(\Config $config): void
    {
        set_error_handler(function (int $errno, string $errstr): bool {
            $this->warnings[] = $errstr;

            return true;
        }, E_USER_WARNING);

        try {
            $config->applyLocale(self::SECTION);
        } finally {
            restore_error_handler();
        }
    }

    public function testWithoutKeysKeepsCurrentDefaults(): void
    {
        \Connection::setLcTimeNames('en_US');
        $config = $this->config("\n[" . self::SECTION . "]\nBASE_URL=\"x\"\n");
        $before = file_get_contents($this->dir . 'app_configs.ini');

        $config->applyLocale(self::SECTION);

        self::assertSame('America/Sao_Paulo', date_default_timezone_get());
        self::assertSame('pt_BR', \Connection::getLcTimeNames());
        self::assertSame($before, file_get_contents($this->dir . 'app_configs.ini'), 'applyLocale não grava chaves novas');
    }

    public function testWithoutConfigFileKeepsCurrentDefaults(): void
    {
        $this->config()->applyLocale(self::SECTION);

        self::assertSame('America/Sao_Paulo', date_default_timezone_get());
        self::assertSame('pt_BR', \Connection::getLcTimeNames());
    }

    public function testReadsTimezoneAndLcTimeNamesFromConfig(): void
    {
        $config = $this->config("\n[" . self::SECTION . "]\nTIMEZONE=\"Europe%2FLisbon\"\nLC_TIME_NAMES=\"en_US\"\n");

        $config->applyLocale(self::SECTION);

        self::assertSame('Europe/Lisbon', date_default_timezone_get());
        self::assertSame('en_US', \Connection::getLcTimeNames());
    }

    public function testInvalidTimezoneWarnsAndFallsBackToDefault(): void
    {
        $config = $this->config("\n[" . self::SECTION . "]\nTIMEZONE=\"Mars%2FOlympus\"\n");

        $this->applyCapturingWarnings($config);

        self::assertSame('America/Sao_Paulo', date_default_timezone_get());
        self::assertCount(1, $this->warnings);
        self::assertStringContainsString('TIMEZONE', $this->warnings[0]);
    }

    public function testInvalidLcTimeNamesWarnsAndFallsBackToDefault(): void
    {
        $config = $this->config("\n[" . self::SECTION . "]\nLC_TIME_NAMES=\"pt_BR+x\"\n");

        $this->applyCapturingWarnings($config);

        self::assertSame('pt_BR', \Connection::getLcTimeNames());
        self::assertCount(1, $this->warnings);
        self::assertStringContainsString('LC_TIME_NAMES', $this->warnings[0]);
    }
}
