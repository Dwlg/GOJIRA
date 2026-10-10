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

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/gojira-env-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
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

    public function testLoadDefinesGetenvAndEnv(): void
    {
        $k = $this->key('LOAD');
        $loaded = \Gojira_Env::load($this->envFile($k . "=valor\n"));

        self::assertSame([$k], $loaded);
        self::assertSame('valor', getenv($k));
        self::assertSame('valor', $_ENV[$k]);
        self::assertSame('valor', \Gojira_Env::get($k));
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
        self::assertSame('do-arquivo', getenv($k));
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

    public function testPlaceholderOutsideDevThrowsCitingTheConstant(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DB_PWD');

        \Gojira_Env::checkPlaceholders(['DB_USER' => 'app', 'DB_PWD' => 'db_host'], 'PROD');
    }

    public function testPlaceholderInDevOnlyReportsTheList(): void
    {
        self::assertSame(
            ['DB_USER', 'DB_PWD'],
            \Gojira_Env::checkPlaceholders(['DB_USER' => 'db_user', 'DB_PWD' => 'db_host'], 'dev')
        );
    }

    public function testRealCredentialsPassOutsideDev(): void
    {
        self::assertSame([], \Gojira_Env::checkPlaceholders(['DB_USER' => 'app', 'DB_PWD' => 's3nh4-real'], 'PROD'));
    }
}
