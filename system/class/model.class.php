<?php
//TODO - SaveData (salva multiplos Rows)
//TODO - Integrar com o schema (gerente de db)

abstract class Model extends Connection{
	
	//Propriedades da classe
	protected $globals;			//Valores globais do sistema
	
	private   $table;				//Tabela base do modelo
	private   $structure;		//Estrutura da tabela
	private   $sql = [];       //SQL que deve ser usado na proxima busca
	
	private $mysqlFuncRegex = '';
	private $mysqlLiteralRegex = '/^\b(NULL|CURRENT_TIMESTAMP)\b$/ui';      //Palavras reservadas ou nomes de váriaveis que não devem ser escapados
	private $mysqlFunctions = ['CURRENT_TIMESTAMP','CURDATE','NOW','CURTIME','ADDDATE','ADDTIME','WEEKDAY','WEEKOFYEAR','YEAR','MONTH','DAY','HOUR','MINUTE','SECOND','SEC_TO_TIME','TIME_TO_SEC','STR_TO_DATE','DATEDIFF','TIMEDIFF','DATE_SUB','DAYNAME','DAYOFMONTH', 'DAYOFWEEK','DAYOFYEAR','FROM_DAYS','LAST_DAY', 'MICROSECOND', 'DATE_FORMAT',
	'UPPER', 'LOWER', 'TRIM', 'SUBSTR', 'STRCMP', 'SPACE', 'SOUNDS_LIKE', 'SOUNDEX', 'RTRIM', 'RPAD', 'RIGHT', 'LTRIM', 'LPAD', 'LEFT', 'REVERSE', 'REPLACE', 'REGEX', 'QUOTE', 'MID', 'MATCH',
	'MIN','MAX','AVG','COUNT','GROUP_CONCAT', 'SUM',
	'DEFAULT', 'RAND', 'UUID', 'ANY_VALUE', 'FORMAT',
	'COMPRESS', 'DECODE', 'ENCODE', 'MD5', 'SHA1', 'SHA2', 'UNCOMPRESS', 'VALIDATE_PASSWORD_STRENGTH',
	'ABS', 'ACOS', 'ASIN', 'ATAN', 'CEIL', 'CEILING', 'CONV', 'COS', 'COT', 'DEGREES', 'EXP', 'FLOOR', 'LN', 'LOG', 'LOG10', 'LOG2', 'MOD', 'PI', 'POW', 'POWER', 'RADIANS', 'ROUND', 'SIGN', 'SIN', 'SQRT', 'TAN', 'TRUNCATE'];

	//Funções sem argumento que o modo estrito ainda aceita como valor em saveRow
	private static $strictValueFunctions = '/\A(NOW|CURDATE|CURTIME|CURRENT_TIMESTAMP|UUID)\(\s*\)\z/i';

	//Operadores aceitos por where() no modo estrito
	private static $whereOperators = ['=', '<>', '!=', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'IS', 'IS NOT'];

	//Tipos de join aceitos no modo estrito
	private static $joinTypes = ['INNER', 'LEFT', 'RIGHT', 'CROSS', 'LEFT OUTER', 'RIGHT OUTER'];

	
	//Construtores do objeto
	function __construct1( $globals ){
		
		$reserved      = ['Model','AppModel','ComponentModel'];
		$class         = get_class( $this );
		
		$this->globals    = $globals;
		
		//Limpa o nome da classe para tentar buscar o nome da tabela
		$table       = explode('_', $class);
		$this->table = strtolower( substr( $class, 0, strlen( $class) - strlen(end($table)) -1 ) );
		
		$this->resetCommand();
		
		$this->mysqlFuncRegex = '/^\b(' . implode('|',$this->mysqlFunctions) . ')\b\(.*\)$/ui';
		
	}
	
	
	
	
	//propriedades do objeto
	//$table
	function set_table( $val ){
		$this->table     = $val;
		$this->structure = null;
	}
	function get_table(){ return $this->table; }
	
	
	
	//Invoca o modelo correto
	static function Load( $class, $globals = ''){
		
		if( $globals == '' ){
			$globals = $GLOBALS['globals'];
		}

		//Impede path traversal / inclusão de arquivo arbitrário via nome vindo da URL
		if( !GojiraCore::isSafeName( $class ) ){
			throw( new ModelException( "Invalid model name", 0x2001 ) );
		}

		$file    = strtolower( $class ) . '.php';
		$dir     = $globals->environment->modelPath;
		$class   = ucfirst($class) . '_Model';

		//Se localizar o arquivo no disco (e confinado ao diretório esperado)
		$full = GojiraCore::confinedFile( $dir, $file );
		if( $full !== false ){

			//Instancia o objeto
			require_once( $full );
			try{
				$objModel = new $class( $globals );
				$objModel->onLoad();
				return $objModel;
			} catch( Exception $e ){
				error_log( '[GOJIRA] Model::Load: falha ao instanciar "' . $class . '": ' . $e->getMessage() );
				throw( new ModelException( 'Error in instantiation of Model "' . ControllerException::label( $class ) . '"', 0x2002 ) );
			}
			
		} else {
			
			//Model não localizado no disco
			throw( new ModelException( 'Model "' . ControllerException::label( $class ) . '" not found', 0x2001 ) );
			
		}
		
	}
	
	
	//Verifica se um Model existe
	static function Exists( $class ){
		
		$ret     = false;
		$globals = $GLOBALS['globals'];

		//Nome inválido nunca existe como model
		if( !GojiraCore::isSafeName( $class ) ){
			return false;
		}

		$file    = strtolower($class) . '.php';
		$class   = ucwords(strtolower($class), "_0123456789") . '_Model';
		$dir     = $globals->environment->modelPath;

		//Se localizar o arquivo no disco (e confinado ao diretório esperado)
		$full = GojiraCore::confinedFile( $dir, $file );
		if( $full !== false ){

			require_once( $full );
			
			if( class_exists( $class ) ){
				$ret = true;
			}
		}
		
		return $ret;
	}
	
