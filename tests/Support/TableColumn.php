<?php

namespace Gojira\Tests\Support;

/*
   Monta objetos de coluna no mesmo formato que Model::updateTableInfo()
   produz a partir do information_schema. Os valores booleanos vêm como
   string ('1'/'0'), igual ao que o mysqli devolve.
*/
final class TableColumn
{
    public static function make(string $name, string $type, array $options = []): \stdClass
    {
        $col = new \stdClass();
        $col->name              = $name;
        $col->default_value     = $options['default'] ?? null;
        $col->default_is_null   = array_key_exists('default', $options) && $options['default'] !== null ? '0' : '1';
        $col->accept_null       = !empty($options['null']) ? '1' : '0';
        $col->field_type        = $type;
        $col->length            = $options['length'] ?? null;
        $col->decimal           = $options['decimal'] ?? '0';
        $col->is_primary_key    = !empty($options['primary']) ? '1' : '0';
        $col->is_auto_increment = !empty($options['auto_increment']) ? '1' : '0';
        $col->is_unique         = !empty($options['primary']) || !empty($options['unique']) ? '1' : '0';
        $col->is_index          = !empty($options['primary']) || !empty($options['index']) ? '1' : '0';

        return $col;
    }

    /**
     * Estrutura indexada pelo nome da coluna, como Model::$structure.
     *
     * @param \stdClass[] $columns
     * @return array<string, \stdClass>
     */
    public static function structure(array $columns): array
    {
        $structure = [];
        foreach ($columns as $col) {
            $structure[$col->name] = $col;
        }

        return $structure;
    }
}
