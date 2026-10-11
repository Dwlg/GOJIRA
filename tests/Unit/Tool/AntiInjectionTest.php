<?php

namespace Gojira\Tests\Unit\Tool;

use Gojira\Tests\Support\Deprecations;
use PHPUnit\Framework\TestCase;

/**
 * Tool_Security::antiInjection: deprecated, com o comportamento antigo mantido.
 *
 * Roda em processo separado porque o aviso é emitido uma vez por requisição.
 *
 * @runTestsInSeparateProcesses
 */
final class AntiInjectionTest extends TestCase
{
    use Deprecations;

    public function testEmitsOneDeprecationAndKeepsTheOldBehavior(): void
    {
        $tools = new \Tool();

        [$results, $deprecations] = $this->withDeprecations(static function () use ($tools) {
            return [
                $tools->antiInjection(' 1--2 '),
                $tools->antiInjection('<b>x</b> * y'),
                $tools->antiInjection('a -- b', true, true),
            ];
        });

        self::assertSame(['12', 'x  y', 'a -- b'], $results);
        self::assertCount(1, $deprecations);
        self::assertStringContainsString('antiInjection() is deprecated', $deprecations[0]);
    }
}
