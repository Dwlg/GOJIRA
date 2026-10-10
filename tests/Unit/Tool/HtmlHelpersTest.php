<?php

namespace Gojira\Tests\Unit\Tool;

use PHPUnit\Framework\TestCase;

/**
 * Helpers de HTML (Tool_Html e Tool_Multimedia): valores simples saem iguais
 * aos de antes (regressão) e valores com aspas ou sinais de tag não saem do
 * atributo nem do texto em que foram postos. A marcação usada é inofensiva.
 */
final class HtmlHelpersTest extends TestCase
{
    private const MARKUP = '"><b>marcador</b>';

    /** @var array<string, mixed> */
    private $get;

    public static function setUpBeforeClass(): void
    {
        require_once GOJIRA_SYSTEM_PATH . 'class/tool/html.class.php';
        require_once GOJIRA_SYSTEM_PATH . 'class/tool/multimedia.class.php';
        if (!defined('ICON_SORT_ASC')) {
            define('ICON_SORT_ASC', '<i class="up"></i>');
            define('ICON_SORT_DESC', '<i class="down"></i>');
        }
    }

    protected function setUp(): void
    {
        $this->get = $_GET;
        $_GET      = [];
    }

    protected function tearDown(): void
    {
        $_GET = $this->get;
    }

    private function html(): \Tool_Html
    {
        return new \Tool_Html();
    }

    private function output(callable $fn): string
    {
        ob_start();
        try {
            $fn();
        } finally {
            $out = (string) ob_get_clean();
        }
        return $out;
    }

    /** Nenhum atributo do HTML gerado contém sinal de tag cru. */
    private static function assertNoRawMarkup(string $html): void
    {
        self::assertStringNotContainsString('<b>', $html);
        self::assertStringNotContainsString('"><b', $html);
    }

    // ---- escapeAttr / escapeUrl -------------------------------------------

    public function testEscapeAttr(): void
    {
        self::assertSame('Texto simples 123', \Tool_Html::escapeAttr('Texto simples 123'));
        self::assertSame('&quot;&gt;&lt;b&gt;marcador&lt;/b&gt;', \Tool_Html::escapeAttr(self::MARKUP));
        self::assertSame('a &amp; b &amp; c', \Tool_Html::escapeAttr('a & b &amp; c'), 'entidade existente não é escapada de novo');
        self::assertSame('&#039;', \Tool_Html::escapeAttr("'"));
        self::assertSame('560', \Tool_Html::escapeAttr(560));
        self::assertSame('', \Tool_Html::escapeAttr(['x']));
    }

    public function testEscapeUrlKeepsQueryStringSeparators(): void
    {
        self::assertSame('lista/?id=1&view=2&pag=', \Tool_Html::escapeUrl('lista/?id=1&view=2&pag='));
        self::assertSame('x&quot;&gt;&lt;b&gt;&#039;', \Tool_Html::escapeUrl('x"><b>\''));
    }

    // ---- pagination --------------------------------------------------------

    public function testPaginationWithSimpleGetValuesIsUnchanged(): void
    {
        $_GET = ['id' => '7', 'view' => 'lista', 'type' => 'a', 'sort' => 'name', 'inv' => '1'];

        $expected = '<div class="pagination"><ul>'
            . '<li><strong>1</strong></li>'
            . '<li><span><a href="lista/?id=7&view=lista&type=a&sort=name&inv=1&pag=2">2</a></span></li>'
            . '</ul></div>';

        self::assertSame($expected, $this->html()->pagination(20, 10, 'lista', 1, false));
    }

    public function testPaginationAjaxLinkIsUnchanged(): void
    {
        $expected = '<div class="pagination"><ul>'
            . '<li><span><a href="home/lista/pag/1">1</a></span></li>'
            . '<li><strong>2</strong></li>'
            . '</ul></div>';

        self::assertSame($expected, $this->html()->pagination(20, 10, 'home/lista/pag/3/', 2, true));
    }

    public function testPaginationUrlencodesGetValues(): void
    {
        $_GET = ['id' => self::MARKUP, 'sort' => 'a b&c=d'];

        $out = $this->html()->pagination(20, 10, 'lista', 1, false);

        self::assertStringContainsString('href="lista/?id=' . urlencode(self::MARKUP) . '&sort=a+b%26c%3Dd&pag=2"', $out);
        self::assertNoRawMarkup($out);
    }

    public function testPaginationIgnoresNonScalarGetValues(): void
    {
        $_GET = ['id' => ['1', '2'], 'view' => 'v'];

        self::assertStringContainsString('href="lista/?view=v&pag=2"', $this->html()->pagination(20, 10, 'lista', 1, false));
    }

    public function testPaginationEscapesTheBaseUrl(): void
    {
        self::assertNoRawMarkup($this->html()->pagination(20, 10, 'lista' . self::MARKUP, 1, true));
    }

    // ---- getSeo --------------------------------------------------------------

