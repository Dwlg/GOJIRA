<?php

namespace Gojira\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

/**
 * Boot real em processo separado (constantes e init.php definem constantes
 * e globais que não podem vazar para os outros testes).
 */
final class BootConfigTest extends TestCase
{
    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open indisponível');
        }
        $this->dir = sys_get_temp_dir() . '/gojira-boot-' . bin2hex(random_bytes(6)) . '/';
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->dir) ?: [] as $f) {
            if (is_file($this->dir . $f)) {
                unlink($this->dir . $f);
            }
        }
        rmdir($this->dir);
    }

    /** @param array<string, string> $env */
    private function runPhp(string $code, array $env = []): string
    {
        $script = $this->dir . 'boot.php';
        file_put_contents($script, $code);

        $base = getenv();
        foreach (['ENVIRONMENT', 'DB_NAME', 'DB_HOST', 'DB_USER', 'DB_PWD', 'SMTP_HOST', 'GOJIRA_BASE_URL', 'GOJIRA_TRUSTED_HOSTS'] as $k) {
            unset($base[$k]);
        }

        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->dir,
            array_merge($base, $env)
        );
        self::assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        return $out . ($err !== '' ? "\n[stderr] " . $err : '');
    }

    private function constantsScript(): string
    {
        return '<?php require ' . var_export(GOJIRA_SYSTEM_PATH . 'class/env.class.php', true) . ';'
             . 'require ' . var_export(GOJIRA_TESTS_ROOT . 'project_demo/constants.php', true) . ';'
             . 'echo json_encode([ENVIRONMENT, DB_NAME, DB_HOST, DB_USER, DB_PWD, SMTP_HOST]);';
    }

    public function testDemoConstantsKeepTheOldDefaultsWithoutEnvironment(): void
    {
        self::assertSame(
            '["DEV","db_name","localhost","db_user","db_host",""]',
            $this->runPhp($this->constantsScript())
        );
    }

    public function testDemoConstantsReadTheEnvironment(): void
    {
        $out = $this->runPhp($this->constantsScript(), [
            'ENVIRONMENT' => 'PROD', 'DB_NAME' => 'app', 'DB_HOST' => 'db', 'DB_USER' => 'u',
            'DB_PWD' => 'p@ss', 'SMTP_HOST' => 'smtp.local',
        ]);
        self::assertSame('["PROD","app","db","u","p@ss","smtp.local"]', $out);
    }

    public function testInitRefusesPlaceholderCredentialsOutsideDev(): void
    {
        $code = '<?php'
              . ' $absPath = ' . var_export($this->dir, true) . ';'
              . ' define("PROJECT_ID", "T"); define("ENVIRONMENT", "PROD");'
              . ' $globals = new stdClass(); $globals->database = new stdClass();'
              . ' $globals->database->host = "127.0.0.1"; $globals->database->user = "app";'
              . ' $globals->database->password = "db_host"; $globals->database->name = "x";'
              . ' try { require ' . var_export(GOJIRA_SYSTEM_PATH . 'init.php', true) . '; echo "BOOTOU"; }'
              . ' catch (RuntimeException $e) { echo "EXC:" . $e->getMessage(); }';

        $out = $this->runPhp($code);

        self::assertStringStartsWith('EXC:', $out);
        self::assertStringContainsString('DB_PWD', $out);
        self::assertStringNotContainsString('DB_USER', $out);
    }
}
