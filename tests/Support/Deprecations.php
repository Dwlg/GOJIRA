<?php

namespace Gojira\Tests\Support;

/*
   Captura E_USER_DEPRECATED sem deixar o aviso virar saída do teste.

   O Model emite cada deprecation uma vez por requisição; resetModelDeprecations()
   zera esse controle para que cada teste veja os avisos que provoca.
*/
trait Deprecations
{
    protected function resetModelDeprecations(): void
    {
        if (!property_exists(\Model::class, 'deprecationsSent')) {
            return;
        }
        $prop = new \ReflectionProperty(\Model::class, 'deprecationsSent');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    /**
     * Executa $fn e devolve [resultado, mensagens de E_USER_DEPRECATED].
     *
     * @return array{0: mixed, 1: string[]}
     */
    protected function withDeprecations(callable $fn): array
    {
        $messages = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$messages): bool {
            $messages[] = $errstr;
            return true;
        }, E_USER_DEPRECATED);

        try {
            $result = $fn();
        } finally {
            restore_error_handler();
        }

        return [$result, $messages];
    }
}
