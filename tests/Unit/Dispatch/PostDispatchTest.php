<?php

namespace Gojira\Tests\Unit\Dispatch;

use PHPUnit\Framework\TestCase;

/**
 * Despacho legado por $_POST['class'] (Controller::postDispatch, usado pelo
 * init.php): continua funcionando, emite um E_USER_DEPRECATED por requisição e
 * é ignorado com GOJIRA_DISABLE_POST_DISPATCH.
 *
 * Cada teste roda em processo próprio: o aviso é emitido uma vez por processo
 * (requisição) e a constante não pode ser desfeita depois de definida.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class PostDispatchTest extends TestCase
{
    /** @var list<string> */
    private $deprecations = [];

    protected function setUp(): void
    {
        $this->deprecations = [];
        set_error_handler(function (int $errno, string $errstr): bool {
            $this->deprecations[] = $errstr;

            return true;
        }, E_USER_DEPRECATED);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
    }

    public function testPostWithClassIsDispatchedAndDeprecated(): void
    {
        self::assertSame(['home', 'index'], \Controller::postDispatch(['class' => 'home', 'proc' => 'index']));
        self::assertCount(1, $this->deprecations);
        self::assertStringContainsString('GOJIRA_DISABLE_POST_DISPATCH', $this->deprecations[0]);
    }

    public function testProcIsOptional(): void
    {
        self::assertSame(['home', ''], \Controller::postDispatch(['class' => 'home']));
    }

    public function testDeprecationIsEmittedOncePerRequest(): void
    {
        \Controller::postDispatch(['class' => 'home', 'proc' => 'index']);
        \Controller::postDispatch(['class' => 'home', 'proc' => 'outro']);

        self::assertCount(1, $this->deprecations);
    }

    public function testPostWithoutClassIsNotADispatch(): void
    {
        self::assertNull(\Controller::postDispatch(['proc' => 'index', 'nome' => 'x']));
        self::assertNull(\Controller::postDispatch([]));
        self::assertSame([], $this->deprecations);
    }

    public function testDisabledPostDispatchIsIgnored(): void
    {
        self::assertNull(\Controller::postDispatch(['class' => 'home', 'proc' => 'index'], true));
        self::assertSame([], $this->deprecations);
    }

    public function testConstantDisablesPostDispatch(): void
    {
        define('GOJIRA_DISABLE_POST_DISPATCH', true);

        self::assertNull(\Controller::postDispatch(['class' => 'home', 'proc' => 'index']));
        self::assertSame([], $this->deprecations);
    }

    public function testConstantSetToFalseKeepsPostDispatch(): void
    {
        define('GOJIRA_DISABLE_POST_DISPATCH', false);

        self::assertSame(['home', 'index'], \Controller::postDispatch(['class' => 'home', 'proc' => 'index']));
        self::assertCount(1, $this->deprecations);
    }
}
