<?php

namespace Gojira\Tests\Support;

/** SessionGuard que registra os cookies em vez de enviá-los. */
final class RecordingGuard extends \Gojira_SessionGuard
{
    /** @var array<int, array{name: string, value: string, options: array}> */
    public $cookies = [];

    protected function sendCookie(string $name, string $value, array $options): bool
    {
        $this->cookies[] = ['name' => $name, 'value' => $value, 'options' => $options];

        return true;
    }

    /** @return array{name: string, value: string, options: array}|null */
    public function lastCookie(): ?array
    {
        return $this->cookies === [] ? null : $this->cookies[count($this->cookies) - 1];
    }
}
