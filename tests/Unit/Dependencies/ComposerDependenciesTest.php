<?php

namespace Gojira\Tests\Unit\Dependencies;

use PHPUnit\Framework\TestCase;

/**
 * Dependências do framework: composer.json, composer.lock e system/vendor.
 */
final class ComposerDependenciesTest extends TestCase
{
    private static function json(string $file): array
    {
        $data = json_decode((string) file_get_contents(GOJIRA_TESTS_ROOT . $file), true);
        self::assertIsArray($data, $file . ' não é JSON válido');

        return $data;
    }

    /** @return array<string, string> nome => versão */
    private static function lockedPackages(): array
    {
        $lock = self::json('composer.lock');
        $packages = [];
        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
            $packages[$package['name']] = $package['version'];
        }

        return $packages;
    }

    private static function normalize(string $version): string
    {
        return ltrim($version, 'v');
    }

    public function testComposerJsonOnlyAcceptsStablePackages(): void
    {
        $composer = self::json('composer.json');

        self::assertSame('stable', $composer['minimum-stability']);
        self::assertTrue($composer['prefer-stable']);
    }

    public function testPhpMailerComesFromComposer(): void
    {
        $composer = self::json('composer.json');

        self::assertSame('^6.9', $composer['require']['phpmailer/phpmailer'] ?? null);
    }

    public function testLockedPlatformMatchesTheMinimumPhpVersion(): void
    {
        $composer = self::json('composer.json');

        self::assertSame('>=8.0.0', $composer['require']['php']);
        self::assertSame('8.0.2', $composer['config']['platform']['php'] ?? null);
    }

    public function testLockHasNoDevelopmentVersions(): void
    {
        $lock = self::json('composer.lock');
        self::assertSame('stable', $lock['minimum-stability']);

        foreach (self::lockedPackages() as $name => $version) {
            self::assertDoesNotMatchRegularExpression('/(^dev-|-dev$|-dev\b)/i', $version, $name . ' está em versão de desenvolvimento');
        }
    }

    public function testLockedVersionsAreAtLeastTheFixedReleases(): void
    {
        $packages = self::lockedPackages();

        self::assertArrayHasKey('phpmailer/phpmailer', $packages);
        self::assertTrue(version_compare(self::normalize($packages['phpmailer/phpmailer']), '6.9.0', '>='));
        self::assertTrue(version_compare(self::normalize($packages['phpmailer/phpmailer']), '7.0.0', '<'));

        self::assertArrayHasKey('smarty/smarty', $packages);
        self::assertTrue(version_compare(self::normalize($packages['smarty/smarty']), '5.8.4', '>='));
    }

    public function testInstalledVendorMatchesTheLock(): void
    {
        $installed = require GOJIRA_SYSTEM_PATH . 'vendor/composer/installed.php';

        foreach (self::lockedPackages() as $name => $version) {
            self::assertArrayHasKey($name, $installed['versions'], $name . ' não está em system/vendor');
            self::assertSame($version, $installed['versions'][$name]['pretty_version'], $name . ' em system/vendor difere do composer.lock');
        }
    }

    public function testBundledLegacyCopiesAndSamplesAreGone(): void
    {
        $vendor = GOJIRA_SYSTEM_PATH . 'vendor/';

        self::assertDirectoryDoesNotExist($vendor . 'PHPMailer-6.0.7');
        self::assertDirectoryDoesNotExist($vendor . 'nusoap-php7/samples');
        self::assertFileDoesNotExist($vendor . 'phpmailer/phpmailer/get_oauth_token.php');
        self::assertSame([], glob($vendor . '*/get_oauth_token.php') ?: []);
        self::assertSame([], glob($vendor . '*/*/get_oauth_token.php') ?: []);
    }

    public function testNetworkToolNoLongerPointsToTheBundledCopy(): void
    {
        $source = (string) file_get_contents(GOJIRA_SYSTEM_PATH . 'class/tool/network.class.php');

        self::assertStringNotContainsString('PHPMailer-6.0.7', $source);
        self::assertStringContainsString("vendor/autoload.php", $source);
    }
}
