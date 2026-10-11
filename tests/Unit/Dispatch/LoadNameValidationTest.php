<?php

namespace Gojira\Tests\Unit\Dispatch;

use PHPUnit\Framework\TestCase;

/**
 * Controller, Model e Component só carregam arquivos com nome seguro e,
 * no caso de Controller e Model, confinados ao próprio diretório.
 *
 * Os arquivos de apoio são gerados num diretório temporário: um nome válido
 * para cada tipo, um arquivo fora dos diretórios permitidos e um link
 * simbólico que aponta para fora.
 */
final class LoadNameValidationTest extends TestCase
{
    /** @var string */
    private static $root;

    /** @var \stdClass */
    private static $globals;

    public static function setUpBeforeClass(): void
    {
        $root = sys_get_temp_dir() . '/gojira-load-' . bin2hex(random_bytes(4));
        foreach (['app/controller', 'app/model', 'app/component/controller', 'app/component/model'] as $dir) {
            mkdir($root . '/' . $dir, 0777, true);
        }

        file_put_contents($root . '/app/controller/loadprobe.php', '<?php
            class Loadprobe_Controller extends Controller {
                function __construct($globals) { $this->globals = $globals; }
            }');
        file_put_contents($root . '/app/model/loadprobe.php', '<?php class Loadprobe_Model extends Model {}');
        file_put_contents($root . '/app/component/controller/loadprobe.php', '<?php class Loadprobe_ComponentController extends Component {}');
        file_put_contents($root . '/app/component/model/loadprobe.php', '<?php class Loadprobe_ComponentModel {}');
        file_put_contents($root . '/app/component/controller/nomodelprobe.php', '<?php class Nomodelprobe_ComponentController extends Component {}');

        // Component sem model usa AppModel, que o init.php declara.
        if (!class_exists('AppModel')) {
            eval('class AppModel extends Model {}');
        }

        // Arquivos fora dos diretórios permitidos. Se forem incluídos, definem a constante.
        foreach (['controller', 'model', 'component'] as $kind) {
            file_put_contents(
                $root . '/outside_' . $kind . '.php',
                '<?php define("GOJIRA_TEST_OUTSIDE_' . strtoupper($kind) . '_INCLUDED", true);'
                . ' class Linked_' . ucfirst($kind) . 'Probe {}'
            );
        }

        $env                          = new \stdClass();
        $env->controllerPath          = $root . '/app/controller/';
        $env->modelPath               = $root . '/app/model/';
        $env->componentControllerPath = $root . '/app/component/controller/';
        $env->componentModelPath      = $root . '/app/component/model/';

        $globals              = new \stdClass();
        $globals->environment = $env;

        self::$root    = $root;
        self::$globals = $globals;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$root === null || !is_dir(self::$root)) {
            return;
        }
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
        // Exists() lê $GLOBALS['globals'].
        $GLOBALS['globals'] = self::$globals;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['globals']);
    }

    /** @return array<string, array{string}> */
    public function unsafeNames(): array
    {
        return [
            'diretório pai'    => ['../outside_controller'],
            'barra'            => ['sub/loadprobe'],
            'byte nulo'        => ["loadprobe\0"],
            'caminho absoluto' => ['/etc/hosts'],
            'vazio'            => [''],
        ];
    }

    /** @dataProvider unsafeNames */
    public function testControllerLoadRejectsUnsafeName(string $name): void
    {
        $this->expectException(\ControllerException::class);
        $this->expectExceptionCode(0x1001);
        $this->expectExceptionMessage('Invalid controller name');

        \Controller::Load($name, self::$globals);
    }

    /** @dataProvider unsafeNames */
    public function testModelLoadRejectsUnsafeName(string $name): void
    {
        $this->expectException(\ModelException::class);
        $this->expectExceptionCode(0x2001);
        $this->expectExceptionMessage('Invalid model name');

        \Model::Load($name, self::$globals);
    }

    /** @dataProvider unsafeNames */
    public function testComponentLoadRejectsUnsafeName(string $name): void
    {
        $this->expectException(\ComponentException::class);
        $this->expectExceptionCode(0x3001);
        $this->expectExceptionMessage('Invalid component name');

        \Component::Load($name, self::$globals);
    }

    /** @dataProvider unsafeNames */
    public function testExistsReturnsFalseForUnsafeName(string $name): void
    {
        self::assertFalse(\Controller::Exists($name));
        self::assertFalse(\Model::Exists($name));
    }

    public function testTraversalNeverIncludesFileOutsideTheDirectories(): void
    {
        foreach (['controller' => \Controller::class, 'model' => \Model::class, 'component' => \Component::class] as $kind => $class) {
            try {
                $class::Load('../../outside_' . $kind, self::$globals);
                self::fail($class . '::Load aceitou um nome com diretório pai');
            } catch (\Exception $e) {
                self::assertStringStartsWith('Invalid ' . $kind . ' name', $e->getMessage());
            }
            self::assertFalse(defined('GOJIRA_TEST_OUTSIDE_' . strtoupper($kind) . '_INCLUDED'));
        }
    }

    public function testValidNamesStillLoad(): void
    {
        self::assertInstanceOf('Loadprobe_Controller', \Controller::Load('loadprobe', self::$globals));
        self::assertInstanceOf('Loadprobe_Model', \Model::Load('loadprobe', self::$globals));
        self::assertInstanceOf('Loadprobe_ComponentController', \Component::Load('loadprobe', self::$globals));
        self::assertTrue(\Controller::Exists('loadprobe'));
        self::assertTrue(\Model::Exists('loadprobe'));
    }

    public function testMissingValidNameKeepsNotFoundError(): void
    {
        try {
            \Controller::Load('missingprobe', self::$globals);
            self::fail('Controller inexistente foi carregado');
        } catch (\ControllerException $e) {
            self::assertSame(0x1001, $e->getCode());
            self::assertStringContainsString('not found', $e->getMessage());
        }

        $this->expectException(\ModelException::class);
        $this->expectExceptionCode(0x2001);
        $this->expectExceptionMessageMatches('/not found/');
        \Model::Load('missingprobe', self::$globals);
    }

    /** @return array<string, array{string, string, string, string}> */
    public function linkedKinds(): array
    {
        return [
            'controller' => ['controller', 'controller', \Controller::class, \ControllerException::class],
            'model'      => ['model', 'model', \Model::class, \ModelException::class],
            'component'  => ['component', 'component/controller', \Component::class, \ComponentException::class],
        ];
    }

    private function linkOutside(string $kind, string $dir, string $name): string
    {
        $link = self::$root . '/app/' . $dir . '/' . $name . '.php';
        if (!@symlink(self::$root . '/outside_' . $kind . '.php', $link)) {
            self::markTestSkipped('O sistema de arquivos não permite link simbólico.');
        }

        return $link;
    }

    /** @dataProvider linkedKinds */
    public function testSymlinkPointingOutsideIsNotLoaded(string $kind, string $dir, string $class, string $exception): void
    {
        $link = $this->linkOutside($kind, $dir, 'linkedprobe');

        try {
            $class::Load('linkedprobe', self::$globals);
            self::fail($class . '::Load seguiu um link para fora do diretório');
        } catch (\Exception $e) {
            self::assertInstanceOf($exception, $e);
            self::assertStringContainsString('not found', $e->getMessage());
        } finally {
            unlink($link);
        }

        self::assertFalse(defined('GOJIRA_TEST_OUTSIDE_' . strtoupper($kind) . '_INCLUDED'));
    }

    /** @return array<string, array{string, string, string}> */
    public function linkedExistsKinds(): array
    {
        return [
            'controller' => ['controller', 'controller', \Controller::class],
            'model'      => ['model', 'model', \Model::class],
        ];
    }

    /** @dataProvider linkedExistsKinds */
    public function testExistsDoesNotFollowSymlinkOutside(string $kind, string $dir, string $class): void
    {
        $link = $this->linkOutside($kind, $dir, 'linkedexists');

        try {
            self::assertFalse($class::Exists('linkedexists'));
        } finally {
            unlink($link);
        }

        self::assertFalse(defined('GOJIRA_TEST_OUTSIDE_' . strtoupper($kind) . '_INCLUDED'));
    }

    public function testComponentModelSymlinkOutsideIsIgnored(): void
    {
        $link = $this->linkOutside('model', 'component/model', 'nomodelprobe');

        try {
            $component = \Component::Load('nomodelprobe', self::$globals);
        } finally {
            unlink($link);
        }

        self::assertInstanceOf('Nomodelprobe_ComponentController', $component);
        self::assertFalse(defined('GOJIRA_TEST_OUTSIDE_MODEL_INCLUDED'));
    }

    public function testConfinedFileResolvesOnlyFilesInsideTheDirectory(): void
    {
        $dir = self::$root . '/app/controller/';

        self::assertSame(realpath($dir . 'loadprobe.php'), \GojiraCore::confinedFile($dir, 'loadprobe.php'));
        self::assertSame(realpath($dir . 'loadprobe.php'), \GojiraCore::confinedFile(rtrim($dir, '/'), '/loadprobe.php'));
        self::assertFalse(\GojiraCore::confinedFile($dir, 'missing.php'));
        self::assertFalse(\GojiraCore::confinedFile($dir, '../model/loadprobe.php'));
        self::assertFalse(\GojiraCore::confinedFile($dir, ''));
        self::assertFalse(\GojiraCore::confinedFile(self::$root . '/missing-dir/', 'loadprobe.php'));
    }

    public function testConfinedFileAlwaysJoinsWithASeparator(): void
    {
        $dir      = self::$root . '/app/controller';
        $expected = realpath($dir . '/loadprobe.php');

        // Sem barra final em $dir, com barra no início de $file, com barras repetidas.
        self::assertSame($expected, \GojiraCore::confinedFile($dir, 'loadprobe.php'));
        self::assertSame($expected, \GojiraCore::confinedFile($dir . '/', 'loadprobe.php'));
        self::assertSame($expected, \GojiraCore::confinedFile($dir . '//', '/loadprobe.php'));

        // Sem o separador, "controller" + "x.php" viraria um irmão do diretório.
        file_put_contents(self::$root . '/app/controllersibling.php', '<?php');
        try {
            self::assertFalse(\GojiraCore::confinedFile($dir, 'sibling.php'));
        } finally {
            unlink(self::$root . '/app/controllersibling.php');
        }
    }
}
