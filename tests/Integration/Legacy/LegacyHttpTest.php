<?php

namespace Gojira\Tests\Integration\Legacy;

use PHPUnit\Framework\TestCase;

/**
 * system/thumb.php e system/console.php atendidos por HTTP (servidor embutido do PHP,
 * com a raiz do repositório como document root).
 */
final class LegacyHttpTest extends TestCase
{
    private const MAX = 64;

    /** @var resource|null */
    private static $server;

    /** @var string */
    private static $baseUrl;

    /** @var string */
    private static $outsideFile;

    public static function setUpBeforeClass(): void
    {
        self::$outsideFile = sys_get_temp_dir() . '/gojira-outside-' . bin2hex(random_bytes(4)) . '.png';
        copy(GOJIRA_TESTS_ROOT . 'tests/Fixtures/thumb/sample.png', self::$outsideFile);

        for ($attempt = 0; $attempt < 5 && self::$server === null; $attempt++) {
            $port = random_int(20000, 45000);
            $env  = getenv();
            unset($env['GOJIRA_THUMB_ALLOW_REMOTE'], $env['GOJIRA_THUMB_ROOT'], $env['GOJIRA_ENABLE_CONSOLE']);
            $env['GOJIRA_ENABLE_CONSOLE'] = '1'; // mesmo ligado, fora de CLI o console responde 404
            $env['GOJIRA_THUMB_MAX_SIZE'] = (string) self::MAX; // limite baixo para o teste não gerar imagens enormes
            $env['GOJIRA_THUMB_MAX_PIXELS']        = '900';   // square40.png (1600 px) passa do teto; wide.png (500 px) não
            $env['GOJIRA_THUMB_MAX_EFFECT_PIXELS'] = '1000';  // c/hue até ~36x27

            $proc = proc_open(
                [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', GOJIRA_TESTS_ROOT],
                [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes,
                GOJIRA_TESTS_ROOT,
                $env
            );

            for ($i = 0; $i < 50; $i++) {
                $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
                if ($socket !== false) {
                    fclose($socket);
                    self::$server  = $proc;
                    self::$baseUrl = 'http://127.0.0.1:' . $port . '/';
                    break;
                }
                usleep(100000);
            }

            if (self::$server === null) {
                proc_terminate($proc);
                proc_close($proc);
            }
        }

        if (self::$server === null) {
            self::fail('Não foi possível iniciar o servidor embutido do PHP.');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
        @unlink(self::$outsideFile);
    }

    /** @return array{0:int,1:string,2:string} status, content-type, corpo */
    private function get(string $path): array
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
        $body    = file_get_contents(self::$baseUrl . $path, false, $context);

        $status = 0;
        $type   = '';
        foreach ($http_response_header as $header) {
            if (preg_match('#^HTTP/\S+ (\d{3})#', $header, $m)) {
                $status = (int) $m[1];
            } elseif (stripos($header, 'Content-Type:') === 0) {
                $type = trim(substr($header, 13));
            }
        }

        return [$status, $type, (string) $body];
    }

    private function thumb(string $img, string $extra = ''): array
    {
        return $this->get('system/thumb.php?img=' . rawurlencode($img) . $extra);
    }

    private function requireGd(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('Extensão GD indisponível neste PHP.');
        }
    }

    public function testConsoleRespondsNotFoundOverHttp(): void
    {
        [$status, , $body] = $this->get('system/console.php');
        self::assertSame(404, $status);
        self::assertSame('', $body);

        $context = stream_context_create(['http' => [
            'ignore_errors' => true,
            'method'        => 'POST',
            'header'        => 'Content-Type: application/x-www-form-urlencoded',
            'content'       => 'command=define+a+b',
        ]]);
        $body = file_get_contents(self::$baseUrl . 'system/console.php', false, $context);
        self::assertStringContainsString(' 404 ', $http_response_header[0]);
        self::assertSame('', $body);
    }

    public function testImageInsideTheProjectIsServed(): void
    {
        $this->requireGd();

        [$status, $type, $body] = $this->thumb('../tests/Fixtures/thumb/sample.png', '&t=resize&m=4');

        self::assertSame(200, $status);
        self::assertSame('image/png', $type);
        $size = getimagesizefromstring($body);
        self::assertSame([4, 3], [$size[0], $size[1]]);
    }

    public function testJpegCropInsideTheProjectIsServed(): void
    {
        $this->requireGd();

        [$status, $type, $body] = $this->thumb('../tests/Fixtures/thumb/sample.jpg', '&t=crop&w=4&h=4');

        self::assertSame(200, $status);
        self::assertSame('image/jpeg', $type);
        $size = getimagesizefromstring($body);
        self::assertSame([4, 4], [$size[0], $size[1]]);
    }

    public function testResizeOutputNeverExceedsTheLimit(): void
    {
        $this->requireGd();

        // 8x6 com h=64 daria 85x64; o lado maior é limitado a MAX mantendo a proporção.
        [$status, $type, $body] = $this->thumb('../tests/Fixtures/thumb/sample.gif', '&t=resize&h=' . self::MAX);

        self::assertSame(200, $status);
        self::assertSame('image/gif', $type);
        $size = getimagesizefromstring($body);
        self::assertSame([64, 48], [$size[0], $size[1]]);
    }

    /**
     * @dataProvider refusedSources
     */
    public function testSourcesOutsideTheProjectAreRefused(string $img, int $expected): void
    {
        $img = str_replace('{outside}', self::$outsideFile, $img);

        [$status, $type, $body] = $this->thumb($img);

        self::assertSame($expected, $status, $img);
        self::assertStringStartsWith('text/plain', $type);
        self::assertStringNotContainsString('PNG', $body);
        self::assertStringNotContainsString('root:', $body);
    }

    public function refusedSources(): array
    {
        return [
            'caminho absoluto fora'   => ['{outside}', 404],
            'arquivo do sistema'      => ['/etc/passwd', 404],
            'subida de diretório'     => ['../../../../../../etc/passwd', 404],
            'wrapper php://'          => ['php://filter/resource=thumb.php', 404],
            'wrapper file://'         => ['file:///etc/passwd', 404],
            'URL remota sem opção'    => ['http://127.0.0.1/', 404],
            'arquivo PHP do projeto'  => ['thumb.php', 415],
            'texto com extensão jpg'  => ['../tests/Fixtures/thumb/not-an-image.jpg', 415],
            'BMP'                     => ['../tests/Fixtures/thumb/sample.bmp', 415],
            'SVG'                     => ['../tests/Fixtures/thumb/vector.svg', 415],
            'sem imagem'              => ['', 400],
        ];
    }

    /**
     * @dataProvider invalidSizes
     */
    public function testDimensionsAboveTheLimitAreRefused(string $query): void
    {
        [$status, $type] = $this->thumb('../tests/Fixtures/thumb/sample.png', $query);

        self::assertSame(400, $status, $query);
        self::assertStringStartsWith('text/plain', $type);
    }

    public function invalidSizes(): array
    {
        return [
            'm acima'        => ['&t=resize&m=65'],
            'w acima'        => ['&t=crop&w=65&h=10'],
            'h acima'        => ['&t=crop&w=10&h=99999'],
            'negativo'       => ['&t=resize&m=-1'],
            'resize sem m'   => ['&t=resize'],
            'crop sem h'     => ['&t=crop&w=10'],
        ];
    }

    public function testSourceAbovePixelLimitIsRefused(): void
    {
        [$status, $type] = $this->thumb('../tests/Fixtures/thumb/square40.png', '&t=resize&m=10');

        self::assertSame(413, $status);
        self::assertStringStartsWith('text/plain', $type);
    }

    public function testPathWithoutTypeRefusesSourceAboveMaxSize(): void
    {
        // wide.png tem 100x5: dentro do teto de pixels, mas com um lado acima de MAX.
        [$status] = $this->thumb('../tests/Fixtures/thumb/wide.png');
        self::assertSame(400, $status);

        $this->requireGd();
        [$status] = $this->thumb('../tests/Fixtures/thumb/wide.png', '&t=resize&m=50');
        self::assertSame(200, $status);
    }

    /**
     * @dataProvider invalidColorQueries
     */
    public function testInvalidColorsAreRefused(string $query): void
    {
        [$status] = $this->thumb('../tests/Fixtures/thumb/sample.png', $query);

        self::assertSame(400, $status, $query);
    }

    public function invalidColorQueries(): array
    {
        return [
            'c não hex'   => ['&c=zzzzzz'],
            'c curta'     => ['&c=fff'],
            'hue longa'   => ['&hue=1234567'],
            'hue com #'   => ['&hue=%23ff0000'],
        ];
    }

    public function testColorEffectsAboveTheAreaLimitAreRefused(): void
    {
        $this->requireGd();

        // 64x48 = 3072 pixels, acima do teto de 1000.
        [$status] = $this->thumb('../tests/Fixtures/thumb/sample.png', '&t=resize&m=64&c=ff0000');
        self::assertSame(400, $status);

        [$status] = $this->thumb('../tests/Fixtures/thumb/sample.png', '&t=resize&m=64&hue=00ff00');
        self::assertSame(400, $status);

        // 16x12 = 192 pixels.
        [$status, $type] = $this->thumb('../tests/Fixtures/thumb/sample.png', '&t=resize&m=16&c=ff0000');
        self::assertSame(200, $status);
        self::assertSame('image/png', $type);
    }

    public function testBlackHueDoesNotDivideByZero(): void
    {
        $this->requireGd();

        [$status, $type, $body] = $this->thumb('../tests/Fixtures/thumb/sample.png', '&hue=000000');

        self::assertSame(200, $status);
        self::assertSame('image/png', $type);
        self::assertNotFalse(getimagesizefromstring($body));
    }
}
