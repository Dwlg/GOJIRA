<?php

namespace Gojira\Tests\Unit\Legacy;

use PHPUnit\Framework\TestCase;

require_once GOJIRA_SYSTEM_PATH . 'class/thumb.class.php';

/**
 * Validação de entrada do system/thumb.php (Gojira_Thumb): origem confinada
 * à raiz, URL remota opt-in, formatos aceitos e limite de dimensões.
 */
final class ThumbSourceTest extends TestCase
{
    private const FIXTURES = GOJIRA_TESTS_ROOT . 'tests/Fixtures/thumb/';

    /** @var string */
    private $root;

    /** @var string */
    private $systemDir;

    /** @var string */
    private $outside;

    /** @var string[] */
    private $deprecations = [];

    protected function setUp(): void
    {
        $base            = sys_get_temp_dir() . '/gojira-thumb-' . bin2hex(random_bytes(4));
        $this->root      = $base . '/project';
        $this->systemDir = $this->root . '/system';
        $this->outside   = $base . '/outside';

        mkdir($this->systemDir, 0777, true);
        mkdir($this->root . '/webroot/img', 0777, true);
        mkdir($this->outside, 0777, true);

        copy(self::FIXTURES . 'sample.png', $this->root . '/webroot/img/a.png');
        copy(self::FIXTURES . 'sample.png', $this->outside . '/secret.png');
        symlink($this->outside . '/secret.png', $this->root . '/webroot/img/link.png');

        $this->deprecations = [];
        set_error_handler(function (int $errno, string $errstr): bool {
            $this->deprecations[] = $errstr;

            return true;
        }, E_USER_DEPRECATED);
    }

    protected function tearDown(): void
    {
        restore_error_handler();

        $base = dirname($this->root);
        $it   = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            ($file->isDir() && !$file->isLink()) ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($base);
    }

    private function resolve(string $img, bool $allowRemote = false): string
    {
        return \Gojira_Thumb::resolveSource($img, \Gojira_Thumb::root($this->systemDir), $this->systemDir, $allowRemote);
    }

    private function assertRefused(string $img, int $status, bool $allowRemote = false): void
    {
        try {
            $this->resolve($img, $allowRemote);
            self::fail('Origem aceita indevidamente: ' . $img);
        } catch (\Gojira_Thumb_Exception $e) {
            self::assertSame($status, $e->getStatus(), $img);
        }
    }

    public function testRootIsTheFolderAboveSystem(): void
    {
        self::assertSame(realpath($this->root), \Gojira_Thumb::root($this->systemDir));
    }

    public function testRelativePathInsideRootIsAccepted(): void
    {
        self::assertSame(realpath($this->root . '/webroot/img/a.png'), $this->resolve('../webroot/img/a.png'));
    }

    public function testAbsolutePathInsideRootIsAccepted(): void
    {
        self::assertSame(realpath($this->root . '/webroot/img/a.png'), $this->resolve($this->root . '/webroot/img/a.png'));
    }

    public function testQueryStringAppendedToTheNameIsIgnored(): void
    {
        self::assertSame(realpath($this->root . '/webroot/img/a.png'), $this->resolve('../webroot/img/a.png?v=2'));
    }

    public function testRelativePathEscapingTheRootIsRefused(): void
    {
        $this->assertRefused('../../outside/secret.png', 404);
    }

    public function testAbsolutePathOutsideTheRootIsRefused(): void
    {
        $this->assertRefused($this->outside . '/secret.png', 404);
    }

    public function testSymlinkPointingOutsideTheRootIsRefused(): void
    {
        $this->assertRefused('../webroot/img/link.png', 404);
    }

    public function testSiblingFolderWithTheSamePrefixIsRefused(): void
    {
        mkdir($this->root . '-evil');
        copy(self::FIXTURES . 'sample.png', $this->root . '-evil/x.png');

        try {
            $this->assertRefused('../../project-evil/x.png', 404);
        } finally {
            unlink($this->root . '-evil/x.png');
            rmdir($this->root . '-evil');
        }
    }

