<?php

namespace Gojira\Tests\Unit\Dispatch;

use PHPUnit\Framework\TestCase;

/**
 * GojiraCore::isSafeName: só letras ASCII, dígitos e underscore.
 */
final class IsSafeNameTest extends TestCase
{
    /** @return array<string, array{mixed}> */
    public function validNames(): array
    {
        return [
            'minúsculas'      => ['user'],
            'misto'           => ['UserProfile'],
            'underscore'      => ['user_profile'],
            'dígitos'         => ['report2024'],
            'só underscore'   => ['_'],
            'só dígito'       => ['0'],
        ];
    }

    /** @return array<string, array{mixed}> */
    public function invalidNames(): array
    {
        return [
            'vazio'             => [''],
            'diretório pai'     => ['../user'],
            'barra'             => ['admin/user'],
            'barra invertida'   => ['admin\\user'],
            'ponto'             => ['user.php'],
            'byte nulo'         => ["user\0"],
            'espaço'            => ['user name'],
            'hífen'             => ['user-name'],
            'quebra de linha'   => ["user\n"],
            'acentuado'         => ['usuário'],
            'caminho absoluto'  => ['/etc/hosts'],
            'null'              => [null],
            'inteiro'           => [10],
            'array'             => [['user']],
        ];
    }

    /**
     * @dataProvider validNames
     * @param mixed $name
     */
    public function testAcceptsValidNames($name): void
    {
        self::assertTrue(\GojiraCore::isSafeName($name));
    }

    /**
     * @dataProvider invalidNames
     * @param mixed $name
     */
    public function testRejectsInvalidNames($name): void
    {
        self::assertFalse(\GojiraCore::isSafeName($name));
    }
}
