<?php

namespace Gojira\Tests\Support;

/** Hasher que conta as chamadas e delega ao hasher padrão do Login. */
final class CountingHasher implements \Gojira_PasswordHasher
{
    /** @var array<string, int> */
    public $calls = ['hash' => 0, 'verify' => 0, 'needsRehash' => 0];

    /** @var \Gojira_PasswordHasher */
    private $inner;

    public function __construct()
    {
        $this->inner = new \Gojira_BcryptHasher(new \Gojira_LegacyHasher(), PASSWORD_BCRYPT, ['cost' => 4]);
    }

    public function hash(string $plain): string
    {
        $this->calls['hash']++;

        return $this->inner->hash($plain);
    }

    public function verify(string $plain, string $hash): bool
    {
        $this->calls['verify']++;

        return $this->inner->verify($plain, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        $this->calls['needsRehash']++;

        return $this->inner->needsRehash($hash);
    }
}