    public function testMissingFileAndDirectoryAreRefused(): void
    {
        $this->assertRefused('../webroot/img/none.png', 404);
        $this->assertRefused('../webroot/img', 404);
    }

    public function testEmptyAndNullByteAreRefused(): void
    {
        $this->assertRefused('', 400);
        $this->assertRefused("../webroot/img/a.png\0.jpg", 400);
    }

    /**
     * @dataProvider streamWrappers
     */
    public function testStreamWrappersAreAlwaysRefused(string $img): void
    {
        $this->assertRefused($img, 404, true);
    }

    public function streamWrappers(): array
    {
        return [
            'php'  => ['php://filter/resource=../webroot/img/a.png'],
            'file' => ['file:///etc/hostname'],
            'phar' => ['phar://../webroot/img/a.png/x'],
            'data' => ['data:image/png;base64,AAAA'],
            'ftp'  => ['ftp://example.com/a.png'],
        ];
    }

    public function testRemoteUrlIsRefusedByDefault(): void
    {
        $this->assertRefused('https://example.com/a.png', 404);
        $this->assertRefused('http://example.com/a.png', 404);
    }

    /**
     * @runInSeparateProcess
     */
    public function testRemoteUrlEmitsDeprecationOncePerRequest(): void
    {
        $this->assertRefused('https://example.com/a.png', 404);
        $this->assertRefused('https://example.com/b.png', 404);

        self::assertCount(1, $this->deprecations);
        self::assertStringContainsString('GOJIRA_THUMB_ALLOW_REMOTE', $this->deprecations[0]);
    }

    public function testRemoteUrlIsAcceptedWhenAllowed(): void
    {
        self::assertSame('https://example.com/a.png?v=1', $this->resolve('https://example.com/a.png?v=1', true));
    }

    public function testAllowRemoteReadsTheEnvironment(): void
    {
        putenv('GOJIRA_THUMB_ALLOW_REMOTE');
        self::assertFalse(\Gojira_Thumb::allowRemote());

        putenv('GOJIRA_THUMB_ALLOW_REMOTE=1');
        try {
            self::assertTrue(\Gojira_Thumb::allowRemote());
        } finally {
            putenv('GOJIRA_THUMB_ALLOW_REMOTE');
        }
    }

    /**
     * @dataProvider acceptedImages
     */
    public function testJpegPngAndGifAreAccepted(string $file, string $mime): void
    {
        $info = \Gojira_Thumb::detectType(self::FIXTURES . $file);

        self::assertSame($mime, $info['mime']);
        self::assertSame(8, $info['width']);
        self::assertSame(6, $info['height']);
    }

    public function acceptedImages(): array
    {
        return [
            'jpeg'                   => ['sample.jpg', 'image/jpeg'],
            'png'                    => ['sample.png', 'image/png'],
            'gif'                    => ['sample.gif', 'image/gif'],
            'tipo vem do conteúdo'   => ['png-named.jpg', 'image/png'],
        ];
    }

    /**
     * @dataProvider refusedFiles
     */
    public function testOtherFormatsAreRefused(string $file): void
    {
        $this->expectException(\Gojira_Thumb_Exception::class);
        $this->expectExceptionCode(415);

        \Gojira_Thumb::detectType(self::FIXTURES . $file);
    }

    public function refusedFiles(): array
    {
        return [
            'bmp'                 => ['sample.bmp'],
            'svg'                 => ['vector.svg'],
            'texto com extensão'  => ['not-an-image.jpg'],
        ];
    }

    public function testDimensionDefaultsToZeroWhenMissing(): void
    {
        self::assertSame(0, \Gojira_Thumb::dimension(null, 4000));
        self::assertSame(0, \Gojira_Thumb::dimension('', 4000));
        self::assertSame(0, \Gojira_Thumb::dimension(0, 4000));
        self::assertSame(0, \Gojira_Thumb::dimension('0', 4000));
    }

