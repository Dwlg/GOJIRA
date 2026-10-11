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
        foreach (['GOJIRA_STRICT_CONFIG', 'GOJIRA_ENV_FILE', 'ENVIRONMENT', 'DB_NAME', 'DB_HOST', 'DB_USER', 'DB_PWD', 'SMTP_HOST', 'GOJIRA_BASE_URL', 'GOJIRA_TRUSTED_HOSTS'] as $k) {
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

    // ENVIRONMENT padrão do demo é 'PROD' desde o PR13/GLX-42: um deploy que
    // esquece de definir ENVIRONMENT (ou o .env) fica com display_errors
    // desligado e com a política de placeholder de credencial recusando o
    // boot, em vez de só registrar no log (ver UPGRADE.md, seção 15). As
    // demais constantes continuam com o mesmo fallback de sempre.
    public function testDemoConstantsDefaultToProdWithoutEnvironment(): void
    {
        self::assertSame(
            '["PROD","db_name","localhost","db_user","db_host",""]',
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

    private function initScript(string $environment): string
    {
        return '<?php'
             . ' ini_set("error_log", ' . var_export($this->dir . 'error.log', true) . ');'
             . ' set_error_handler(function ($no, $msg) { if ($no === E_USER_WARNING) { echo "WARN:" . $msg . "\\n"; return true; } return false; });'
             . ' $absPath = ' . var_export($this->dir, true) . ';'
             . ' define("PROJECT_ID", "T"); define("ENVIRONMENT", ' . var_export($environment, true) . ');'
             . ' $globals = new stdClass(); $globals->database = new stdClass();'
             . ' $globals->database->host = "127.0.0.1"; $globals->database->user = "app";'
             . ' $globals->database->password = "db_host"; $globals->database->name = "x";'
             . ' try { require ' . var_export(GOJIRA_SYSTEM_PATH . 'init.php', true) . '; echo "BOOTOU"; }'
             . ' catch (Throwable $e) { echo "EXC:" . get_class($e) . ":" . $e->getMessage(); }';
    }

    /** @return array<string, array{string}> */
    public function productionProvider(): array
    {
        return ['PROD' => ['PROD'], 'PRODUCTION' => ['PRODUCTION']];
    }

    /** @dataProvider productionProvider */
    public function testInitRefusesPlaceholderCredentialsInProduction(string $environment): void
    {
        $out = $this->runPhp($this->initScript($environment));

        self::assertStringStartsWith('EXC:RuntimeException:', $out);
        self::assertStringContainsString('DB_PWD', $out);
        self::assertStringNotContainsString('DB_USER', $out);
    }

    public function testInitOnlyWarnsAboutPlaceholderOutsideDevAndProduction(): void
    {
        $out = $this->runPhp($this->initScript('HOMOLOG'));

        // O aviso sai antes da conexão; o que acontece depois (banco inexistente) não importa aqui.
        self::assertStringStartsWith('WARN:', $out);
        self::assertStringContainsString('DB_PWD', strtok($out, "\n"));
        self::assertStringNotContainsString('EXC:RuntimeException:Configuração com valor de exemplo', $out);
    }

    public function testInitRefusesPlaceholderWithStrictFlagEvenInDev(): void
    {
        $out = $this->runPhp($this->initScript('DEV'), ['GOJIRA_STRICT_CONFIG' => '1']);

        self::assertStringStartsWith('EXC:RuntimeException:', $out);
        self::assertStringContainsString('DB_PWD', $out);
    }
}
