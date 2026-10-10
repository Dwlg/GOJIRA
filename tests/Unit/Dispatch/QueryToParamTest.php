<?php

namespace Gojira\Tests\Unit\Dispatch;

use PHPUnit\Framework\TestCase;

/**
 * Caracterização de Tool_Helper::queryToParam, que converte o caminho da URL
 * em controller, método e parâmetros (/class/proc/nome/valor/...).
 */
final class QueryToParamTest extends TestCase
{
    private function parse(string $query): array
    {
        return (new \Tool())->queryToParam($query);
    }

    public function testClassOnly(): void
    {
        self::assertSame(['home', '', []], $this->parse('home'));
    }

    public function testClassAndProc(): void
    {
        self::assertSame(['home', 'index', []], $this->parse('home/index'));
    }

    public function testTrailingSlashAddsEmptyParameterName(): void
    {
        self::assertSame(['home', 'index', ['' => '']], $this->parse('home/index/'));
    }

    public function testNameValuePairs(): void
    {
        self::assertSame(
            ['project', 'detail', ['permalink' => 'casa', 'page' => '2']],
            $this->parse('project/detail/permalink/casa/page/2')
        );
    }

    public function testOddParameterCountGivesEmptyValueToLastName(): void
    {
        self::assertSame(['project', 'detail', ['permalink' => '']], $this->parse('project/detail/permalink'));
    }

    public function testRepeatedNameKeepsLastValue(): void
    {
        self::assertSame(['a', 'b', ['x' => '2']], $this->parse('a/b/x/1/x/2'));
    }

    public function testEmptyQuery(): void
    {
        self::assertSame(['', '', []], $this->parse(''));
    }

    public function testSegmentsAreNotDecodedNorValidated(): void
    {
        self::assertSame(['..', '%2e', ['a%20b' => '']], $this->parse('../%2e/a%20b'));
    }

    public function testLeadingSlashGivesEmptyClass(): void
    {
        self::assertSame(['', 'home', ['index' => '']], $this->parse('/home/index'));
    }
}
