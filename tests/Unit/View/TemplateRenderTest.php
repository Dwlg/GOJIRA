<?php

namespace Gojira\Tests\Unit\View;

use Gojira\Tests\Support\Deprecations;
use PHPUnit\Framework\TestCase;

/**
 * Saída do template pelo Engine, com o Smarty real de system/vendor.
 *
 * - GOJIRA_ESCAPE_HTML (opt-in): variável impressa no template sai escapada.
 * - Allowlist de $globals (opt-in): o template só recebe as chaves listadas.
 * - Sem nenhuma opção, a saída e as variáveis são as de antes, e o caminho
 *   antigo avisa com E_USER_DEPRECATED uma vez por requisição.
 *
 * Cada teste roda em processo próprio: as constantes e o aviso único por
 * requisição não podem vazar de um teste para outro.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class TemplateRenderTest extends TestCase
{
    use Deprecations;

    /** Marcação inofensiva que mostra se o HTML chegou cru ou escapado. */
    private const MARKUP  = '<b class="m">marcador</b> & \'x\'';
    private const ESCAPED = '&lt;b class=&quot;m&quot;&gt;marcador&lt;/b&gt; &amp; &#039;x&#039;';

    /** @var string */
    private $root;

    protected function setUp(): void
    {
        require_once GOJIRA_SYSTEM_PATH . 'vendor/autoload.php';
        require_once GOJIRA_SYSTEM_PATH . 'class/engine.class.php';
        if (!defined('PROJECT_ID')) {
            define('PROJECT_ID', 'TEST');
        }

        $this->root = sys_get_temp_dir() . '/gojira-view-' . bin2hex(random_bytes(4));
        foreach (['controller', 'view/probe', 'compile'] as $dir) {
            mkdir($this->root . '/' . $dir, 0777, true);
        }

        file_put_contents($this->root . '/controller/probe.php', '<?php class Probe_Controller extends Controller {
            function __construct($globals) { $this->globals = $globals; }
            public function show($p) { return ["item" => ' . var_export(self::MARKUP, true) . ']; }
        }');
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    /** Monta o $globals mínimo do init.php e devolve a saída de Engine::Render. */
    private function render(string $template, array $param = []): string
    {
        file_put_contents($this->root . '/view/probe/show.html', $template);

        $smarty = new \Smarty\Smarty();
        $smarty->setTemplateDir($this->root . '/view/');
        $smarty->setCompileDir($this->root . '/compile/');
        $smarty->caching = \Smarty\Smarty::CACHING_OFF;
        \Engine::configureSmarty($smarty);

        $globals                              = new \stdClass();
        $globals->environment                 = new \stdClass();
        $globals->environment->controllerPath = $this->root . '/controller/';
        $globals->environment->viewPath       = $this->root . '/view/';
        $globals->environment->label          = 'ambiente';
        $globals->database                    = new \stdClass();
        $globals->database->password          = 'valor-interno';
        $globals->user                        = new \stdClass();
        $globals->user->name                  = 'usuario';
        $globals->cfg                         = new FakeConfig();
        $globals->smarty                      = $smarty;

        $GLOBALS['globals'] = $globals;

        return (string) \Engine::Render('probe', 'show', $param);
    }

    // ---- escape_html -----------------------------------------------------

    public function testWithoutTheOptionTemplateVariablesAreNotEscaped(): void
    {
        $this->captureDeprecations();
        $out = $this->render('[{$param.x}][{$objData.item}]', ['x' => self::MARKUP]);

        self::assertSame('[' . self::MARKUP . '][' . self::MARKUP . ']', $out, 'saída idêntica à de antes');
    }

    public function testEscapeHtmlEscapesParamAndControllerData(): void
    {
        define('GOJIRA_ESCAPE_HTML', true);
        $this->captureDeprecations();

        $out = $this->render('[{$param.x}][{$objData.item}]', ['x' => '<script>marcador</script>' . self::MARKUP]);

        self::assertSame('[&lt;script&gt;marcador&lt;/script&gt;' . self::ESCAPED . '][' . self::ESCAPED . ']', $out);
        self::assertStringNotContainsString('<', $out);
    }

    public function testEscapeHtmlKeepsNofilterAndPlainValues(): void
    {
        define('GOJIRA_ESCAPE_HTML', true);
        $this->captureDeprecations();

        $out = $this->render('[{$param.plain}][{$param.x nofilter}]', ['plain' => 'Texto simples 123', 'x' => '<b>ok</b>']);

        self::assertSame('[Texto simples 123][<b>ok</b>]', $out);
    }

    public function testEscapeHtmlFalseKeepsTheOldOutput(): void
    {
        define('GOJIRA_ESCAPE_HTML', false);
        $this->captureDeprecations();

        self::assertSame('[' . self::MARKUP . ']', $this->render('[{$param.x}]', ['x' => self::MARKUP]));
    }

    // ---- $globals no template ---------------------------------------------

    public function testLegacyModeExposesGlobalsAndEmitsOneDeprecation(): void
    {
        $this->captureDeprecations();

        $tpl = '[{$globals->user->name}][{$user->name}][{$env->label}][{$globals->database->password}]'
            . '[{if isset($database)}visivel{else}oculto{/if}]';
        $first  = $this->render($tpl);
        $second = $this->render($tpl);

        self::assertSame('[usuario][usuario][ambiente][valor-interno][oculto]', $first, 'comportamento antigo mantido');
        self::assertSame($first, $second);
        self::assertCount(1, $this->capturedDeprecations(), 'um aviso por requisição');
        $this->assertDeprecated('GOJIRA_TEMPLATE_GLOBALS');
    }

    public function testAllowlistExposesOnlyTheListedKeys(): void
    {
        $this->captureDeprecations();
        \Engine::setTemplateGlobals(['user', 'database', 'smarty']);

        $out = $this->render(
            '[{$globals->user->name}][{$user->name}][{$env->label}]'
            . '[{if isset($globals->database)}visivel{else}oculto{/if}]'
            . '[{if isset($globals->smarty)}visivel{else}oculto{/if}]'
            . '[{if isset($globals->cfg) || isset($cfg)}visivel{else}oculto{/if}]'
        );

        self::assertSame('[usuario][usuario][ambiente][oculto][oculto][oculto]', $out, 'banco e Smarty nunca entram');
        self::assertSame([], $this->capturedDeprecations(), 'com allowlist não há aviso');
    }

    public function testAllowlistFromConstantAcceptsCommaSeparatedList(): void
    {
        define('GOJIRA_TEMPLATE_GLOBALS', ' user , environment ');
        $this->captureDeprecations();

        self::assertSame(['user', 'environment'], \Engine::templateGlobals());
        self::assertSame(
            '[usuario][ambiente][oculto]',
            $this->render('[{$globals->user->name}][{$globals->environment->label}][{if isset($cfg)}visivel{else}oculto{/if}]')
        );
        self::assertSame([], $this->capturedDeprecations());
    }

    public function testEmptyAllowlistHidesEveryGlobal(): void
    {
        $this->captureDeprecations();
        \Engine::setTemplateGlobals([]);

        self::assertSame('[oculto][ambiente]', $this->render('[{if isset($globals->user) || isset($user)}visivel{else}oculto{/if}][{$env->label}]'));

        \Engine::setTemplateGlobals(null);
        self::assertNull(\Engine::templateGlobals(), 'null volta ao modo antigo (sem a constante)');
    }

    public function testJsonResponseIsUnchangedAndDoesNotWarn(): void
    {
        $this->captureDeprecations();

        $smarty = new \Smarty\Smarty();
        $globals                              = new \stdClass();
        $globals->environment                 = new \stdClass();
        $globals->environment->controllerPath = $this->root . '/controller/';
        $globals->environment->viewPath       = $this->root . '/view/';
        $globals->cfg                         = new FakeConfig();
        $globals->smarty                      = $smarty;
        $GLOBALS['globals'] = $globals;

        self::assertSame(json_encode(['item' => self::MARKUP]), \Engine::Render('probe', 'show', []));
        self::assertSame([], $this->capturedDeprecations(), 'sem template, sem aviso');
    }
}

/** Config mínima: o Engine só lê TEMPLATE_EXTENSION. */
final class FakeConfig
{
    public function getConfig($section, $key, $default = null)
    {
        return $default;
    }
}
