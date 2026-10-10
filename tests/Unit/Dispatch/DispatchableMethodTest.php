<?php

namespace Gojira\Tests\Unit\Dispatch;

use PHPUnit\Framework\TestCase;

/**
 * Controller::Call só despacha método público, não estático, sem prefixo "__",
 * declarado no controller do projeto (ou no AppController do projeto) e cujo
 * nome não exista em Controller/GojiraCore. Todo o resto responde como método
 * inexistente (0x1011 ou not_found), em qualquer combinação de maiúsculas.
 */
final class DispatchableMethodTest extends TestCase
{
    /** @var string */
    private static $root;

    /** @var \stdClass */
    private static $globals;

    public static function setUpBeforeClass(): void
    {
        // O init.php declara AppController (do projeto ou vazio). Aqui ele faz o
        // papel do AppController de um projeto, com uma action legítima.
        if (!class_exists('AppController', false)) {
            eval('class AppController extends Controller {
                public function appacao($p) { return "appacao"; }
                protected function appinterno($p) { return "appinterno"; }
            }');
        }
        if (!method_exists('AppController', 'appacao')) {
            self::fail('AppController já declarado sem a action de teste');
        }

        $root = sys_get_temp_dir() . '/gojira-dispatch-' . bin2hex(random_bytes(4));
        mkdir($root, 0777, true);

        $body = 'function __construct($globals) { $this->globals = $globals; }
            public $loads = 0;
            public function index($p) { return "index"; }
            public static function estatico($p) { return "estatico"; }
            function onLoad() { $this->loads++; }
            function beforeCall($c, $m, $p) { return true; }';

        file_put_contents($root . '/dispprobe.php', '<?php class Dispprobe_Controller extends AppController { ' . $body . ' }');
        file_put_contents($root . '/disphandled.php', '<?php class Disphandled_Controller extends AppController { ' . $body . '
            function not_found($m, $p) { return "not_found"; }
        }');

        $globals                              = new \stdClass();
        $globals->environment                 = new \stdClass();
        $globals->environment->controllerPath = $root . '/';

        self::$root    = $root;
        self::$globals = $globals;
    }

    public static function tearDownAfterClass(): void
    {
        array_map('unlink', glob(self::$root . '/*.php'));
        rmdir(self::$root);
    }

    protected function tearDown(): void
    {
        \Controller::setAccessPolicy(null);
    }

    /** @param mixed $method */
    private function call(string $class, $method)
    {
        return \Controller::Call($class, $method, [], self::$globals);
    }

    /** @return array<string, array{string}> */
    public function blockedMethods(): array
    {
        $names = [
            // Construtores e mágicos (GojiraCore e PHP)
            '__construct', '__CONSTRUCT', '__Construct', '__construct1', '__construct2',
            '__get', '__set', '__SET', '__isset', '__unset', '__absPath', '__call', '__destruct',
            // Ganchos de Controller, inclusive sobrescritos no controller do projeto
            'onLoad', 'onload', 'ONLOAD', 'beforeCall', 'beforecall', 'BEFORECALL',
            // Estáticos de Controller e GojiraCore
            'setAccessPolicy', 'setaccesspolicy', 'SETACCESSPOLICY', 'getAccessPolicy',
            'Load', 'load', 'Call', 'CALL', 'Exists', 'isSafeName', 'confinedFile',
            'isDispatchable', 'postDispatch',
            // Estático declarado no próprio controller
            'estatico', 'ESTATICO',
            // Não público no AppController do projeto
            'appinterno',
            // Nomes que não são método
            '', 'naoexiste',
        ];

        $cases = [];
        foreach ($names as $name) {
            $cases[$name === '' ? '(vazio)' : $name] = [$name];
        }

        return $cases;
    }

    /** @dataProvider blockedMethods */
    public function testBlockedMethodIsNotFound(string $method): void
    {
        try {
            $this->call('dispprobe', $method);
            self::fail('dispprobe/' . $method . ' foi despachado');
        } catch (\ControllerException $e) {
            self::assertSame(0x1011, $e->getCode());
        }
    }

    /** @dataProvider blockedMethods */
    public function testBlockedMethodGoesToNotFoundHandler(string $method): void
    {
        self::assertSame('not_found', $this->call('disphandled', $method));
    }

    public function testSetAccessPolicyThroughDispatchDoesNotChangeThePolicy(): void
    {
        self::assertSame('not_found', $this->call('disphandled', 'setAccessPolicy'));
        self::assertNull(\Controller::getAccessPolicy());
    }

    public function testNonStringMethodIsNotFound(): void
    {
        self::assertSame('not_found', $this->call('disphandled', ['index']));
    }

    /** @return array<string, array{string, string}> */
    public function legitimateActions(): array
    {
        return [
            'action do controller'               => ['index', 'index'],
            'action do controller em maiúsculas' => ['INDEX', 'index'],
            'action do AppController do projeto' => ['appacao', 'appacao'],
            'action do AppController, misturada' => ['AppAcao', 'appacao'],
        ];
    }

    /** @dataProvider legitimateActions */
    public function testLegitimateActionIsDispatched(string $method, string $expected): void
    {
        self::assertSame($expected, $this->call('dispprobe', $method));
        self::assertSame($expected, $this->call('disphandled', $method));
    }
}
