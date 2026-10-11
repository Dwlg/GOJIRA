<?php

namespace Gojira\Tests\Unit\Bootstrap;

use PHPUnit\Framework\TestCase;

/**
 * Bootstrap (system/init.php) e Engine numa requisição HTTP real, pelo servidor
 * embutido do PHP. Cobre as opções da PR8: GOJIRA_NO_SESSION_AUTOSTART,
 * GOJIRA_DISABLE_SMARTY, GOJIRA_JSON_RESPONSES, o nosniff e o comportamento
 * sem nenhuma opção. Não usa banco.
 */
final class BootstrapHttpTest extends TestCase
{
    /** @var string */
    private static $root;

    /** @var resource|null */
    private static $server;

    /** @var string */
    private static $baseUrl;

    /** @var string */
    private static $log;

    public static function setUpBeforeClass(): void
    {
        $root = sys_get_temp_dir() . '/gojira-http-' . bin2hex(random_bytes(4));
        self::copyDir(GOJIRA_TESTS_ROOT . 'tests/Fixtures/bootstrap_app', $root);
        self::$root = $root;
        self::$log  = $root . '/server.log';

        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($probe === false) {
            self::fail('Sem porta livre para o servidor embutido: ' . $errstr);
        }
        $port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $env = getenv();
        $env['GOJIRA_SYSTEM_PATH'] = GOJIRA_SYSTEM_PATH;

        $cmd = [
            PHP_BINARY,
            '-d', 'session.save_path=' . $root,
            '-d', 'variables_order=EGPCS',
            '-S', '127.0.0.1:' . $port,
            $root . '/index.php',
        ];
        self::$server = proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['file', self::$log, 'a'], 2 => ['file', self::$log, 'a']],
            $pipes,
            $root,
            $env
        );
        if (!is_resource(self::$server)) {
            self::fail('Não foi possível iniciar o servidor embutido do PHP');
        }

        self::$baseUrl = 'http://127.0.0.1:' . $port . '/';

        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($sock !== false) {
                fclose($sock);
                return;
            }
            usleep(100000);
        }
        self::fail('O servidor embutido não respondeu. Log: ' . @file_get_contents(self::$log));
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        if (self::$root !== null) {
            self::removeDir(self::$root);
        }
    }

    // --- sem nenhuma opção: comportamento da 1.0 -------------------------------------

    public function testSemOpcaoArrayDoControllerSaiComoAntes(): void
    {
        $r = $this->get('demo/data');

        self::assertSame(200, $r['status']);
        self::assertSame(json_encode(['ok' => true, 'n' => 1]), $r['body'], 'corpo idêntico ao da 1.0');
        // Exceção de segurança à regra opt-in: JSON servido como text/html deixa
        // o navegador interpretar como HTML um valor ecoado da entrada.
        self::assertSame('application/json; charset=utf-8', $r['headers']['content-type']);
        self::assertArrayHasKey('set-cookie', $r['headers'], 'a sessão continua aberta em toda requisição');
    }

    public function testSemOpcaoSmartyCarregadoESessaoAtiva(): void
    {
        $state = json_decode($this->get('demo/state')['body'], true);

        self::assertTrue($state['smarty_loaded']);
        self::assertTrue($state['session_active']);
    }

    public function testSemOpcaoTemplateRenderizadoPeloSmarty(): void
    {
        self::assertSame('view:1', $this->get('demo/view')['body']);
    }

    public function testNosniffEmToda(): void
    {
        foreach (['demo/data', 'demo/text', 'demo/view'] as $path) {
            $r = $this->get($path);
            self::assertSame('nosniff', $r['headers']['x-content-type-options'] ?? null, $path);
        }

        $r = $this->get('demo/data', ['GOJIRA_JSON_RESPONSES']);
        self::assertSame('nosniff', $r['headers']['x-content-type-options'] ?? null);
    }

    public function testNosniffPodeSerDesligado(): void
    {
        $r = $this->get('demo/data', ['GOJIRA_DISABLE_NOSNIFF']);

        self::assertArrayNotHasKey('x-content-type-options', $r['headers']);
    }

    public function testSemOpcaoMetodoInexistenteNaoEcoaHtml(): void
    {
        $r = $this->get('demo/' . rawurlencode('nada<i>x</i>'));

        // A mensagem da exceção não traz HTML nem o nome cru vindo da URL. (O
        // stack trace que o PHP mostra com display_errors ligado está fora do
        // framework; em produção display_errors fica desligado.)
        self::assertStringContainsString('Method "nadaix" not found in Controller Object "demo"', $r['body']);
        self::assertStringNotContainsString('<strong>', $r['body']);
    }

    // --- GOJIRA_NO_SESSION_AUTOSTART ---------------------------------------------------

    public function testSessaoSobDemandaNaoEnviaCookieSemLogin(): void
    {
        $r = $this->get('demo/state', ['GOJIRA_NO_SESSION_AUTOSTART']);

        self::assertArrayNotHasKey('set-cookie', $r['headers']);
        self::assertFalse(json_decode($r['body'], true)['session_active']);
    }

    public function testSessaoSobDemandaCookieEndurecido(): void
    {
        $r = $this->get('demo/login', ['GOJIRA_NO_SESSION_AUTOSTART']);

        self::assertTrue(json_decode($r['body'], true)['started']);
        $cookie = $this->sessionCookie($r);
        self::assertMatchesRegularExpression('/;\s*HttpOnly/i', $cookie);
        self::assertMatchesRegularExpression('/;\s*SameSite=Lax/i', $cookie);
        self::assertDoesNotMatchRegularExpression('/;\s*Secure/i', $cookie, 'HTTP puro não marca Secure');
    }

    public function testSessaoSobDemandaSecureAtrasDeProxyConfiavel(): void
    {
        $r = $this->get('demo/login', ['GOJIRA_NO_SESSION_AUTOSTART', 'TRUST_LOCAL_PROXY'], ['X-Forwarded-Proto: https']);

        self::assertMatchesRegularExpression('/;\s*Secure/i', $this->sessionCookie($r));
    }

    public function testXForwardedProtoSemProxyConfiavelNaoMarcaSecure(): void
    {
        $r = $this->get('demo/login', ['GOJIRA_NO_SESSION_AUTOSTART'], ['X-Forwarded-Proto: https']);

        self::assertDoesNotMatchRegularExpression('/;\s*Secure/i', $this->sessionCookie($r));
    }

    public function testSessaoSobDemandaRecusaIdNaoEmitido(): void
    {
        $forjado = 'idforjadopeloclient1234567890';
        $r = $this->get('demo/login', ['GOJIRA_NO_SESSION_AUTOSTART'], ['Cookie: PHPSESSID=' . $forjado]);

        $cookie = $this->sessionCookie($r);
        self::assertStringStartsWith('PHPSESSID=', $cookie);
        self::assertStringNotContainsString($forjado, $cookie, 'use_strict_mode troca o id que o servidor não emitiu');
    }

    public function testSessaoSobDemandaReabreComCookieExistente(): void
    {
        $login = $this->get('demo/login', ['GOJIRA_NO_SESSION_AUTOSTART']);
        $pair  = explode(';', $this->sessionCookie($login))[0];

        $r = $this->get('demo/resume', ['GOJIRA_NO_SESSION_AUTOSTART'], ['Cookie: ' . $pair]);
        self::assertSame(['resumed' => true, 'seen' => true], $r['json']);

        $anon = $this->get('demo/resume', ['GOJIRA_NO_SESSION_AUTOSTART']);
        self::assertSame(['resumed' => false, 'seen' => null], $anon['json']);
        self::assertArrayNotHasKey('set-cookie', $anon['headers']);
    }

    public function testEndurecimentoNaAberturaAutomatica(): void
    {
        $r = $this->get('demo/data', ['GOJIRA_SESSION_HARDENING']);

        $cookie = $this->sessionCookie($r);
        self::assertMatchesRegularExpression('/;\s*HttpOnly/i', $cookie);
        self::assertMatchesRegularExpression('/;\s*SameSite=Lax/i', $cookie);
    }

    // --- GOJIRA_DISABLE_SMARTY ---------------------------------------------------------

    public function testSmartyDesativadoNaoCarregaAClasse(): void
    {
        $r = $this->get('demo/state', ['GOJIRA_DISABLE_SMARTY']);

        self::assertSame(200, $r['status']);
        self::assertFalse(json_decode($r['body'], true)['smarty_loaded']);
        self::assertSame(json_encode(['ok' => true, 'n' => 1]), $this->get('demo/data', ['GOJIRA_DISABLE_SMARTY'])['body']);
    }

    public function testSmartyDesativadoComTemplateFalhaComMensagemClara(): void
    {
        $r = $this->get('demo/view', ['GOJIRA_DISABLE_SMARTY']);

        // Sem Error_Controller a exceção sobe como antes; a mensagem diz o motivo.
        self::assertStringContainsString('Gojira_SmartyDisabledException', $r['body']);
        self::assertStringContainsString('GOJIRA_DISABLE_SMARTY', $r['body']);
    }

    // --- GOJIRA_JSON_RESPONSES ---------------------------------------------------------

    public function testJsonContentTypeQuandoControllerDevolveArray(): void
    {
        $r = $this->get('demo/data', ['GOJIRA_JSON_RESPONSES']);

        self::assertSame(200, $r['status']);
        self::assertSame('application/json; charset=utf-8', $r['headers']['content-type']);
        self::assertSame(json_encode(['ok' => true, 'n' => 1]), $r['body']);
    }

    public function testJsonComEcoDeEntradaNuncaSaiComoHtml(): void
    {
        $r = $this->get('demo/echo/v/' . rawurlencode('<img src=x onerror=alert(1)>'));

        self::assertSame('application/json; charset=utf-8', $r['headers']['content-type']);
        self::assertSame('nosniff', $r['headers']['x-content-type-options']);
        self::assertStringNotContainsString('<', $r['body'], 'o < sai como \\u003C');
        self::assertStringContainsString('<img', $r['json']['v'], 'o valor decodificado é o mesmo');
    }

    public function testFalhaDeCodificacaoJsonNaoViraRespostaVazia(): void
    {
        $legacy = $this->get('demo/nan');
        self::assertStringContainsString('JSON encoding failed', $legacy['body']);

        $json = $this->get('demo/nan', ['GOJIRA_JSON_RESPONSES']);
        self::assertSame(500, $json['status']);
        self::assertSame('server_error', $json['json']['error']['code']);
    }

    public function testJsonNaoMudaRespostaEmTexto(): void
    {
        $r = $this->get('demo/text', ['GOJIRA_JSON_RESPONSES']);

        self::assertSame('plain text', $r['body']);
        self::assertStringStartsWith('text/html', (string) $r['headers']['content-type']);
    }

    /**
     * @dataProvider errosJson
     */
    public function testJsonErroComStatusCorretoSemDetalheInterno(string $path, int $status, string $code, array $opts = []): void
    {
        $r = $this->get($path, array_merge(['GOJIRA_JSON_RESPONSES'], $opts));

        self::assertSame($status, $r['status']);
        self::assertSame('application/json; charset=utf-8', $r['headers']['content-type']);
        self::assertSame(['error' => ['code' => $code, 'message' => $r['json']['error']['message']]], $r['json']);
        self::assertStringNotContainsString('<', $r['body']);
        self::assertStringNotContainsString('/srv/', $r['body']);
        self::assertStringNotContainsString(self::$root, $r['body']);
        self::assertStringNotContainsString(GOJIRA_SYSTEM_PATH, $r['body']);
        self::assertStringNotContainsStringIgnoringCase('select', $r['body']);
    }

    public static function errosJson(): array
    {
        return [
            'controller inexistente' => ['nada/index', 404, 'not_found'],
            'método inexistente'     => ['demo/nada', 404, 'not_found'],
            'beforeCall recusa'      => ['demo/secret', 403, 'forbidden'],
            'exceção do controller'  => ['demo/boom', 500, 'server_error'],
            'template sem Smarty'    => ['demo/view', 500, 'server_error', ['GOJIRA_DISABLE_SMARTY']],
        ];
    }

    public function testJsonErroInternoVaiParaOLog(): void
    {
        $this->get('demo/boom', ['GOJIRA_JSON_RESPONSES']);

        self::assertStringContainsString('SELECT secret_col', (string) file_get_contents(self::$log));
    }

    public function testJsonErroEmDevTrazDetalheSemTagLiteral(): void
    {
        $r = $this->get('demo/boom', ['GOJIRA_JSON_RESPONSES', 'DEV']);

        self::assertSame(500, $r['status']);
        self::assertStringContainsString('SELECT secret_col', $r['json']['error']['detail']);
        self::assertStringNotContainsString('<', $r['body']);
    }

    public function testJsonComSmartyDesativado(): void
    {
        $r = $this->get('demo/data', ['GOJIRA_JSON_RESPONSES', 'GOJIRA_DISABLE_SMARTY', 'GOJIRA_NO_SESSION_AUTOSTART']);

        self::assertSame('application/json; charset=utf-8', $r['headers']['content-type']);
        self::assertArrayNotHasKey('set-cookie', $r['headers']);
    }

    // --- apoio -------------------------------------------------------------------------

    /**
     * @param string[] $opts
     * @param string[] $headers
     */
    private function get(string $path, array $opts = [], array $headers = []): array
    {
        if ($opts !== []) {
            $headers[] = 'X-Gojira-Test-Opts: ' . implode(',', $opts);
        }

        $ctx = stream_context_create(['http' => [
            'method'          => 'GET',
            'header'          => implode("\r\n", $headers),
            'ignore_errors'   => true,
            'follow_location' => 0,
            'timeout'         => 10,
        ]]);

        $body = @file_get_contents(self::$baseUrl . '?query=' . $path, false, $ctx);
        self::assertNotFalse($body, 'sem resposta para ' . $path . '. Log: ' . @file_get_contents(self::$log));

        $raw    = $http_response_header ?? [];
        $status = (int) (explode(' ', (string) ($raw[0] ?? ''))[1] ?? 0);
        $parsed = [];
        foreach (array_slice($raw, 1) as $line) {
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $name  = strtolower(trim(substr($line, 0, $pos)));
            $value = trim(substr($line, $pos + 1));
            // Vários Set-Cookie: interessa o de sessão (o último enviado)
            $parsed[$name] = $value;
        }

        return ['status' => $status, 'headers' => $parsed, 'body' => $body, 'json' => json_decode($body, true)];
    }

    private function sessionCookie(array $r): string
    {
        self::assertArrayHasKey('set-cookie', $r['headers'], 'esperava o cookie de sessão. Corpo: ' . $r['body']);

        return $r['headers']['set-cookie'];
    }

    private static function copyDir(string $from, string $to): void
    {
        mkdir($to, 0777, true);
        foreach (scandir($from) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            if (is_dir($from . '/' . $item)) {
                self::copyDir($from . '/' . $item, $to . '/' . $item);
            } else {
                copy($from . '/' . $item, $to . '/' . $item);
            }
        }
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) && !is_link($path) ? self::removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