    public function testDimensionAcceptsUpToTheLimit(): void
    {
        self::assertSame(400, \Gojira_Thumb::dimension('400', 4000));
        self::assertSame(4000, \Gojira_Thumb::dimension('4000', 4000));
    }

    /**
     * @dataProvider invalidDimensions
     *
     * @param mixed $value
     */
    public function testInvalidDimensionsAreRefused($value): void
    {
        $this->expectException(\Gojira_Thumb_Exception::class);
        $this->expectExceptionCode(400);

        \Gojira_Thumb::dimension($value, 4000);
    }

    public function invalidDimensions(): array
    {
        return [
            'acima do limite' => ['4001'],
            'negativo'        => ['-10'],
            'não numérico'    => ['abc'],
            'decimal'         => ['10.5'],
            'array'           => [['1']],
        ];
    }

    public function testMaxSizeDefaultsTo4000AndReadsTheEnvironment(): void
    {
        putenv('GOJIRA_THUMB_MAX_SIZE');
        self::assertSame(4000, \Gojira_Thumb::maxSize());

        putenv('GOJIRA_THUMB_MAX_SIZE=1200');
        try {
            self::assertSame(1200, \Gojira_Thumb::maxSize());
        } finally {
            putenv('GOJIRA_THUMB_MAX_SIZE');
        }
    }

    public function testSourceAboveThePixelLimitIsRefusedBeforeDecoding(): void
    {
        // sample.png tem 8x6 = 48 pixels.
        self::assertSame('image/png', \Gojira_Thumb::detectType(self::FIXTURES . 'sample.png', 48)['mime']);

        $this->expectException(\Gojira_Thumb_Exception::class);
        $this->expectExceptionCode(413);

        \Gojira_Thumb::detectType(self::FIXTURES . 'sample.png', 47);
    }

    public function testPixelLimitsDefaultAndReadTheEnvironment(): void
    {
        putenv('GOJIRA_THUMB_MAX_PIXELS');
        putenv('GOJIRA_THUMB_MAX_EFFECT_PIXELS');
        self::assertSame(16000000, \Gojira_Thumb::maxPixels());
        self::assertSame(4000000, \Gojira_Thumb::maxEffectPixels());

        putenv('GOJIRA_THUMB_MAX_PIXELS=500');
        putenv('GOJIRA_THUMB_MAX_EFFECT_PIXELS=abc');
        try {
            self::assertSame(500, \Gojira_Thumb::maxPixels());
            self::assertSame(4000000, \Gojira_Thumb::maxEffectPixels());
        } finally {
            putenv('GOJIRA_THUMB_MAX_PIXELS');
            putenv('GOJIRA_THUMB_MAX_EFFECT_PIXELS');
        }
    }

    public function testHexColorAcceptsRrggbb(): void
    {
        self::assertSame('', \Gojira_Thumb::hexColor(''));
        self::assertSame('', \Gojira_Thumb::hexColor(null));
        self::assertSame('ff00AA', \Gojira_Thumb::hexColor('ff00AA'));
        self::assertSame('000000', \Gojira_Thumb::hexColor('000000'));
    }

    /**
     * @dataProvider invalidColors
     *
     * @param mixed $value
     */
    public function testInvalidHexColorsAreRefused($value): void
    {
        $this->expectException(\Gojira_Thumb_Exception::class);
        $this->expectExceptionCode(400);

        \Gojira_Thumb::hexColor($value);
    }

    public function invalidColors(): array
    {
        return [
            'curta'        => ['fff'],
            'longa'        => ['ff00ff00'],
            'não hex'      => ['zzzzzz'],
            'com #'        => ['#ff0000'],
            'quebra final' => ["ff0000\n"],
            'array'        => [['ff0000']],
        ];
    }

    public function testEffectAreaIsLimited(): void
    {
        \Gojira_Thumb::checkEffectArea(100, 40, 4000);

        $this->expectException(\Gojira_Thumb_Exception::class);
        $this->expectExceptionCode(400);

        \Gojira_Thumb::checkEffectArea(100, 41, 4000);
    }
}
