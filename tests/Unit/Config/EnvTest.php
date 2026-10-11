<?php

namespace Gojira\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

require_once GOJIRA_SYSTEM_PATH . 'class/env.class.php';

/**
 * Gojira_Env: parser do .env, precedência do ambiente e placeholders.
 */
final class EnvTest extends TestCase
{
    /** @var string[] chaves que o teste definiu no ambiente */
    private $keys = [];

    /** @var string */
    private $dir;

    /** @var string|false */
    private $errorLog;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/gojira-env-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        \Gojira_Env::reset();
        putenv('GOJIRA_STRICT_CONFIG');
        $this->errorLog = ini_set('error_log', $this->dir . '/error.log');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->errorLog);
        \Gojira_Env::reset();
        putenv('GOJIRA_STRICT_CONFIG');
        foreach ($this->keys as $k) {
            putenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
        }
        foreach (scandir($this->dir) ?: [] as $f) {
            if (is_file($this->dir . '/' . $f)) {
                unlink($this->dir . '/' . $f);
            }
        }
        rmdir($this->dir);
    }

    private function key(string $suffix): string
    {
        $k = 'GOJIRA_T_' . strtoupper(bin2hex(random_bytes(3))) . '_' . $suffix;
        $this->keys[] = $k;
        return $k;
    }

    private function envFile(string $content): string
    {
        $file = $this->dir . '/.env';
        file_put_contents($file, $content);
        return $file;
    }

    public function testParsesPlainQuotedAndCommentedLines(): void
    {
        $vars = \Gojira_Env::parse(implode("\n", [
            '# comentário',
            '',
            'A=1',
            'export B=dois',
            'C = com espaço   ',
            'D="aspas \"duplas\"\ncom quebra" # comentário',
            "E='literal \\n # sem comentário'",
            'F=valor # comentário depois de espaço',
            'G=senha#com#cerquilha',
            'H=',
            'I=""',
            'J.K_L=ponto',
        ]));

        self::assertSame([
            'A'     => '1',
            'B'     => 'dois',
            'C'     => 'com espaço',
            'D'     => "aspas \"duplas\"\ncom quebra",
            'E'     => 'literal \n # sem comentário',
            'F'     => 'valor',
            'G'     => 'senha#com#cerquilha',
            'H'     => '',
            'I'     => '',
            'J.K_L' => 'ponto',
        ], $vars);
    }

    public function testAcceptsCrlfAndBom(): void
    {
        self::assertSame(['A' => '1', 'B' => '2'], \Gojira_Env::parse("\xEF\xBB\xBFA=1\r\nB=2\r\n"));
    }

    public function testInvalidLineWarnsWithLineNumberOnly(): void
    {
        $warnings = [];
        set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
            $warnings[] = $msg;
            return true;
        }, E_USER_WARNING);
        try {
            $vars = \Gojira_Env::parse("A=1\nsegredo-sem-igual\nB=\"sem fechar\nC=3");
        } finally {
            restore_error_handler();
        }

        self::assertSame(['A' => '1', 'C' => '3'], $vars);
        self::assertCount(2, $warnings);
        self::assertStringContainsString('linha 2', $warnings[0]);
        self::assertStringContainsString('linha 3', $warnings[1]);
        self::assertStringNotContainsString('segredo', implode(' ', $warnings));
    }

    public function testMissingFileIsANoOp(): void
    {
        self::assertSame([], \Gojira_Env::load($this->dir . '/nao-existe.env'));
    }

    public function testLoadFillsEnvAndInternalArrayWithoutPutenv(): void
    {
        $k = $this->key('LOAD');
        $loaded = \Gojira_Env::load($this->envFile($k . "=valor\n"));

        self::assertSame([$k], $loaded);
        self::assertFalse(getenv($k));
        self::assertSame('valor', $_ENV[$k]);
        self::assertSame('valor', \Gojira_Env::get($k));
    }

    public function testForbiddenKeysAreRefused(): void
    {
        $k = $this->key('OK');
        $warnings = [];
        set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
            $warnings[] = $msg;
            return true;
        }, E_USER_WARNING);
        try {
            $loaded = \Gojira_Env::load($this->envFile(
                "PATH=/tmp/x\nLD_PRELOAD=/tmp/x.so\nld_library_path=/tmp\nHTTP_HOST=evil.example\n" . $k . "=1\n"
            ));
        } finally {
            restore_error_handler();
        }

        self::assertSame([$k], $loaded);
        self::assertCount(4, $warnings);
        self::assertNotSame('/tmp/x.so', $_ENV['LD_PRELOAD'] ?? null);
        self::assertNotSame('evil.example', $_ENV['HTTP_HOST'] ?? null);
        self::assertNotSame('/tmp/x', \Gojira_Env::get('PATH'));
    }

    public function testGetNeverReadsHttpKeysFromServer(): void
    {
        $_SERVER['HTTP_GOJIRA_T_HEADER'] = 'do-cliente';
        try {
            self::assertSame('padrao', \Gojira_Env::get('HTTP_GOJIRA_T_HEADER', 'padrao'));
            self::assertSame('padrao', \Gojira_Env::get('http_gojira_t_header', 'padrao'));
        } finally {
            unset($_SERVER['HTTP_GOJIRA_T_HEADER']);
        }
    }

    public function testLoadFirstUsesTheFirstExistingFile(): void
    {
        $k = $this->key('FIRST');
        $second = $this->dir . '/segundo.env';
        file_put_contents($second, $k . "=segundo\n");
        file_put_contents($this->dir . '/terceiro.env', $k . "=terceiro\n");

        $used = \Gojira_Env::loadFirst([null, '', $this->dir . '/nao-existe.env', $second, $this->dir . '/terceiro.env']);

        self::assertSame($second, $used);
        self::assertSame('segundo', \Gojira_Env::get($k));
        self::assertNull(\Gojira_Env::loadFirst([null, $this->dir . '/nao-existe.env']));
    }

    public function testServedByWeb(): void
    {
        mkdir($this->dir . '/www');
        mkdir($this->dir . '/www/app');
        $server = ['DOCUMENT_ROOT' => $this->dir . '/www'];
        try {
            self::assertTrue(\Gojira_Env::servedByWeb($this->dir . '/www/.env', $server));
            self::assertTrue(\Gojira_Env::servedByWeb($this->dir . '/www/app/.env', $server));
            self::assertFalse(\Gojira_Env::servedByWeb($this->dir . '/.env', $server));
            self::assertFalse(\Gojira_Env::servedByWeb($this->dir . '/www-irmao/.env', $server));
            self::assertFalse(\Gojira_Env::servedByWeb($this->dir . '/www/.env', []));
        } finally {
            rmdir($this->dir . '/www/app');
            rmdir($this->dir . '/www');
        }
    }

    public function testLoadDoesNotOverrideTheEnvironment(): void
    {
        $k = $this->key('KEEP');
        putenv($k . '=do-servidor');

        self::assertSame([], \Gojira_Env::load($this->envFile($k . "=do-arquivo\n")));
        self::assertSame('do-servidor', getenv($k));
    }

    public function testLoadDoesNotOverrideEnvSuperglobal(): void
    {
        $k = $this->key('KEEPENV');
        $_ENV[$k] = 'do-servidor';

        \Gojira_Env::load($this->envFile($k . "=do-arquivo\n"));
        self::assertSame('do-servidor', $_ENV[$k]);
        self::assertFalse(getenv($k));
    }

    public function testOverrideFlagReplacesTheEnvironment(): void
    {
        $k = $this->key('OVER');
        putenv($k . '=do-servidor');

        \Gojira_Env::load($this->envFile($k . "=do-arquivo\n"), true);
        self::assertSame('do-arquivo', \Gojira_Env::get($k));
        self::assertSame('do-servidor', getenv($k));
    }

    public function testGetFallsBackToDefaultThenServer(): void
    {
        $k = $this->key('GET');
        self::assertSame('padrao', \Gojira_Env::get($k, 'padrao'));
        self::assertNull(\Gojira_Env::get($k));

        $_SERVER[$k] = 'via-server';
        self::assertSame('via-server', \Gojira_Env::get($k, 'padrao'));
    }

    /** @return array<string, array{mixed, bool}> */
    public function placeholderProvider(): array
    {
        return [
            'db_user'          => ['db_user', true],
            'db_host como pwd' => ['db_host', true],
            'maiúsculas'       => ['DB_PWD', true],
            'changeme'         => ['changeme', true],
            'senha real'       => ['k8#Fq2!vZ', false],
            'vazia'            => ['', false],
            'null'             => [null, false],
        ];
    }

    /**
     * @dataProvider placeholderProvider
     * @param mixed $value
     */
    public function testIsPlaceholder($value, bool $expected): void
    {
        self::assertSame($expected, \Gojira_Env::isPlaceholder($value));
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

    /** @return array<string, array{string}> */
    public function productionProvider(): array
    {
        return ['PROD' => ['PROD'], 'PRODUCTION' => ['production'], 'com espaço' => [' Prod ']];
    }

    /** @dataProvider productionProvider */
    public function testPlaceholderInProductionRefusesTheBootCitingTheConstant(string $environment): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DB_PWD');

        \Gojira_Env::checkPlaceholders(['DB_USER' => 'app', 'DB_PWD' => 'db_host'], $environment);
    }

    public function testPlaceholderOutsideDevOnlyWarns(): void
    {
        $found = null;
        $warnings = $this->captureWarnings(static function () use (&$found): void {
            $found = \Gojira_Env::checkPlaceholders(['DB_USER' => 'app', 'DB_PWD' => 'db_host'], 'HOMOLOG');
        });

        self::assertSame(['DB_PWD'], $found);
        self::assertCount(1, $warnings);
        self::assertStringContainsString('DB_PWD', $warnings[0]);
        self::assertStringContainsString('DB_PWD', (string) file_get_contents($this->dir . '/error.log'));
    }

    public function testPlaceholderInDevOnlyLogs(): void
    {
        $found = null;
        $warnings = $this->captureWarnings(static function () use (&$found): void {
            $found = \Gojira_Env::checkPlaceholders(['DB_USER' => 'db_user', 'DB_PWD' => 'db_host'], 'dev');
        });

        self::assertSame(['DB_USER', 'DB_PWD'], $found);
        self::assertSame([], $warnings);
        self::assertStringContainsString('DB_USER, DB_PWD', (string) file_get_contents($this->dir . '/error.log'));
    }

    public function testStrictFlagRefusesTheBootEvenInDev(): void
    {
        putenv('GOJIRA_STRICT_CONFIG=1');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DB_PWD');

        \Gojira_Env::checkPlaceholders(['DB_PWD' => 'db_host'], 'DEV');
    }

    /** @return array<string, array{string, bool}> */
    public function strictProvider(): array
    {
        return ['1' => ['1', true], 'true' => ['TRUE', true], 'on' => ['on', true], '0' => ['0', false], 'vazio' => ['', false]];
    }

    /** @dataProvider strictProvider */
    public function testStrictFlagValues(string $value, bool $expected): void
    {
        putenv('GOJIRA_STRICT_CONFIG=' . $value);
        self::assertSame($expected, \Gojira_Env::strictConfig());
    }

    public function testReportIssueOutcomes(): void
    {
        self::assertSame('log', \Gojira_Env::reportIssue('x', 'DEV'));
        self::assertSame('none', \Gojira_Env::reportIssue('x', 'DEV', false));
        $warnings = $this->captureWarnings(static function (): void {
            self::assertSame('warning', \Gojira_Env::reportIssue('problema', 'HOMOLOG', false));
        });
        self::assertSame(['problema'], $warnings);
    }

    public function testRealCredentialsPassEverywhere(): void
    {
        putenv('GOJIRA_STRICT_CONFIG=1');
        self::assertSame([], \Gojira_Env::checkPlaceholders(['DB_USER' => 'app', 'DB_PWD' => 's3nh4-real'], 'PROD'));
    }
}
