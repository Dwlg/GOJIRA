<?php

namespace Gojira\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;

/**
 * Gojira_BcryptHasher e Gojira_LegacyHasher, sem banco.
 */
final class PasswordHasherTest extends TestCase
{
    private function legacyHashOf(string $plain): string
    {
        $val = $plain;
        for ($c = 0; $c < 3; $c++) {
            $val = hash('sha512', $val, false);
            $val = hash('whirlpool', $val, false);
        }

        return $val;
    }

    public function testLegacyHashMatchesLoginToPassword(): void
    {
        $legacy = new \Gojira_LegacyHasher();
        $login  = (new \ReflectionClass(\Login::class))->newInstanceWithoutConstructor();

        self::assertSame($login->toPassword('segredo'), $legacy->hash('segredo'));
        self::assertSame($this->legacyHashOf('segredo'), $legacy->hash('segredo'));
        self::assertTrue($legacy->looksLegacy($legacy->hash('segredo')));
    }

    public function testLegacyVerify(): void
    {
        $legacy = new \Gojira_LegacyHasher();
        $hash   = $legacy->hash('segredo');

        self::assertTrue($legacy->verify('segredo', $hash));
        self::assertFalse($legacy->verify('outra', $hash));
        self::assertFalse($legacy->verify($hash, $hash), 'o próprio hash não vale como senha');
    }

    public function testLegacyHasherDoesNotRehashItsOwnFormat(): void
    {
        $legacy = new \Gojira_LegacyHasher();

        self::assertFalse($legacy->needsRehash($legacy->hash('segredo')));
        self::assertTrue($legacy->needsRehash(password_hash('segredo', PASSWORD_DEFAULT)));
    }

    public function testBcryptHashIsModernAndVerifies(): void
    {
        $hasher = new \Gojira_BcryptHasher();
        $hash   = $hasher->hash('segredo');

        self::assertNotEmpty(password_get_info($hash)['algo']);
        self::assertNotSame($hash, $hasher->hash('segredo'), 'cada hash usa um sal novo');
        self::assertTrue($hasher->verify('segredo', $hash));
        self::assertFalse($hasher->verify('outra', $hash));
        self::assertFalse($hasher->needsRehash($hash));
    }

    public function testBcryptAcceptsLegacyHashOnlyWithLegacyFallback(): void
    {
        $legacyHash = $this->legacyHashOf('segredo');

        $withFallback    = new \Gojira_BcryptHasher(new \Gojira_LegacyHasher());
        $withoutFallback = new \Gojira_BcryptHasher();

        self::assertTrue($withFallback->verify('segredo', $legacyHash));
        self::assertFalse($withFallback->verify('outra', $legacyHash));
        self::assertFalse($withFallback->verify($legacyHash, $legacyHash));
        self::assertFalse($withoutFallback->verify('segredo', $legacyHash));
    }

    public function testBcryptAsksToRehashLegacyAndOutdatedHashes(): void
    {
        $hasher = new \Gojira_BcryptHasher(new \Gojira_LegacyHasher(), PASSWORD_BCRYPT, ['cost' => 11]);

        self::assertTrue($hasher->needsRehash($this->legacyHashOf('segredo')));
        self::assertTrue($hasher->needsRehash(password_hash('segredo', PASSWORD_BCRYPT, ['cost' => 10])));
        self::assertFalse($hasher->needsRehash(password_hash('segredo', PASSWORD_BCRYPT, ['cost' => 11])));
    }

    public function testBcryptCostIsFixedAtTwelveByDefault(): void
    {
        $hash = (new \Gojira_BcryptHasher())->hash('segredo');

        self::assertSame(12, \Gojira_BcryptHasher::DEFAULT_COST);
        self::assertSame(12, password_get_info($hash)['options']['cost']);
    }

    public function testBcryptCostIsConfigurable(): void
    {
        $hasher = new \Gojira_BcryptHasher(null, PASSWORD_BCRYPT, ['cost' => 5]);
        $hash   = $hasher->hash('segredo');

        self::assertSame(5, password_get_info($hash)['options']['cost']);
        self::assertFalse($hasher->needsRehash($hash));
        self::assertTrue((new \Gojira_BcryptHasher())->needsRehash($hash), 'custo abaixo do padrão pede rehash');
    }

    public function testBcryptRejectsEmptyOrGarbageHash(): void
    {
        $hasher = new \Gojira_BcryptHasher(new \Gojira_LegacyHasher());

        self::assertFalse($hasher->verify('', ''));
        self::assertFalse($hasher->verify('segredo', 'nao-e-hash'));
    }
}
