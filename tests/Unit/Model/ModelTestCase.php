<?php

namespace Gojira\Tests\Unit\Model;

use Gojira\Tests\Support\Deprecations;
use Gojira\Tests\Support\TableColumn;
use PHPUnit\Framework\TestCase;

/*
   Base dos testes de caracterização do Model sem banco.
   A estrutura padrão é uma tabela com chave primária auto_increment.
*/
abstract class ModelTestCase extends TestCase
{
    use Deprecations;

    protected function setUp(): void
    {
        $this->resetModelDeprecations();
    }

    protected function globals(): \stdClass
    {
        $globals                 = new \stdClass();
        $globals->tools          = new \Tool();
        $globals->database       = new \stdClass();
        $globals->database->name = 'gojira_test';

        return $globals;
    }

    /** id int PK auto_increment, name varchar NOT NULL, status int NULL, created datetime NULL, birth date NULL */
    protected function defaultStructure(): array
    {
        return TableColumn::structure([
            TableColumn::make('id', 'int', ['primary' => true, 'auto_increment' => true]),
            TableColumn::make('name', 'varchar', ['length' => '100']),
            TableColumn::make('status', 'int', ['null' => true]),
            TableColumn::make('created', 'datetime', ['null' => true]),
            TableColumn::make('birth', 'date', ['null' => true]),
        ]);
    }

    protected function model(?array $structure = null): \Recording_Model
    {
        $model = new \Recording_Model($this->globals());
        $model->useStructure($structure ?? $this->defaultStructure());

        return $model;
    }
}
