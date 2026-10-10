<?php

namespace Gojira\Tests\Support;

/** Login sem banco: os tipos logados são definidos pelo teste. */
final class FakeLogin extends \Login
{
    /** @var string[] */
    public $logged = [];

    public function __construct()
    {
    }

    public function isLogged($type)
    {
        return in_array(strtoupper((string) $type), $this->logged, true);
    }
}
