<?php

namespace Gojira\Tests\Unit\Dispatch;

use Gojira\Tests\Support\FakeLogin;
use PHPUnit\Framework\TestCase;

/**
 * Controller::Call: só método público é despachável, e a AccessPolicy,
 * quando registrada, decide antes do beforeCall.
 */
final class ControllerCallTest extends TestCase
{
    /** @var string */
    private static $root;

    /** @var \stdClass */
    private static $globals;

    public static function setUpBeforeClass(): void
    {
        $root = sys_get_temp_dir() . '/gojira-call-' . bin2hex(random_bytes(4));
        mkdir($root, 0777, true);

        $base = 'function __construct($globals) { $this->globals = $globals; }
            public function aberto($p) { return "aberto"; }
            public function painel($p) { return "painel"; }
            protected function interno($p) { return "interno"; }
            private function segredo($p) { return "segredo"; }';

        file_put_contents($root . '/callprobe.php', '<?php class Callprobe_Controller extends Controller { ' . $base . ' }');
        file_put_contents($root . '/callhandled.php', '<?php class Callhandled_Controller extends Controller { ' . $base . '
            public $allow = true;
            function beforeCall($c, $m, $p) { return $m !== "painel"; }
            function not_found($m, $p) { return "not_found:" . $m; }
            function unauthorized($m, $p) { return "unauthorized:" . $m; }
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

    private function call(string $class, string $method)
    {
        return \Controller::Call($class, $method, [], self::$globals);
    }

    private function expectCode(int $code, string $class, string $method): void
    {
        try {
            $this->call($class, $method);
            self::fail($class . '/' . $method . ' foi despachado');
        } catch (\ControllerException $e) {
            self::assertSame($code, $e->getCode());
        }
    }

    public function testPublicMethodIsDispatched(): void
    {
        self::assertSame('aberto', $this->call('callprobe', 'aberto'));
    }

    /** @return array<string, array{string}> */
    public function hiddenMethods(): array
    {
        return ['protected' => ['interno'], 'private' => ['segredo']];
    }

    /** @dataProvider hiddenMethods */
    public function testNonPublicMethodIsNotFound(string $method): void
    {
        $this->expectCode(0x1011, 'callprobe', $method);
    }

    /** @dataProvider hiddenMethods */
    public function testNonPublicMethodGoesToNotFoundHandler(string $method): void
    {
        self::assertSame('not_found:' . $method, $this->call('callhandled', $method));
    }

    public function testWithoutPolicyBeforeCallStillDecides(): void
    {
        self::assertNull(\Controller::getAccessPolicy());
        self::assertSame('aberto', $this->call('callhandled', 'aberto'));
        self::assertSame('unauthorized:painel', $this->call('callhandled', 'painel'));
    }

    public function testPolicyDeniesUndeclaredMethod(): void
    {
        \Controller::setAccessPolicy(new \Gojira_MapAccessPolicy([], new FakeLogin()));

        $this->expectCode(0x1012, 'callprobe', 'aberto');
    }

    public function testPolicyDenialUsesUnauthorizedHandler(): void
    {
        \Controller::setAccessPolicy(new \Gojira_MapAccessPolicy([], new FakeLogin()));

        self::assertSame('unauthorized:aberto', $this->call('callhandled', 'aberto'));
    }

    public function testPolicyAllowsPublicMethodWithoutLogin(): void
    {
        \Controller::setAccessPolicy(new \Gojira_MapAccessPolicy(['callprobe' => ['public' => ['aberto']]], new FakeLogin()));

        self::assertSame('aberto', $this->call('callprobe', 'aberto'));
        $this->expectCode(0x1012, 'callprobe', 'painel');
    }

    public function testPolicyAllowsRequiredRole(): void
    {
        $login         = new FakeLogin();
        $login->logged = ['ADM'];
        \Controller::setAccessPolicy(new \Gojira_MapAccessPolicy(['callprobe' => ['requires' => ['ADM']]], $login));

        self::assertSame('painel', $this->call('callprobe', 'painel'));
    }

    public function testPolicyDoesNotRevealHiddenMethods(): void
    {
        \Controller::setAccessPolicy(new \Gojira_MapAccessPolicy(['callprobe' => ['public' => true]], new FakeLogin()));

        $this->expectCode(0x1011, 'callprobe', 'interno');
    }

    public function testBeforeCallStillRunsAfterThePolicy(): void
    {
        $login         = new FakeLogin();
        $login->logged = ['ADM'];
        \Controller::setAccessPolicy(new \Gojira_MapAccessPolicy(['callhandled' => ['requires' => ['ADM']]], $login));

        self::assertSame('unauthorized:painel', $this->call('callhandled', 'painel'));
    }
}