	//Executa ao carregar o model
	function onLoad(){}
	
	//Adiciona um campo na consulta SQL
	protected function field( $name, $alias = '', $table = '' ){ $this->sql['field'][] = array( $name, $alias, $table ); return $this; }
	
	//From - No caso de fazer um select de outra tabela, sem precisar mudar o campo TABLE
	protected function from( $table, $alias = 'a' ){ $this->sql['table'] = [$table, $alias]; return $this; }
	
	//Comparações. No modo estrito: $field é coluna (ou Gojira_Raw), $compare é um operador da lista
	//e $value é um valor PHP, escapado (array para IN), um Gojira_Raw ou uma subconsulta.
	//Fora dele, $value entra cru, como antes (com deprecation quando é texto).
	protected function where( $field, $compare, $value ){ $this->sql['where'][] = array($field, $compare, $value ); return $this; }

	//Condição com bind, nos dois modos: whereRaw('a.created > ? OR a.status IN (?)', [$data, [1, 2]])
	protected function whereRaw( $sql, array $params = [] ){ $this->sql['where'][] = array( Gojira_Raw::sql( $sql, $params ), null, null ); return $this; }

	//Separadores do where
	protected function wOr(){   $this->sql['where'][ count( $this->sql['where'] ) - 1 ][] = 'OR';   return $this; }
	protected function wAnd(){	$this->sql['where'][ count( $this->sql['where'] ) - 1 ][] = 'AND';  return $this; }
	
	//order by
	protected function order( $ord, $dir = 'ASC' ){ $this->sql['order'][] = array($ord, $dir); return $this; }
	
	//Group by
	protected function group( $field ){ $this->sql['group'][] = $field; return $this; }
	
	//having
	protected function having( $sintax ){ $this->sql['having'][] = $sintax; return $this; }
	
	//having com bind, nos dois modos: havingRaw('COUNT(*) > ?', [$n])
	protected function havingRaw( $sql, array $params = [] ){ $this->sql['having'][] = Gojira_Raw::sql( $sql, $params ); return $this; }

	//Separadores do having
	protected function hOr(){ $this->sql['having'][] = Gojira_Raw::sql( 'OR' ); return $this; }
	protected function hAnd(){ $this->sql['having'][] = Gojira_Raw::sql( 'AND' ); return $this; }
	
	//Join
	protected function join( $type, $table, $comp, $alias = '' ){ $this->sql[ 'join' ][] = array( $type, $table, $comp, $alias );  return $this; }
	
	//Limit de registros
	protected function limit( $init, $end = 0 ){ $this->sql[ 'limit' ] = array( $init, $end); return $this; }
	
