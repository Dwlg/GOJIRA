<?php

namespace Gojira\Tests\Unit\Auth;

use Gojira\Tests\Support\FakeLogin;
use PHPUnit\Framework\TestCase;

/**
 * Gojira_MapAccessPolicy: nega por padrão, libera o que o mapa declara
 * público e exige o papel declarado em requires.
 */
final class MapAccessPolicyTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!class_exists('Policyprobe_Controller', false)) {
            eval('class Policyprobe_Controller {
                public static $access = ["public" => ["vitrine"], "requires" => ["MTR"]];
            }');
        }
    }

    private function policy(array $map, array $logged = []): \Gojira_MapAccessPolicy
    {
        $login         = new FakeLogin();
        $login->logged = $logged;

        return new \Gojira_MapAccessPolicy($map, $login);
    }

    public function testControllerWithoutRulesIsDenied(): void
    {
        $policy = $this->policy([]);

        self::assertFalse($policy->isPublic('home', 'index'));
        self::assertFalse($policy->authorize('home', 'index', null));
    }

    public function testListedMethodIsPublicAndOthersAreNot(): void
    {
        $policy = $this->policy(['home' => ['public' => ['index', 'Splash']]]);

        self::assertTrue($policy->isPublic('home', 'index'));
        self::assertTrue($policy->isPublic('HOME', 'splash'), 'controller e método sem diferença de caixa, como no PHP');
        self::assertFalse($policy->isPublic('home', 'painel'));
        self::assertFalse($policy->authorize('home', 'painel', null));
    }

    public function testWholeControllerCanBePublic(): void
    {
        self::assertTrue($this->policy(['page' => ['public' => true]])->isPublic('page', 'qualquer'));
    }

    public function testRequiredRoleMustBeLogged(): void
    {
        $map = ['admin' => ['requires' => ['ADM', 'MTR']]];

        self::assertFalse($this->policy($map)->authorize('admin', 'painel', null));
        self::assertFalse($this->policy($map, ['CLI'])->authorize('admin', 'painel', null));
        self::assertTrue($this->policy($map, ['MTR'])->authorize('admin', 'painel', null));
    }

    public function testRoleCanComeFromTheUserObject(): void
    {
        $policy = new \Gojira_MapAccessPolicy(['admin' => ['requires' => ['adm']]]);
        $user   = new \Gojira_GenericUser(1, 'ana', '', '', 1, 'ADM', '', true);
        $other  = new \Gojira_GenericUser(2, 'carlos', '', '', 2, 'CLI', '', true);

        self::assertTrue($policy->authorize('admin', 'painel', $user));
        self::assertFalse($policy->authorize('admin', 'painel', $other));
        self::assertFalse($policy->authorize('admin', 'painel', null));
    }

    public function testStaticDeclarationOverridesTheCentralMap(): void
    {
        $policy = $this->policy(['policyprobe' => ['public' => true, 'requires' => ['ADM']]], ['ADM']);

        self::assertTrue($policy->isPublic('policyprobe', 'vitrine'));
        self::assertFalse($policy->isPublic('policyprobe', 'outro'), 'a lista da classe substitui o public do mapa');
        self::assertFalse($policy->authorize('policyprobe', 'outro', null), 'a classe exige MTR, não ADM');
    }

    public function testStaticDeclarationWorksWithoutCentralMap(): void
    {
        $policy = $this->policy([], ['MTR']);

        self::assertTrue($policy->isPublic('policyprobe', 'vitrine'));
        self::assertTrue($policy->authorize('policyprobe', 'outro', null));
    }

    public function testSingleValuesAreAcceptedAsLists(): void
    {
        $policy = $this->policy(['admin' => ['public' => 'index', 'requires' => 'ADM']], ['ADM']);

        self::assertTrue($policy->isPublic('admin', 'index'));
        self::assertTrue($policy->authorize('admin', 'painel', null));
    }
}
