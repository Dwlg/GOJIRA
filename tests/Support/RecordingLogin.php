<?php

namespace Gojira\Tests\Support;

/** Login que registra os cookies de identidade em vez de enviá-los. */
final class RecordingLogin extends \Login
{
    /** @var array<int, array{name: string, value: string, options: array}> */
    public $cookies = [];

    /** @var bool simula saída já enviada ao navegador */
    public $forceHeadersSent = false;

    protected function headersAlreadySent(&$file = '', &$line = 0): bool
    {
        if ($this->forceHeadersSent) {
            $file = 'saida.php';
            $line = 7;

            return true;
        }

        return parent::headersAlreadySent($file, $line);
    }

    protected function sendCookie(string $name, string $value, array $options): bool
    {
        $this->cookies[] = ['name' => $name, 'value' => $value, 'options' => $options];

        return true;
    }

    /** @return array<string, array{name: string, value: string, options: array}> último cookie por nome */
    public function cookiesByName(): array
    {
        $byName = [];
        foreach ($this->cookies as $cookie) {
            $byName[$cookie['name']] = $cookie;
        }

        return $byName;
    }
}