    public function testGetSeoWithSimpleValuesIsUnchanged(): void
    {
        $out = $this->output(function () {
            $this->html()->getSeo('Titulo', 'Descricao [tag] da pagina', 'a, b');
        });

        $expected = "<title>Titulo</title>\n"
            . "<meta name=\"description\" content=\"Descricao  da pagina\">\n"
            . "<meta name=\"keywords\" content=\"a, b\">\n"
            . "<meta name=\"copyright\" content=\"Agência Freela\">\n"
            . "<meta name=\"googlebot\" content=\"all, follow\">\n"
            . "<meta name=\"robots\" content=\"all, follow\">\n";

        self::assertSame($expected, $out);
    }

    public function testGetSeoEscapesTitleAndAttributes(): void
    {
        $out = $this->output(function () {
            $this->html()->getSeo('</title>' . self::MARKUP, 'd"x', self::MARKUP, self::MARKUP, self::MARKUP, 'https://ex.test/' . self::MARKUP);
        });

        self::assertStringContainsString('<title>&lt;/title&gt;&quot;&gt;&lt;b&gt;marcador&lt;/b&gt;</title>', $out);
        self::assertStringContainsString('content="d&quot;x"', $out);
        self::assertSame(1, substr_count($out, '</title>'));
        self::assertNoRawMarkup($out);
    }

    public function testGetSeoPrintsTheCanonicalValue(): void
    {
        $out = $this->output(function () {
            $this->html()->getSeo('T', 'D', 'K', 'all', 'all', 'https://ex.test/pagina?a=1&b=2');
        });

        self::assertStringContainsString('<meta name="canonical" content="https://ex.test/pagina?a=1&b=2">', $out);
    }

    // ---- columnHeader / load ---------------------------------------------------

    public function testColumnHeaderWithSimpleValuesIsUnchanged(): void
    {
        $out = $this->output(function () {
            $this->html()->columnHeader('lista/', 'name', false, 'name', 'Nome');
            $this->html()->columnHeader('lista/', 'name', true, 'email', 'E-mail');
            $this->html()->columnHeader('lista/', '', false, 'name', 'Nome');
        });

        self::assertSame(
            '<th><a href="lista/sort/name/inv/1/"><i class="down"></i>Nome</a></th>'
            . '<th><a href="lista/sort/email/">E-mail</a></th>'
            . '<th>Nome</th>',
            $out
        );
    }

    public function testColumnHeaderEscapesUrlAndField(): void
    {
        $out = $this->output(function () {
            $this->html()->columnHeader('lista/' . self::MARKUP, 'x', false, self::MARKUP, 'Nome');
        });

        self::assertNoRawMarkup($out);
    }

    public function testLoadWithSimpleValuesIsUnchangedAndEscapesTheFile(): void
    {
        $out = $this->output(function () {
            $this->html()->load('js', 'app', false);
            $this->html()->load('css', 'https://cdn.test/a.css?v=1&x=2', false, true);
            $this->html()->load('js', 'app' . self::MARKUP, false);
        });

        $lines = explode("\n", trim($out));
        self::assertSame('<script type="text/javascript" src="js/app.js"></script>', $lines[0]);
        self::assertSame('<link rel="stylesheet" type="text/css" href="https://cdn.test/a.css?v=1&x=2" media="all" />', $lines[1]);
        self::assertNoRawMarkup($lines[2]);
    }

    // ---- embedVideo / issu_get_embed ---------------------------------------------

    public function testEmbedVideoWithSimpleValuesIsUnchanged(): void
    {
        $media = new \Tool_Multimedia();

        self::assertSame(
            '<iframe src="https://www.youtube.com/embed/aBc-123_x?rel=0&wmode=transparent&autoplay=0" width="560" height="315" frameborder="0" allowfullscreen></iframe>',
            $media->embedVideo('aBc-123_x', 560, 315)
        );
        self::assertSame(
            '<iframe src="https://player.vimeo.com/video/12345678?title=0&amp;byline=0&amp;portrait=0&autoplay=1" width="100%" height="400" frameborder="0"></iframe>',
            $media->embedVideo('12345678', '100%', '400', 'vimeo', true)
        );
        self::assertSame(
            'https://www.youtube.com/embed/aBc-123_x?rel=0&wmode=transparent&autoplay=0',
            $media->embedVideo('aBc-123_x', 0, 0, 'youtube', false, true)
        );
    }

    public function testEmbedVideoEscapesAttributes(): void
    {
        $media = new \Tool_Multimedia();

        self::assertNoRawMarkup($media->embedVideo(self::MARKUP, self::MARKUP, self::MARKUP));
        self::assertNoRawMarkup($media->embedVideo(self::MARKUP, 1, 1, 'vimeo'));
        self::assertNoRawMarkup($media->embedVideo(self::MARKUP, 1, 1, 'vimeo', false, true));
    }

    public function testIssuEmbedEscapesAttributes(): void
    {
        $media = new \Tool_Multimedia();

        self::assertStringContainsString('documentId=doc-1" />', $media->issu_get_embed('doc-1', 400, 300));
        self::assertStringContainsString('style="width: 400px;height: 300px"', $media->issu_get_embed('doc-1', 400, 300));
        self::assertNoRawMarkup($media->issu_get_embed(self::MARKUP, self::MARKUP, 1));
    }
}
