<?php

/*
   Models de teste.

   Fixture_Model expõe o construtor de consulta (protegido no Model) e permite
   injetar a estrutura da tabela sem consultar o information_schema.

   Recording_Model substitui os métodos de acesso ao banco herdados da
   Connection (count, query, execute, exist) por gravadores. Assim o SQL que o
   Model monta pode ser lido nos testes unitários sem banco.

   As classes ficam no namespace global porque o Model deriva o nome da
   tabela do nome da classe (Fixture_Model -> fixture).
*/

class Fixture_Model extends Model
{
    public function useStructure(array $structure): void
    {
        $prop = new ReflectionProperty(Model::class, 'structure');
        $prop->setAccessible(true);
        $prop->setValue($this, $structure);
    }

    public function field($name, $alias = '', $table = '') { return parent::field($name, $alias, $table); }
    public function from($table, $alias = 'a') { return parent::from($table, $alias); }
    public function where($field, $compare, $value) { return parent::where($field, $compare, $value); }
    public function wOr() { return parent::wOr(); }
    public function wAnd() { return parent::wAnd(); }
    public function order($ord, $dir = 'ASC') { return parent::order($ord, $dir); }
    public function group($field) { return parent::group($field); }
    public function having($sintax) { return parent::having($sintax); }
    public function hOr() { return parent::hOr(); }
    public function hAnd() { return parent::hAnd(); }
    public function join($type, $table, $comp, $alias = '') { return parent::join($type, $table, $comp, $alias); }
    public function limit($init, $end = 0) { return parent::limit($init, $end); }
}

class Recording_Model extends Fixture_Model
{
    /** @var string[] SQL recebido por count(), query() e execute(), em ordem */
    public $recorded = [];

    /** @var array<int, array{0:string,1:string,2:string,3:string}> argumentos de exist() */
    public $existCalls = [];

    /** Valor devolvido por count() */
    public $countResult = 0;

    /** Valor devolvido por exist() (decide entre INSERT e UPDATE no saveRow) */
    public $existResult = false;

    function count($query)
    {
        $this->recorded[] = $query;
        return $this->countResult;
    }

    function query($sql)
    {
        $this->recorded[] = $sql;
        throw new LogicException('Recording_Model não executa consultas: ' . $sql);
    }

    function execute($sql)
    {
        $this->recorded[] = $sql;
        return true;
    }

    function exist($table, $column = '', $value = '', $compare = '')
    {
        $this->existCalls[] = [$table, $column, $value, $compare];
        return $this->existResult;
    }

    public function lastRecorded(): string
    {
        if ($this->recorded === []) {
            throw new LogicException('Nenhum SQL gravado');
        }

        return end($this->recorded);
    }
}
