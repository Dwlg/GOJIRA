<?php

namespace Gojira\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

require_once GOJIRA_SYSTEM_PATH . 'class/config.class.php';

/**
 * Config (app_configs.ini): ida e volta, chave sem default não grava,
 * e save() espera a trava exclusiva de outro processo.
 */
final class ConfigFileTest extends TestCase
{
    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/gojira-cfg-' . bin2hex(random_bytes(6)) . '/';
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . 'app_configs.ini');
        rmdir($this->dir);
    }

    public function testRoundTripKeepsSpecialCharacters(): void
    {
        $cfg = new \Config($this->dir);
        $cfg->setConfig('S', 'URL', 'app.com/a b/?x="1"&y=2');
        $cfg->setConfig('S', 'N', '42');

        $again = new \Config($this->dir);
        self::assertSame('app.com/a b/?x="1"&y=2', $again->getConfig('S', 'URL'));
        self::assertSame('42', $again->getConfig('S', 'N'));
    }

    public function testGetConfigWithoutDefaultDoesNotWrite(): void
    {
        $cfg = new \Config($this->dir);
        self::assertNull($cfg->getConfig('S', 'NAO_EXISTE'));
        self::assertSame('', file_get_contents($this->dir . 'app_configs.ini'));
    }

    public function testGetConfigWithDefaultStillCreatesTheKey(): void
    {
        $cfg = new \Config($this->dir);
        self::assertSame('webroot/', $cfg->getConfig('S', 'ROOT_DIR', 'webroot/'));
        self::assertStringContainsString('ROOT_DIR="webroot%2F"', (string) file_get_contents($this->dir . 'app_configs.ini'));
    }

    public function testSaveWaitsForAnExclusiveLockHeldByAnotherProcess(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open indisponível');
        }

        $file = $this->dir . 'app_configs.ini';
        $cfg  = new \Config($this->dir);

        // Outro processo segura LOCK_EX por 1 s e então grava um conteúdo próprio.
        $child = 'flock($f = fopen($argv[1], "c"), LOCK_EX); echo "locked\n"; fflush(STDOUT);'
               . 'usleep(1000000); ftruncate($f, 0); fwrite($f, "[OUTRO]\nK=\"v\"\n"); fflush($f); flock($f, LOCK_UN);';
        $proc = proc_open(
            [PHP_BINARY, '-r', $child, $file],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($proc);
        self::assertSame("locked\n", fgets($pipes[1]));

        $start = microtime(true);
        $cfg->setConfig('S', 'K', 'meu');
        $elapsed = microtime(true) - $start;

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        // save() só escreveu depois que o outro processo liberou a trava,
        // e o arquivo final é inteiro o do save(), sem mistura.
        self::assertGreaterThan(0.5, $elapsed);
        self::assertSame("\n[S]\nK=\"meu\"\n", file_get_contents($file));
    }
}
