<?php

namespace Gojira\Tests\Support;

/**
 * Store que simula outra requisição com o mesmo cookie: logo depois de
 * localizar o token, a "outra requisição" o consome (rotação concorrente).
 */
final class RacingRememberStore implements \Gojira_RememberTokenStore
{
    /** @var \Gojira_RememberTokenStore */
    private $inner;

    /** @var bool */
    public $race = true;

    public function __construct(\Gojira_RememberTokenStore $inner)
    {
        $this->inner = $inner;
    }

    public function issue($userId, int $ttlSeconds): array
    {
        return $this->inner->issue($userId, $ttlSeconds);
    }

    public function findBySelector(string $selector): ?\Gojira_RememberToken
    {
        $record = $this->inner->findBySelector($selector);
        if ($record !== null && $this->race) {
            $this->inner->rotate($selector, $record->getValidatorHash(), $record->getUserId(), 3600);
        }

        return $record;
    }

    public function rotate(string $selector, string $validatorHash, $userId, int $ttlSeconds): ?array
    {
        return $this->inner->rotate($selector, $validatorHash, $userId, $ttlSeconds);
    }

    public function revoke(string $selector): void
    {
        $this->inner->revoke($selector);
    }

    public function revokeAllForUser($userId): void
    {
        $this->inner->revokeAllForUser($userId);
    }

    public function purgeExpired(): void
    {
        $this->inner->purgeExpired();
    }
}
