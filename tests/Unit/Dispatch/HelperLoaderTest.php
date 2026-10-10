<?php

namespace Gojira\Tests\Unit\Dispatch;

use PHPUnit\Framework\TestCase;

/**
 * helper_loader (system/inc/helpers.php) só inclui arquivo com nome seguro em
 * cada segmento do namespace e confinado ao diretório onde procura, como os
 * loaders de Controller, Model e Component.
 */
final class HelperLoaderTest extends TestCase
{
    /** @var string */
    private static $root;

    /** @var mixed */
    private $previousGlobals;

    public static function setUpBeforeClass(): void
    {
        // helpers.php registra o autoload ao ser incluído. Os testes chamam a função
        // diretamente; o autoload global sai para não afetar os outros testes.
        require_once GOJIRA_SYSTEM_PATH . 'inc/helpers.php';
        spl_autoload_unregister('helper_loader');

        $root = sys_get_temp_dir() . '/gojira-helper-' . bin2hex(random_bytes(4));
        foreach (['vendor', 'inc/Hlpns', 'sysvendor'] as $dir) {
            mkdir($root . '/' . $dir, 0777, true);
        }

        file_put_contents($root . '/vendor/Hlpvendorprobe.php', '<?php class Hlpvendorprobe {}');
        file_put_contents($root . '/inc/Hlpns/Hlpnsprobe.php', '<?php namespace Hlpns; class Hlpnsprobe {}');
        file_put_contents($root . '/inc/hlplowerprobe.php', '<?php class HlpLowerProbe {}');
        file_put_contents($root . '/sysvendor/Hlpsysprobe.php', '<?php class Hlpsysprobe {}');

        // Arquivo fora dos diretórios permitidos. Se for incluído, define a constante.
        file_put_contents($root . '/outside.php', '<?php define("GOJIRA_TEST_HELPER_OUTSIDE_INCLUDED", true);');
        symlink($root . '/outside.php', $root . '/vendor/Hlplinked.php');
        symlink($root . '/outside.php', $root . '/inc/Hlpns/Hlplinked.php');

        self::$root = $root;
    }

    public static function tearDownAfterClass(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::$root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            ($item->isDir() && !$item->isLink()) ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir(self::$root);
    }

    protected function setUp(): void
    {
        $this->previousGlobals = $GLOBALS['globals'] ?? null;

        $env                   = new \stdClass();
        $env->vendorPath       = self::$root . '/vendor/';
        $env->systemIncPath    = self::$root . '/inc/';
        $env->systemVendorPath = self::$root . '/sysvendor/';

        $GLOBALS['globals']              = new \stdClass();
        $GLOBALS['globals']->environment = $env;
    }

    protected function tearDown(): void
    {
        if ($this->previousGlobals === null) {
            unset($GLOBALS['globals']);
        } else {
            $GLOBALS['globals'] = $this->previousGlobals;
        }

        self::assertFalse(defined('GOJIRA_TEST_HELPER_OUTSIDE_INCLUDED'), 'arquivo fora do diretório foi incluído');
    }

    /** @return array<string, array{string, string}> */
    public function validNames(): array
    {
        return [
            'vendorPath'                   => ['Hlpvendorprobe', 'Hlpvendorprobe'],
            'systemIncPath com namespace'  => ['Hlpns\\Hlpnsprobe', 'Hlpns\\Hlpnsprobe'],
            'systemVendorPath'             => ['Hlpsysprobe', 'Hlpsysprobe'],
            'nome em minúsculas (fallback)' => ['HlpLowerProbe', 'HlpLowerProbe'],
        ];
    }

    /** @dataProvider validNames */
    public function testValidNameIsLoaded(string $name, string $class): void
    {
        \helper_loader($name);

        self::assertTrue(class_exists($class, false));
    }

    /** @return array<string, array{string}> */
    public function invalidNames(): array
    {
        return [
            'subida de diretório'           => ['../outside'],
            'subida dentro do namespace'    => ['Hlpns\\..\\..\\outside'],
            'barra'                         => ['Hlpns/../../outside'],
            'byte nulo'                     => ["outside\0"],
            'segmento vazio'                => ['Hlpns\\\\Hlpnsprobe'],
            'vazio'                         => [''],
            'quebra de linha'               => ["Hlpvendorprobe\n"],
            'ponto'                         => ['outside.php'],
        ];
    }

    /** @dataProvider invalidNames */
    public function testInvalidNameIsNotLoaded(string $name): void
    {
        $before = get_included_files();

        \helper_loader($name);

        self::assertSame($before, get_included_files());
    }

    /** @return array<string, array{string}> */
    public function symlinkNames(): array
    {
        return [
            'link em vendorPath'                => ['Hlplinked'],
            'link em systemIncPath (namespace)' => ['Hlpns\\Hlplinked'],
        ];
    }

    /** @dataProvider symlinkNames */
    public function testSymlinkOutsideTheDirectoryIsNotLoaded(string $name): void
    {
        $before = get_included_files();

        \helper_loader($name);

        self::assertSame($before, get_included_files());
    }
}
