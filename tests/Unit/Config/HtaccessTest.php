<?php

namespace Gojira\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

/**
 * project_demo/.htaccess nega .env e .ini sem depender do mod_rewrite.
 * Confere a expressão do FilesMatch (PCRE, a mesma família do Apache).
 */
final class HtaccessTest extends TestCase
{
    private function filesMatchPattern(): string
    {
        $htaccess = (string) file_get_contents(GOJIRA_TESTS_ROOT . 'project_demo/.htaccess');
        self::assertSame(1, preg_match('/<FilesMatch "([^"]+)">\s*<IfModule mod_authz_core\.c>\s*Require all denied/', $htaccess, $m));
        return $m[1];
    }

    /** @return array<string, array{string, bool}> */
    public function fileProvider(): array
    {
        return [
            '.env'            => ['.env', true],
            '.env.example'    => ['.env.example', true],
            '.ENV maiúsculo'  => ['.ENV', true],
            'app_configs.ini' => ['app_configs.ini', true],
            'INI maiúsculo'   => ['APP_CONFIGS.INI', true],
            'backup .ini.bak' => ['app_configs.ini.bak', true],
            'backup .ini~'    => ['app_configs.ini~', true],
            'backup .ini.1'   => ['app_configs.ini.1', true],
            'configs.php'     => ['configs.php', false],
            'style.css'       => ['style.css', false],
            'env.js'          => ['env.js', false],
            'ini.html'        => ['ini.html', false],
        ];
    }

    /** @dataProvider fileProvider */
    public function testDeniedFiles(string $file, bool $denied): void
    {
        self::assertSame($denied, preg_match('/' . str_replace('/', '\/', $this->filesMatchPattern()) . '/', $file) === 1);
    }

    public function testRootGitignoreKeepsLocalConfigurationOut(): void
    {
        $ignore = (string) file_get_contents(GOJIRA_TESTS_ROOT . '.gitignore');
        foreach (['app_configs.ini', '.env', '!.env.example'] as $line) {
            self::assertContains($line, array_map('trim', explode("\n", $ignore)));
        }
    }
}