	//Gera o comando sql da consulta
	private function makeCommand( $obj = null){
		
		if( is_null( $obj ) ) $obj = $this;
		
		//TODO - Adicionar ANTI-INJECTION na geração
		//TODO - Adicionar validação de valores como Tipo do Join, operadores do Where, campos do order by;
		
		//Parte dos campos
		if( !isset( $obj->sql[ 'field' ] ) || count( $obj->sql[ 'field' ] ) <= 0 )
			throw( new ModelException( 'No <strong>fields</strong> defined for the command', 0x2011 ) );
		
		
		$sql = 'SELECT ';
		//Campos base do select
		foreach( $obj->sql['field'] as $a){
			$sql .= (strlen( $sql ) > 8 ? ', ' : '') . $this->sqlBuilderField( $a );
		}

		$table = (isset( $obj->sql['table'] ) ? $obj->sql['table'][0] : $obj->table);
		$alias = (isset( $obj->sql['table'] ) ? $obj->sql['table'][1] : 'a');
		if( isset( $obj->sql['table'] ) && self::isStrict() && ( !(is_object( $table ) || self::isSimpleName( $table )) || !self::isSimpleName( $alias ) ) ){
			throw( new ModelException( 'Model::from(): an invalid table or alias is not allowed in strict mode', 0x201A ) );
		}
		$sql .= ' FROM ' . (is_object( $table ) ? '(' . $this->makeCommand($table) . ')' : '`' . $table . '`') . ' as `' . $alias . '` ';

		//parte dos joins
		if( isset( $obj->sql[ 'join' ] ) && count( $obj->sql[ 'join' ] ) > 0 ){
			$b = 98;
			foreach( $obj->sql[ 'join' ] as $a ){
				$sql .= $this->sqlBuilderJoin( $a, $b );
			}
		}

		//Parte do where
		if( isset( $obj->sql['where'] ) && count( $obj->sql['where'] ) > 0 ){
			$sql .= ' WHERE ';
			foreach( $obj->sql['where'] as $a ){
				$sql .= $this->sqlBuilderWhere( $a ) . (count( $a ) > 3 ? ' ' . $a[3] . ' ' : '');
			}
		}

		//Parte do group by
		if( isset( $obj->sql['group'] ) && count( $obj->sql['group'] ) > 0 ){
			$sql .= ' GROUP BY ';
			$g    = 0;
			foreach( $obj->sql['group'] as $a ){
				if( $a instanceof Gojira_Raw ){
					$a = $this->renderRaw( $a );
				} elseif( !self::isIdentifier( $a ) && !ctype_digit( trim( (string)$a ) ) ){
					self::legacySql( 'group', 'Model::group(): a SQL expression', 'a column name or Model::raw()' );
				}
				$sql .= ( $g++ > 0 ? ', ' : '') . $a;
			}
		}

		//Parte do having (hOr/hAnd e havingRaw já chegam como Gojira_Raw)
		if( isset( $obj->sql['having'] ) && count( $obj->sql['having'] ) > 0 ){
			$sql .= ' HAVING ';
			foreach( $obj->sql['having'] as $a ){
				if( $a instanceof Gojira_Raw ){
					$a = $this->renderRaw( $a );
				} else {
					self::legacySql( 'having', 'Model::having(): a raw SQL string', 'havingRaw(\'COUNT(*) > ?\', [$n]) or Model::raw()' );
				}
				$sql .= ' ' . $a . ' ';
			}
		}
		
		
		
		//Parte do order by
		if( isset( $obj->sql['order'] ) && count( $obj->sql['order'] ) > 0 ){
			$sql .= ' ORDER BY ';
			$g    = 0;
			foreach( $obj->sql['order'] as $a ){
				$sql .= ( $g++ > 0 ? ', ' : '') . $this->sqlOrderTerm( $a[0] ) . ' ' . $this->sqlOrderDirection( $a[1] );
			}
		}
		
		
		//Parte do LIMIT
		if( isset( $obj->sql['limit'] ) && count( $obj->sql['limit'] ) > 0 ){
			$end = $obj->sql[ 'limit' ][1];
			if( $end === null || $end === '' ) $end = 0;
			if( !self::isUnsignedInt( $end ) ){
				throw( new ModelException( 'Invalid limit', 0x2016 ) );
			}
			$init = $this->sqlLimit( $obj->sql[ 'limit' ][0] );
			if( (int)$end > 0 && stripos( $init, 'OFFSET' ) !== false ){
				throw( new ModelException( 'Invalid limit', 0x2016 ) );
			}
			$sql .= ' LIMIT ' . $init . ((int)$end > 0 ? ', ' . (int)$end : '');
		}
		
		return $sql;
	}
	
	
	//Reseta o comando
	function resetCommand(){
		$this->sql = [ 'field' => [], 'where' => [], 'order' => [], 'group' => [], 'having' => [], 'join' => [], 'limit' => []];
	}
	
	
	//Conta quantos registros viriam no comando
	function countCommand(){
		
		$sql = $this->makeCommand();
		return $this->count( $sql );
		
	}
	
	
	//Retorna um objeto com as variaveis para gerar um comando sql
	function getCommandObject( $lReset = true ){
		
		$obj          = new StdClass();
		$obj->sql     = $this->sql;
		$obj->table   = $this->table;
		$obj->command = $this->makeCommand();
		
		if( $lReset ) $this->resetCommand();
		
		return $obj;
	}
	
	//Retorna o valor do comando
	function getCommandData( $lReset = true, $lArray = false ){
		
		try{
			$sql  = $this->makeCommand();
		} catch( ModelException $e ){
			throw( $e );
		}
		
		$ret = [];
		
		if( $this->count( $sql ) > 0 ){
			
			$query = $this->query( $sql );
			$ret   = $this->makeDataObject( $query, $lArray );
			
		}
		
		if( $lReset ) $this->resetCommand();
		return $ret;
		
	}
	
	
	protected function makeDataObject( $query, $lArray = false, $primary = null ){
		
		$ret   = [];
		$index = -1;
		
		while( $row = $this->fetch( $query ) ){
			
			//Monta o indice usando as chaves primarias, caso não tenha as chaves, usa um indice numerico
			if( is_array( $primary )){
				$index = [];
				foreach( $primary as $a ) $index[] = $row[ $a ];
				$index = implode( ',', $index );
				
			} else {
				$index++;
			}
			
			if( $lArray ){
				$ret[ $index ] = [];
				foreach( $row as $i => $a ){
					$ret[ $index ][$i] = $a;
				}
				
			} else {
				$ret[ $index ] = new StdClass();
				foreach( $row as $i => $a ){
					$ret[ $index ]->$i = $a;
				}
			}
		}
		
		return $ret;
	}
	
	
	
