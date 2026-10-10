<?php

namespace Gojira\Tests\Support;

/**
 * UserProvider em memória, para provar que o Login funciona com uma fonte de
 * usuários que não é a tabela padrão.
 */
final class InMemoryUserProvider implements \Gojira_UserProvider
{
    /** @var array<int|string, \Gojira_GenericUser> */
    private $users = [];

    /** @var array<int, array{0: int|string, 1: string, 2: string|null}> */
    public $updates = [];

    public function add(\Gojira_GenericUser $user): void
    {
        $this->users[$user->getAuthId()] = $user;
    }

    public function findByLogin(string $login, array $types = []): ?\Gojira_Authenticatable
    {
        foreach ($this->users as $user) {
            if (($user->getAuthLogin() === $login || $user->getAuthEmail() === $login) && $this->typeMatches($user, $types)) {
                return $user;
            }
        }

        return null;
    }

    public function findById($id, array $types = []): ?\Gojira_Authenticatable
    {
        $user = $this->users[$id] ?? null;

        return $user !== null && $this->typeMatches($user, $types) ? $user : null;
    }

    public function updatePasswordHash($id, string $newHash, ?string $expectedOldHash = null): bool
    {
        $this->updates[] = [$id, $newHash, $expectedOldHash];

        return true;
    }

    private function typeMatches(\Gojira_Authenticatable $user, array $types): bool
    {
        if ($types === []) {
            return true;
        }
        foreach ($types as $type) {
            if (in_array(strtoupper((string) $type), $user->getRoles(), true) || (string) $type === (string) $user->getAuthType()) {
                return true;
            }
        }

        return false;
    }
}
