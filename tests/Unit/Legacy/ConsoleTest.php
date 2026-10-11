<?php

namespace Gojira\Tests\Unit\Legacy;

use PHPUnit\Framework\TestCase;

require_once GOJIRA_SYSTEM_PATH . 'class/console.class.php';

/**
 * system/console.php fica desligado por padrão e só roda em CLI com GOJIRA_ENABLE_CONSOLE.
 * A resposta 404 fora de CLI é testada em Integration/Legacy/LegacyHttpTest.
 */
final class ConsoleTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('GOJIRA_ENABLE_CONSOLE');
    }

    public function testDisabledOutsideCliEvenWithTheOption(): void
    {
        putenv('GOJIRA_ENABLE_CONSOLE=1');

        foreach (['apache2handler', 'fpm-fcgi', 'cgi-fcgi', 'cli-server'] as $sapi) {
            self::assertFalse(\Console::isEnabled($sapi), $sapi);
        }
    }

    public function testDisabledInCliWithoutTheOption(): void
    {
        putenv('GOJIRA_ENABLE_CONSOLE');
        self::assertFalse(\Console::isEnabled('cli'));

        putenv('GOJIRA_ENABLE_CONSOLE=0');
        self::assertFalse(\Console::isEnabled('cli'));
    }

    public function testEnabledInCliWithTheEnvironmentVariable(): void
    {
        putenv('GOJIRA_ENABLE_CONSOLE=1');
        self::assertTrue(\Console::isEnabled('cli'));
    }

    public function testCliWithoutTheOptionExitsWithError(): void
    {
        [$code, $out, $err] = $this->runConsole(['define', 'a', 'b'], []);

        self::assertSame(1, $code);
        self::assertSame('', $out);
        self::assertStringContainsString('GOJIRA_ENABLE_CONSOLE', $err);
    }

    public function testCliWithTheOptionRunsCommandsOnPhp8(): void
    {
        [$code, $out] = $this->runConsole(['comando-inexistente'], ['GOJIRA_ENABLE_CONSOLE' => '1']);
        self::assertSame(0, $code);
        self::assertSame("Comando não identificado\n", $out);

        // cmd_split usava get_magic_quotes_gpc e create_function, removidas no PHP 8.
        [$code, $out] = $this->runConsole(['define', 'nome', '"valor com espaço"'], ['GOJIRA_ENABLE_CONSOLE' => '1']);
        self::assertSame(0, $code);
        self::assertSame("\n", $out);
    }

    public function testConstantTakesPrecedenceOverTheEnvironment(): void
    {
        $script = 'define("GOJIRA_ENABLE_CONSOLE", false); require ' . var_export(GOJIRA_SYSTEM_PATH . 'class/gojiracore.class.php', true) . ';'
            . ' require ' . var_export(GOJIRA_SYSTEM_PATH . 'class/console.class.php', true) . ';'
            . ' echo Console::isEnabled("cli") ? "on" : "off";';

        [$code, $out] = $this->execProcess([PHP_BINARY, '-r', $script], ['GOJIRA_ENABLE_CONSOLE' => '1']);

        self::assertSame(0, $code);
        self::assertSame('off', $out);
    }

    private function runConsole(array $args, array $env): array
    {
        return $this->execProcess(array_merge([PHP_BINARY, GOJIRA_SYSTEM_PATH . 'console.php'], $args), $env);
    }

    private function execProcess(array $cmd, array $env): array
    {
        $base = getenv();
        unset($base['GOJIRA_ENABLE_CONSOLE']);

        $proc = proc_open(
            implode(' ', array_map('escapeshellarg', $cmd)),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            array_merge($base, $env)
        );
        self::assertIsResource($proc);

        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $out, $err];
    }
}