	//Atualiza as informações da tabela
	function updateTableInfo(){
		
		if( is_array( $this->structure ) ) return;
		
		if( ! $this->tableExist( $this->table ) ){
			throw( new ModelException( 'Invalid table: "' . ControllerException::label( $this->table ) . '"', 0x2003 ) );
		}
		
		$sql  =  'SELECT COLUMN_NAME AS `name`, ' .
		'COLUMN_DEFAULT as `default`, ' .
		'COLUMN_DEFAULT IS NULL OR COLUMN_DEFAULT = "NULL" as `default_is_null`, ' .
		'IS_NULLABLE = "YES" AS `null`, ' .
		'DATA_TYPE AS `type`, ' .
		'IF(CHARACTER_MAXIMUM_LENGTH IS NULL, NUMERIC_PRECISION, CHARACTER_MAXIMUM_LENGTH) AS `length`, ' .
		'IF(CHARACTER_MAXIMUM_LENGTH IS NULL, NUMERIC_SCALE, 0) AS `decimal`, ' .
		'COLUMN_KEY = "PRI" AS `primary_key`, ' .
		'INSTR(EXTRA,"auto_increment") as `auto_increment`, ' .
		'COLUMN_KEY = "PRI" OR COLUMN_KEY = "UNI" as `unique`, ' .
		'NOT( COLUMN_KEY = "" OR COLUMN_KEY IS NULL) as `index` ' .
		'FROM information_schema.COLUMNS ' .
		'WHERE TABLE_SCHEMA="' . $this->escape( $this->globals->database->name ) . '" ' .
		'AND TABLE_NAME="' . $this->escape( $this->table ) . '" ORDER BY ORDINAL_POSITION';
		
		
		$query = $this->query( $sql );
		
		$this->structure = [];
		while( $row = $this->fetch( $query ) ){
			
			$obj = new StdClass();
			$obj->name              = $row['name'];
			$obj->default_value     = $row['default'];
			$obj->default_is_null   = $row['default_is_null'];
			$obj->accept_null       = $row['null'];
			$obj->field_type        = $row['type'];
			$obj->length            = $row['length'];
			$obj->decimal           = $row['decimal'];
			$obj->is_primary_key    = $row['primary_key'];
			$obj->is_auto_increment = $row['auto_increment'];
			$obj->is_unique         = $row['unique'];
			$obj->is_index          = $row['index'];
			
			$this->structure[ $row['name'] ] = $obj;
		}
	}
	
	
	
