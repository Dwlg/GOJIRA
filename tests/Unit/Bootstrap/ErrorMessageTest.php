<?php

namespace Gojira\Tests\Unit\Bootstrap;

use PHPUnit\Framework\TestCase;

require_once GOJIRA_SYSTEM_PATH . 'class/engine.class.php';

/**
 * Mensagens de erro do framework sem HTML, caminho de arquivo nem SQL, e o
 * corpo de erro do modo JSON (GOJIRA_JSON_RESPONSES).
 */
final class ErrorMessageTest extends TestCase
{
    /** @var string */
    private static $root;

    /** @var \stdClass */
    private static $globals;

    /** @var string|false */
    private $previousLog;

    /** @var string */
    private $log;

    public static function setUpBeforeClass(): void
    {
        $root = sys_get_temp_dir() . '/gojira-errmsg-' . bin2hex(random_bytes(4));
        mkdir($root, 0777, true);
        file_put_contents($root . '/errprobe.php', '<?php class Errprobe_Controller extends Controller {
            function __construct($globals) { $this->globals = $globals; }
            public function aberto($p) { return "aberto"; }
            function beforeCall($c, $m, $p) { return $m !== "aberto"; }
        }');
        file_put_contents($root . '/errwrong.php', '<?php class Outro_Nome_Controller extends Controller {}');

        $globals                              = new \stdClass();
        $globals->environment                 = new \stdClass();
        $globals->environment->controllerPath = $root . '/';

        self::$root    = $root;
        self::$globals = $globals;
    }

    public static function tearDownAfterClass(): void
    {
        array_map('unlink', glob(self::$root . '/*'));
        rmdir(self::$root);
    }

    protected function setUp(): void
    {
        $this->log         = self::$root . '/error.log';
        $this->previousLog = ini_set('error_log', $this->log);
        @unlink($this->log);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->previousLog);
    }

    private function controllerMessage(string $class, string $method): string
    {
        try {
            \Controller::Call($class, $method, [], self::$globals);
        } catch (\ControllerException $e) {
            return $e->getMessage();
        }
        self::fail($class . '/' . $method . ' não lançou ControllerException');
    }

    private function assertClean(string $message): void
    {
        self::assertStringNotContainsString('<', $message);
        self::assertStringNotContainsString('>', $message);
        self::assertStringNotContainsString(self::$root, $message);
        self::assertStringNotContainsString('.php', $message);
    }

    public function testControllerInexistenteSemCaminho(): void
    {
        $message = $this->controllerMessage('naoexiste', 'index');

        self::assertSame('Controller "Naoexiste_Controller" not found', $message);
        $this->assertClean($message);
    }

    public function testClasseDeclaradaErradaSemCaminhoNaMensagemMasNoLog(): void
    {
        $message = $this->controllerMessage('errwrong', 'index');

        self::assertSame('Wrong class declaration for controller "Errwrong_Controller"', $message);
        $this->assertClean($message);
        self::assertStringContainsString(self::$root . '/errwrong.php', (string) file_get_contents($this->log));
    }

    public function testMetodoInexistenteSemHtmlDaUrl(): void
    {
        $message = $this->controllerMessage('errprobe', 'x<script>y');

        self::assertSame('Method "xscripty" not found in Controller Object "errprobe"', $message);
        $this->assertClean($message);
    }

    public function testExecucaoNaoAutorizadaSemHtml(): void
    {
        $message = $this->controllerMessage('errprobe', 'aberto');

        self::assertSame('Unauthorized execution for method "aberto" in Controller Object "errprobe"', $message);
    }

    public function testLabelLimitaTamanhoECaracteres(): void
    {
        self::assertSame('ab_9', \ControllerException::label('a/b_9.."<'));
        self::assertSame(str_repeat('a', 64) . '...', \ControllerException::label(str_repeat('a', 100)));
        self::assertSame('', \ControllerException::label(['x']));
    }

    /**
     * @dataProvider jsonErrors
     */
    public function testJsonErrorMapeiaStatusSemDetalhe(\Throwable $e, string $code): void
    {
        $body = json_decode(\Engine::jsonError($e, 'c', 'p'), true);

        self::assertSame($code, $body['error']['code']);
        self::assertArrayNotHasKey('detail', $body['error'], 'detail só com ENVIRONMENT = DEV');
        self::assertSame(['code', 'message'], array_keys($body['error']));
    }

    public static function jsonErrors(): array
    {
        return [
            '0x1001' => [new \ControllerException('x', 0x1001), 'not_found'],
            '0x1011' => [new \ControllerException('x', 0x1011), 'not_found'],
            '0x1012' => [new \ControllerException('x', 0x1012), 'forbidden'],
            '0x1002' => [new \ControllerException('x', 0x1002), 'server_error'],
            'Exception com código 0x1011 não é 404' => [new \Exception('x', 0x1011), 'server_error'],
            'ModelException' => [new \ModelException('Error while saving', 0x2015), 'server_error'],
        ];
    }

    public function testJsonErrorSemTagLiteralNoCorpo(): void
    {
        $json = \Engine::jsonError(new \Exception('<script>SELECT 1</script> /srv/x.php'));

        self::assertStringNotContainsString('<', $json);
        self::assertStringNotContainsString('SELECT', $json);
        self::assertStringNotContainsString('/srv/', $json);
        self::assertStringContainsString('SELECT 1', (string) file_get_contents($this->log), 'o detalhe vai para o log');
    }

    public function testJsonModeDesligadoPorPadrao(): void
    {
        self::assertFalse(\Engine::jsonMode());
    }
}
