<?php

namespace Gojira\Tests\Unit\Dispatch;

use PHPUnit\Framework\TestCase;

/**
 * Caracterização de routes_apply (system/routes.php).
 */
final class RoutesApplyTest extends TestCase
{
    public function testExactMatchReplacesQuery(): void
    {
        $routes = [['match' => 'contato/', 'type_in' => 'exactly', 'replace' => 'page/contact/', 'type_out' => 'exactly']];

        self::assertSame('page/contact/', routes_apply($routes, 'contato/'));
    }

    public function testExactMatchIsCaseSensitive(): void
    {
        $routes = [['match' => 'contato/', 'type_in' => 'exactly', 'replace' => 'page/contact/', 'type_out' => 'exactly']];

        self::assertSame('Contato/', routes_apply($routes, 'Contato/'));
    }

    public function testRegexInWithExactOut(): void
    {
        $routes = [['match' => '/projeto[s]?[\/]?/i', 'type_in' => 'regex', 'replace' => 'project/view/', 'type_out' => 'exactly']];

        self::assertSame('project/view/', routes_apply($routes, 'Projetos/'));
    }

    public function testRegexInAndOutUsesPregReplace(): void
    {
        $routes = [['match' => '/projeto[s]?\/([\w\-]+)[\/]?/i', 'type_in' => 'regex', 'replace' => 'project/detail/permalink/$1/', 'type_out' => 'regex']];

        self::assertSame('project/detail/permalink/casa-inteligente/', routes_apply($routes, 'projeto/casa-inteligente/'));
    }

    public function testRegexIsNotAnchoredSoItMatchesAnywhere(): void
    {
        $routes = [['match' => '/sobre/i', 'replace' => 'page/about']];

        self::assertSame('page/about', routes_apply($routes, 'blog/sobre-nos'));
    }

    public function testDefaultsAreRegexInAndExactOut(): void
    {
        $routes = [['match' => '/^home$/', 'replace' => 'home/index']];

        self::assertSame('home/index', routes_apply($routes, 'home'));
    }

    public function testOnlyFirstMatchingRouteApplies(): void
    {
        $routes = [
            ['match' => '/a/', 'replace' => 'first'],
            ['match' => '/a/', 'replace' => 'second'],
        ];

        self::assertSame('first', routes_apply($routes, 'a'));
    }

    public function testUnknownTypeOutMatchesButKeepsQuery(): void
    {
        $routes = [
            ['match' => '/a/', 'replace' => 'first', 'type_out' => 'other'],
            ['match' => '/a/', 'replace' => 'second'],
        ];

        self::assertSame('a', routes_apply($routes, 'a'));
    }

    public function testNoMatchKeepsQuery(): void
    {
        self::assertSame('home/index', routes_apply([['match' => '/^zzz$/', 'replace' => 'y']], 'home/index'));
        self::assertSame('home/index', routes_apply([], 'home/index'));
    }

    /** @dataProvider demoRoutes */
    public function testProjectDemoRoutes(string $query, string $expected): void
    {
        $routes = (static function () {
            require GOJIRA_TESTS_ROOT . 'project_demo/routes.php';
            return $routes;
        })();

        self::assertSame($expected, routes_apply($routes, $query));
    }

    public function demoRoutes(): array
    {
        return [
            'raiz'               => ['', 'home/splash'],
            'home'               => ['home/', 'home/index'],
            'projeto'            => ['projeto/casa/', 'project/detail/permalink/casa/'],
            'lista de projetos'  => ['projetos', 'project/view/'],
            'sobre'              => ['sobre', 'page/about'],
            'galeria'            => ['galeria/fotos', 'project/gallery/permalink/fotos/'],
            'contato'            => ['Contato', 'page/contact'],
            'sem rota'           => ['user/login', 'user/login'],
        ];
    }
}