	//Busca um registro(ou um range de registro) da tabela atual
	function getData( $key = '', $sort = '', $limit = '', $usePrimary = false ){
		
		$this->updateTableInfo();
		
		//Busca as informações usadas no processo
		$tools   = $this->globals->tools;
		$primary = [];
		$where   = '';
		$data    = [];
		
		
		//Filtra as partes usadas
		$primary = $this->getPrimary();
		$where   = $this->getWhere( $key );
		
		$sql = 'SELECT ';
		foreach( $this->structure as $col ){
			
			$close = '';
			
			if( strlen( $sql ) > 7 ) $sql .= ', ';
			
			//Formata alguns valores
			if( $col->field_type == 'datetime' || $col->field_type == 'timestamp' ){
				$sql   .= 'DATE_FORMAT(';
				$close  = ',"%d/%m/%Y %H:%i:%s")';
			} elseif( $col->field_type == 'date' ){
				$sql   .= 'DATE_FORMAT(';
				$close  = ',"%d/%m/%Y")';
			}
			
			$sql .= 'a.`' . $col->name . '`' . $close . ' as "' . $col->name . '"';
			
		}
		$sql .= ' FROM `' . $this->table . '` as a' . (empty( $where ) ? '' : ' WHERE ' . $where );
		
		
		//Caso precise vir em uma ordem especifica
		if( $sort instanceof Gojira_Raw ){
			$sql .= ' ORDER BY ' . $this->renderRaw( $sort );
		} elseif( is_array( $sort ) ){
			$order = '';
			foreach( $sort as $a ) $order .= (empty( $order ) ? '' : ', ') . self::quoteIdentifier( $a );
			$sql .= ' ORDER BY ' . $order;
		} elseif( !empty( $sort ) ){
			//Lista de colunas com direção opcional é aceita; o resto é expressão livre
			if( !preg_match( '/\A\s*`?\w+`?(\s+(ASC|DESC))?\s*(,\s*`?\w+`?(\s+(ASC|DESC))?\s*)*\z/i', $sort ) ){
				self::legacySql( 'sort', 'Model::getData(): sort as a free SQL expression', 'an array of column names, "col [ASC|DESC], ..." or Model::raw()' );
			}
			$sql .= ' ORDER BY ' . $sort;
		}
		
		
		//Caso seja preciso passar um limit de registros
		if( is_array( $limit ) ) $limit = $this->sqlLimit( $limit );
		if( !empty( $limit ) )   $sql .= ' LIMIT ' . $this->sqlLimit( $limit );
		
		if( $this->count( $sql ) > 0 ){
			
			$query = $this->query( $sql );
			$data  = $this->makeDataObject( $query, false, ($usePrimary ? $primary : null) );
		}
		
		return $data;
		
	}
	
	
	//Busca a quantidade de resultados que tem na tabela, baseado nas chaves passadas
	function countData( $key = '' ){
		
		$this->updateTableInfo();
		
		$sql   = 'SELECT 1 FROM `' . $this->table . '`';
		$where = $this->getWhere( $key );
		
		if( !empty( $where ) ) $sql .= ' WHERE ' . $where;
		
		return $this->count( $sql );
		
	}
	
	
	//Retorna um objeto row com os valores padroes dos campos
	function getEmptyRow( $lArray = false ){
		
		$this->updateTableInfo();
		
		if( $lArray ){
			$row = [];
		} else {
			$row = new StdClass();
		}
		
		foreach( $this->structure as $a ){
			$default = preg_replace("/[\'\\\"]/", "", $a->default_value ?? '');
			
			if( $lArray ){
				$row[ $a->name ] = ($a->default_is_null ? NULL : $default);
			} else {
				$key       = ($a->name);
				$row->$key = ($a->default_is_null ? NULL : $default);
			}
		}
		
		return $row;
		
	}
	
	
	//Deleta um registro ou uma serie de registros
	function delete( $key ){
		
		$this->updateTableInfo();
		
		//Sem chave não há o que apagar (antes, '' apagava a tabela inteira e null gerava SQL inválido).
		//Para apagar tudo de propósito, passe a condição explícita, ex.: delete('1=1').
		if( $key === '' || $key === null || $key === [] ){
			throw( new ModelException( 'Delete requires a key', 0x2018 ) );
		}
		
		$sql = 'DELETE FROM `' . $this->table . '` WHERE ' . $this->getWhere( $key );
		
		if( ! $this->execute( $sql ) ){
			throw( new ModelException( "Error while deleting the registry", 0x2012 ) );
		}
		
		return true;
		
	}
	
	
	//Salva uma linha na base de dados
	function saveRow( $row, $retCommand = false ){
		
		$this->updateTableInfo();
		
		$table     = $this->table;
		$sql       = '';
		$where     = '';
		$structure = $this->structure;
		$pk        = [];
		$lUpdate   = false;
		
		//Padronizando para facilitar o acesso aos dados daqui para frente
		if( !is_array( $row ) ){
			$tmp = [];
			foreach( $row as $i => $a ) $tmp[ $i ] = $a;
			$row = $tmp;
		}
		
		//Verifica se os dados estão ok
		foreach( $structure as $col ){
			
			if( isset( $row[ $col->name ] ) ){
				$col_value = $row[ $col->name ];
			} else {
				$col_value = null;
			}
			
			//Campos auto_increment ou timestamp são ignorados na hora de salvar, por isso, não precisa de verificação
			if( !(($col->is_auto_increment && empty($col_value)) || $col->field_type == 'timestamp') ){
				
				//Verifica se é chave primaria e tem um valor
				if( $col->is_primary_key && empty( $col_value ) ){
					throw( new ModelException( 'Primary Key <strong>' . $col->name . '</strong> has no value and are not auto_increment', 0x2013 ) );
				}
				
				//Se o campo estiver nulo, mas não aceitar valores nulos
				if( $col->accept_null == false &&
				$col->is_auto_increment == false &&
				(is_null( $col_value ) || strtolower( $col_value === 'null')) ){
					throw( new ModelException( 'Field <strong>' . $col->name . '</strong> cannot be null', 0x2014 ) );
				}
			}
			
			if( $col->is_primary_key ) $pk[ $col->name ] = $col_value;
			
			$row[ $col->name ] = $this->formatField( $col, $col_value );
			
		}
		
		
		//Monta a sentença de comparação no caso de ser um update e usa para identificar o mesmo
		foreach( $pk as $i => $a ) $where .= (empty( $where ) ? '' : ' AND ') . self::quoteIdentifier( $i ) . '=' . ($a instanceof Gojira_Raw ? $this->renderRaw( $a ) : '"' . $this->escape( $a ) . '"');

		//O WHERE acima é montado aqui, com os valores escapados: vai como Gojira_Raw
		if( $this->exist( $table, '', '', ( $where === '' ? '' : Gojira_Raw::sql( $where ) ) ) ){
			
			//É uma alteração
			foreach( $structure as $col ){
				if( ! ( ($col->is_auto_increment || $col->is_primary_key) && $this->sqlField( $col, $row[ $col->name ] ) == 'NULL' ) ){
					$sql .= ($sql === '' ? '' : ', ' );
					$sql .= self::quoteIdentifier( $col->name ) . '=' . $this->sqlField( $col, $row[ $col->name ]);
				}
			}
			
			$sql     = 'UPDATE `' . $table . '` SET ' . $sql . ' WHERE ' . $where;
			$lUpdate = true;
			
		} else {
			
			//É uma inclusão
			$header = '';
			foreach( $structure as $col ){
				if( ! ($col->is_auto_increment && $this->sqlField( $col, $row[ $col->name ] ) == 'NULL' ) ){
					$header .= ($sql === '' ? '' : ', ') . self::quoteIdentifier( $col->name );
					$sql    .= ($sql === '' ? '' : ', ') . $this->sqlField( $col, $row[ $col->name ] );
				}
			}
			$sql     = 'INSERT INTO `' . $table . '`(' . $header . ') VALUES(' . $sql . ')';
		}
		
		//Não executa o insert, retorna o sql
		if( $retCommand ){
			return $sql;
			
		} else {
			
			//Executa o comando na base de dados
			if( $this->execute( $sql ) ){
				
				$sql = $where = '';
				
				foreach( $pk as $i => $a ){
					
					if( $structure[ $i ]->is_auto_increment && (empty( $a ) || (is_string( $a ) && strtolower($a) == 'null')) ){
						$a = $this->lastId;
					}

					$where .= (empty( $sql ) ? '' : ' AND ') . self::quoteIdentifier( $i ) . '=' . ($a instanceof Gojira_Raw ? $this->renderRaw( $a ) : '"' . $this->escape( $a ) . '"');
					$sql   .= (empty( $sql ) ? '' : ', ') . self::quoteIdentifier( $i );
					
				}
				
				$sql = 'SELECT ' . $sql . ' FROM `' . $table . '` WHERE ' . $where;
				
				$query = $this->query( $sql, true );
				$row   = $this->fetch( $query );
				
			} else {
				
				//Se der erro na hora de salvar, joga um erro para o nivel de cima
				//O SQL (com os valores) não vai na mensagem nem no log; o log leva a tabela
				//e, quando a conexão o guardou, o erro do banco.
				error_log( '[GOJIRA] Model::saveRow falhou na tabela `' . $table . '`: ' . ( self::$connection instanceof mysqli ? self::$connection->errno . ' ' . self::$connection->error : '' ) );
				throw( new ModelException( "Error while saving", 0x2015 ) );
				
			}
		}
		
		return $row;
		
	}
	
	
	
	//Busca um array com as chaves primarias da estrutura
	function getPrimary( $structure = ''){
		
		$this->updateTableInfo();
		
		$primary = [];
		if( empty( $structure ) ) $structure = $this->structure;
		
		//Seleciona todas as chaves primarias( caso exista mais de uma )
		foreach( $structure as $col ){
			if( $col->is_primary_key ) $primary[] = $col->name;
		}
		
		return $primary;
		
	}
	
	
	//Retorna a parte de um where padrao, usado internamente
	function getWhere( $key, $structure = '' ){
		
		$this->updateTableInfo();
		
		if( empty( $structure ) ) $structure = $this->structure;
		
		$primary = $this->getPrimary( $structure );
		$tools   = $this->globals->tools;
		$where   = '';
		
		//Monta a parte de comparação
		if( $key === '' ){
			$where = '1=1';

		} elseif( $key instanceof Gojira_Raw ){

			$where = $this->renderRaw( $key );

		} elseif( is_numeric( $key ) ){

			$where .= $primary[ 0 ] . '="' . $this->escape( $key ) . '"';

		} elseif( is_string( $key ) ){

			//WHERE em string crua: legado (no estrito, só por Model::raw())
			self::legacySql( 'where-string', 'Model: a raw SQL string as key (getData/countData/delete/getWhere)', 'an array of column => value or Model::raw(\'col = ?\', [$value])' );
			$where = $key;
			
		} elseif( is_array( $key ) ){
			
			$c   = 0;
			$tmp = '';
			foreach( $key as $i => $a ){
				
				$tmp = '';
				
				if( is_string( $i ) ){
					$tmp = self::quoteIdentifier( $i ) . ' = "' . $this->escape( $a ) . '"';
					
				} elseif( isset( $primary[ $i ] ) ) {
					$tmp = self::quoteIdentifier( $primary[ $i ] ) . ' = "' . $this->escape( $a ) . '"';
					
				} elseif( $c < count( $primary ) ){
					$tmp = $primary[ $c ] . '="' . $this->escape( $a ) . '"';
				}
				
				if( !empty( $tmp ) ){
					$where .= (empty( $where ) ? '' : ' AND ') . $tmp;
				}
				
				$c++;
			}
			
		}
		
		return $where;
		
	}
	
	
	//Formata o campo baseado no seu conteudo
	function formatField( $format, $value ){
		
		$tools = $this->globals->tools;
		
		if( is_null( $value ) ) return $value;
		
		//A proteção é o escape no sqlField. Texto segue intacto; tipos numéricos e de
		//data/hora só perdem os espaços das pontas (como antes) e viram string.
		if( !strpos( '[char][varchar][enum][blog][mediumblob][longblob][tinyblob][smallblob][text][mediumtext][longtext][tinytext][smalltext]', $format->field_type ) &&
		    is_scalar( $value ) &&
		    strpos( '[timestamp][time][datetime][date][year][integer][int][bigint][mediumint][smallint][tinyint][decimal][double][float][real][unsigned]', '[' . $format->field_type . ']' ) !== false ){
			$value = trim( (string)$value );
		}
		
		
		//Formatando as datas para o formato mysql (dd/mm/aaaa). Data já no formato
		//do MySQL (aaaa-mm-dd ou aaaammdd) segue como está.
		if( is_string( $value ) && $value != 'NULL' && ! preg_match( $this->mysqlFuncRegex, $value ) &&
		    ! preg_match( '/\A(\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?|\d{8}(\d{6})?)\z/', $value ) ){
			
			if( ($format->field_type == 'datetime' || $format->field_type == 'timestamp') && (strlen( $value ) == 16 || strlen( $value ) == 19) ){
				$value = $tools->dateToSql( $value, true );
			} elseif( $format->field_type == 'date' && (strlen( $value ) == 10 || strlen( $value ) == 8) ){
				$value = $tools->dateToSql( $value );
			}
		}
		
		return $value;
		
	}
	
	
	//formata o campo para o mysql
	function sqlField( $format, $value ){
		
		$ret = '';
		
		if( is_null( $value ) ){
			$ret = 'NULL';

		} elseif( $value instanceof Gojira_Raw ){
			$ret = $this->renderRaw( $value );

		} elseif( !is_scalar( $value ) ){
			throw( new ModelException( 'Field <strong>' . $format->name . '</strong> has an invalid value', 0x2017 ) );

		} elseif( preg_match( $this->mysqlFuncRegex, (string)$value ) || preg_match( $this->mysqlLiteralRegex, (string)$value ) ){
			if( preg_match( $this->mysqlLiteralRegex, (string)$value ) ){
				//NULL e CURRENT_TIMESTAMP seguem como palavra reservada
			} elseif( self::isStrict() ){
				//Estrito: sem Gojira_Raw, só funções sem argumento de uma lista fixa
				if( !preg_match( self::$strictValueFunctions, (string)$value ) ){
					throw( new ModelException( 'Field <strong>' . $format->name . '</strong>: a SQL function as value is not allowed in strict mode; use Model::raw()', 0x201A ) );
				}
			} elseif( !preg_match( '/\A\w+\(\s*\)\z/', (string)$value ) ){
				//Legado: função com argumento entra crua no SQL; as sem argumento (NOW(), UUID()...) seguem sem aviso
				self::deprecated( 'function-value', 'Model: SQL function with arguments as a field value is deprecated; use Model::raw()' );
			}
			$ret = $value;
			
		} elseif( strpos( '[timestamp][time][datetime][date][year]', $format->field_type ) !== false ){
			if( empty( $value ) && $format->accept_null ){
				$ret = 'NULL';
			} else {
				$ret = '"' .  $this->escape( $value ) . '"';
			}
			
		} elseif( strpos( '[integer][int][bigint][mediumint][smallint][tinyint][decimal][double][float][real][unsigned]', $format->field_type ) !== false ){
			if( $value === '' ){
				$ret = ($format->accept_null ? 'NULL' : 0);
			} elseif( is_int( $value ) || is_float( $value ) || (is_string( $value ) && is_numeric( $value )) ){
				$ret = $value;
			} elseif( is_bool( $value ) ){
				$ret = (int)$value;
			} else {
				//Valor não numérico (ex.: "10,5"): vai como texto escapado e o banco converte, como antes
				self::deprecated( 'non-numeric', 'Model: non-numeric value for a numeric field is deprecated' );
				$ret = '"' . $this->escape( $value ) . '"';
			}
			
		} elseif( $format->field_type == 'enum' ){
			if( is_int($value) ){
				if( $value > 0 ){
					$ret = $value;
				} else {
					$ret = ($format->accept_null ? 'NULL' : 0);
				}
			} else {
				$ret = '"' . $this->escape( $value ) . '"';
			}
			
		} else {
			$ret = '"' . $this->escape( $value ) . '"';
		}
		
		return $ret;
		
	}
	
	
	//Termo do ORDER BY do construtor de consulta. Nome simples recebe crase;
	//posição numérica e coluna com tabela (a.nome) seguem como estão;
	//qualquer outra expressão segue como está, com aviso de deprecation.
	private function sqlOrderTerm( $term ){

		if( $term instanceof Gojira_Raw ) return $this->renderRaw( $term );

		$term = trim( (string)$term );

		if( preg_match( '/\A\w+\z/', $term ) && !ctype_digit( $term ) ){
			return self::quoteIdentifier( $term );
		}

		if( !preg_match( '/\A(\d+|`[^`]+`|\w+\.\w+|`[^`]+`\.`[^`]+`)\z/', $term ) ){
			self::legacySql( 'order', 'Model::order(): a free SQL expression', 'a column name or Model::raw()' );
		}

		return $term;
	}


	//Campo do construtor: [nome, alias, tabela]
	private function sqlBuilderField( $a ){

		$name = $a[0];

		if( $name instanceof Gojira_Raw ){
			$name = $this->renderRaw( $name );
		} elseif( !self::isIdentifier( $name, true ) ){
			self::legacySql( 'field', 'Model::field(): a SQL expression as field', 'a column name or Model::raw()' );
		}

		if( self::isStrict() && ( (!empty( $a[1] ) && !self::isSimpleName( $a[1] )) || (!empty( $a[2] ) && !self::isSimpleName( $a[2] )) ) ){
			throw( new ModelException( 'Model::field(): an invalid alias or table is not allowed in strict mode', 0x201A ) );
		}

		return (empty( $a[2] ) ? '' : '`' . $a[2] . '`.' ) . $name . (empty( $a[1] ) ? '' : ' AS "' . $a[1] . '"');
	}


	//Join do construtor: [tipo, tabela, condição, alias]. $b é a próxima letra de alias automático.
	private function sqlBuilderJoin( $a, &$b ){

		$type = strtoupper( preg_replace( '/\s+/', ' ', trim( (string)$a[0] ) ) );
		if( !in_array( $type, self::$joinTypes, true ) ){
			self::legacySql( 'join-type', 'Model::join(): a join type outside ' . implode( ', ', self::$joinTypes ), 'one of them' );
		}

		if( self::isStrict() && ( !(is_object( $a[1] ) || self::isSimpleName( $a[1] )) || (!empty( $a[3] ) && !self::isSimpleName( $a[3] )) ) ){
			throw( new ModelException( 'Model::join(): an invalid table or alias is not allowed in strict mode', 0x201A ) );
		}

		//Condição: Gojira_Raw ou comparação entre colunas (a.id = b.a_id [AND ...]); o resto é legado
		$on = $a[2];
		if( $on instanceof Gojira_Raw ){
			$on = $this->renderRaw( $on );
		} else {
			$col = '(?:(?:`[^`]+`|[A-Za-z_]\w*)\.)?(?:`[^`]+`|[A-Za-z_]\w*)';
			$cmp = $col . '\s*(?:=|<>|!=|<=|>=|<|>)\s*' . $col;
			if( !is_string( $on ) || !preg_match( '/\A\s*' . $cmp . '(?:\s+AND\s+' . $cmp . ')*\s*\z/i', $on ) ){
				self::legacySql( 'join-on', 'Model::join(): a SQL expression as join condition', 'a column comparison or Model::raw()' );
			}
		}

		return ' ' . strtoupper($a[0]) . ' JOIN ' .
		(is_object( $a[1] ) ? '(' . $this->makeCommand($a[1]) . ')' : '`' . $a[1] . '`') .
		' as ' . (empty( $a[3] ) ? chr( $b++ ) : '`' . $a[3] . '`') . ' on ' . $on;
	}


	//Comparação do where: [campo, operador, valor] ou, vindo do whereRaw, [Gojira_Raw, null, null]
	private function sqlBuilderWhere( $a ){

		if( $a[0] instanceof Gojira_Raw && $a[1] === null && $a[2] === null ){
			return $this->renderRaw( $a[0] );
		}

		$field = $a[0];
		if( $field instanceof Gojira_Raw ){
			$field = $this->renderRaw( $field );
		} elseif( !self::isIdentifier( $field ) ){
			self::legacySql( 'where-field', 'Model::where(): a SQL expression as field', 'a column name, Model::raw() or whereRaw()' );
		}

		$op = strtoupper( preg_replace( '/\s+/', ' ', trim( (string)$a[1] ) ) );
		if( !in_array( $op, self::$whereOperators, true ) ){
			self::legacySql( 'where-operator', 'Model::where(): an operator outside ' . implode( ', ', self::$whereOperators ), 'one of them or whereRaw()' );
		}

		$value = $a[2];
		if( $value instanceof Gojira_Raw ){
			$value = $this->renderRaw( $value );
		} elseif( is_object( $value ) ){
			$value = '(' . $this->makeCommand( $value ) . ')';
		} elseif( self::isStrict() ){
			$value = $this->sqlWhereValue( $op, $value );
		} elseif( is_string( $value ) && !is_numeric( $value ) && !preg_match( '/\A\s*(NOT\s+)?(NULL|TRUE|FALSE|UNKNOWN)\s*\z/i', $value ) ){
			//Legado: o valor entra cru. No estrito e na 2.0 ele vira um valor escapado.
			self::deprecated( 'where-value', 'Model::where(): a raw SQL value is deprecated; use whereRaw(\'col = ?\', [$value]) or Model::raw()' );
		}

		return $field . ' ' . (self::isStrict() ? $op : $a[1]) . ' ' . $value;
	}


	//Valor do where no modo estrito: sempre um literal escapado
	private function sqlWhereValue( $op, $value ){

		if( $op === 'IS' || $op === 'IS NOT' ){
			if( is_null( $value ) ) return 'NULL';
			if( is_bool( $value ) ) return ( $value ? 'TRUE' : 'FALSE' );
			if( is_string( $value ) && preg_match( '/\A\s*(NULL|TRUE|FALSE|UNKNOWN)\s*\z/i', $value, $m ) ) return strtoupper( $m[1] );
			throw( new ModelException( 'Model::where(): IS accepts only NULL, TRUE, FALSE or UNKNOWN', 0x201A ) );
		}

		if( $op === 'IN' || $op === 'NOT IN' ){
			return '(' . $this->sqlLiteral( is_array( $value ) ? $value : [ $value ] ) . ')';
		}

		return $this->sqlLiteral( $value, false );
	}
	
	
	//Direção do ORDER BY: só ASC, DESC ou vazio
	private function sqlOrderDirection( $dir ){
		
		$dir = (string)$dir;
		if( !in_array( strtoupper( trim( $dir ) ), ['', 'ASC', 'DESC'], true ) ){
			throw( new ModelException( 'Invalid sort direction', 0x2019 ) );
		}
		
		return $dir;
	}
	
	
	//Normaliza um LIMIT: inteiro, "n", "n, m", "n OFFSET m" ou [n] / [n, m]. O resto lança ModelException.
	protected function sqlLimit( $limit ){
		
		if( is_string( $limit ) && preg_match( '/\A\s*(\d+)\s+OFFSET\s+(\d+)\s*\z/i', $limit, $m ) ){
			return (int)$m[1] . ' OFFSET ' . (int)$m[2];
		}
		
		if( is_array( $limit ) ){
			$parts = array_values( $limit );
		} elseif( is_string( $limit ) && preg_match( '/\A\s*(\d+)\s*(?:,\s*(\d+)\s*)?\z/', $limit, $m ) ){
			$parts = array_slice( $m, 1 );
		} else {
			$parts = [ $limit ];
		}
		
		if( count( $parts ) < 1 || count( $parts ) > 2 ){
			throw( new ModelException( 'Invalid limit', 0x2016 ) );
		}
		
		foreach( $parts as $i => $a ){
			if( !self::isUnsignedInt( $a ) ){
				throw( new ModelException( 'Invalid limit', 0x2016 ) );
			}
			$parts[ $i ] = (int)$a;
		}
		
		return implode( ', ', $parts );
	}
	
	
	//Inteiro não negativo, como int ou string só de dígitos
	private static function isUnsignedInt( $value ){
		return (is_int( $value ) && $value >= 0) || (is_string( $value ) && preg_match( '/\A\d+\z/', $value ) === 1);
	}
	
	
	//Coloca um identificador entre crases, duplicando crases internas
	protected static function quoteIdentifier( $name ){
		return '`' . str_replace( '`', '``', (string)$name ) . '`';
	}


	//Nome simples (sem ponto) para tabela e alias do construtor
	private static function isSimpleName( $name ){
		return is_string( $name ) && preg_match( '/\A[A-Za-z_]\w*\z/', $name ) === 1;
	}

	
}



//Objeto de erro personalizadp
class ModelException extends Exception{
	
	function __construct( $message, $err_cod ){
		parent::__construct( $message, $err_cod );
	}
	
}
