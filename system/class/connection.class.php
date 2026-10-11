<?php
/* Connection.class.php - Classe de conexão padrão do sistema
   Contem metodos para se trabalhar com o banco de dados de forma simples
   com funções que facilitam na interação.
   
   por Carlson A. Soares - 2011-02-17 09:06
   
   Ultimo Update:
      Count aceita comandos sql
*/

//CONSTANTES DA CLASSE

//Error msgs
define( 'DB_CONNECTION_NO_HOST', "Não foi informado um host para conexão com a base de dados" );
define( 'DB_CONNECTION_ERROR', "Erro ao conectar na base de dados: \n%s\n" );
define( 'DB_CONNECTION_INVALID_SQL', 'Comando sql inválido fornecido para consulta');
define( 'DB_CONNECTION_INVALID_QUERY', 'Objeto de consulta inválido');
define( 'DB_CONNECTION_INVALID_TABLE', 'Nome da tabela inválido');
define( 'DB_CONNECTION_INVALID_PARAMETER', 'Parametos inválidos para função %s');
define( 'DB_CONNECTION_INVALID_CHARSET', "Charset '%s' não aceito pelo servidor: %s");

//Código das falhas de run/runAll/runGet
define( 'DB_CONNECTION_ERR_BOUND', 0x4001 );


      class Connection extends GojiraCore{
    
         protected static $connection;    //Objeto de conexão
         protected static $lastCommand;   //Ultimo comando executado
         protected static $affectedRows = 0; //Linhas afetadas pelo último run/runAll/runGet
         
         protected static $database;		//Base de dados em que está conectado
         protected static $dbHost;			//Host atual conectado
			protected static $dbUser;			//Usuário que foi usado para conectar
			protected static $dbPassword;		//Senha para a base de dados

         //Modo estrito (opt-in): null segue a constante GOJIRA_MODEL_STRICT; true/false vem de setStrict()
         protected static $strict = null;

         //Avisos de deprecation já emitidos nesta requisição (um por recurso)
         protected static $deprecationsSent = [];

         //Charset e locale aplicados a cada conexão. Os padrões mantêm o comportamento da 1.0.
         const DEFAULT_CHARSET       = 'utf8';
         const DEFAULT_LC_TIME_NAMES = 'pt_BR';
         protected static $charset     = self::DEFAULT_CHARSET;
         protected static $lcTimeNames = self::DEFAULT_LC_TIME_NAMES;


         //Se o PHP sofre um erro e por algum motivo fecha durante uma transação
         //a tabela fica locada no mysql até o apache reiniciar. Para evitar isso
         //usamos uma exit_function para rodar caso aconteça algum problema
         protected $transaction_in_progress = false;
         

         //As propriedades estão ai para manter compatibilidade com sistemas antigos
         const FETCH_OBJECT = 0;
         const FETCH_ASSOC  = MYSQLI_ASSOC;
         const FETCH_NUM    = MYSQLI_NUM;
         public $FETCH_OBJECT = 0;
         public $FETCH_ASSOC  = MYSQLI_ASSOC;
         public $FETCH_NUM    = MYSQLI_NUM;
         
			
         function __construct1( $dbHost )                            { $this->configure( $dbHost, '', '' ); }
         function __construct3( $dbHost, $dbUser, $dbPass )          { $this->configure( $dbHost, $dbUser, $dbPass ); } 
         function __construct4( $dbHost, $dbUser, $dbPass, $dbName ) { $this->configure( $dbHost, $dbUser, $dbPass, $dbName ); } 
        
         //Destructor força a desconexão do banco de dados;
         //Desativado porque tava dando problema: Como o model extende connection,
         //Esse destruct desconectava no final do model, dando problema se tentasse usar a conexão
         //Em outros pontos apos o termino do model.
         //TODO - Deixar isso no engine.php quando ele for integrado ao system
         /*function __destruct() {
            $this->disconnect();
         }*/
        
        
        
        
         /***   Metodos de SET e GET de valores ***/
         function get_connection()  { return self::$connection; }
         function get_connected()   { return $this->isConnected(); }
         function get_lastCommand() { return self::$lastCommand; }
         function get_lastId()      { return mysqli_insert_id( $this->connection ); }
			function get_database()    { return self::$database; }
         function get_host()        { return self::$dbHost; }
        
        
        
         /***   Charset e locale da conexão ***/

         //Define o charset das próximas conexões (ex.: 'utf8mb4'). Vazio volta ao padrão 'utf8'.
         //Se já houver conexão aberta, aplica nela também.
         static function setCharset( $charset ){

            $charset = ( $charset === null || $charset === '' ) ? self::DEFAULT_CHARSET : (string)$charset;

            if( ! preg_match( '/\A[A-Za-z0-9_]{1,32}\z/', $charset ) ){
               throw( new InvalidArgumentException( sprintf( DB_CONNECTION_INVALID_PARAMETER, __METHOD__ ) ) );
            }

            if( self::openConnection() && ( $error = self::applyCharset( self::$connection, $charset ) ) !== null ){
               throw( new Exception( sprintf( DB_CONNECTION_INVALID_CHARSET, $charset, $error ) ) );
            }

            self::$charset = $charset;
         }

         static function getCharset(){ return self::$charset; }


         //Define o lc_time_names das próximas conexões (ex.: 'en_US'). Vazio volta ao padrão 'pt_BR'.
         //Se já houver conexão aberta, aplica nela também.
         static function setLcTimeNames( $name ){

            $name = ( $name === null || $name === '' ) ? self::DEFAULT_LC_TIME_NAMES : (string)$name;

            if( ! preg_match( '/\A[a-z]{2,3}_[A-Z]{2}\z/', $name ) ){
               throw( new InvalidArgumentException( sprintf( DB_CONNECTION_INVALID_PARAMETER, __METHOD__ ) ) );
            }

            if( self::openConnection() && ! self::$connection->query( "SET lc_time_names='" . $name . "'" ) ){
               throw( new Exception( sprintf( DB_CONNECTION_INVALID_PARAMETER, __METHOD__ ) ) );
            }

            self::$lcTimeNames = $name;
         }

         static function getLcTimeNames(){ return self::$lcTimeNames; }


         //Aplica o charset com mysqli::set_charset. Devolve null ou a mensagem de erro
         //(com ou sem o modo de exceções do mysqli ligado).
         private static function applyCharset( mysqli $connection, $charset ){

            try{
               return $connection->set_charset( $charset ) ? null : $connection->error;
            } catch( mysqli_sql_exception $e ){
               return $e->getMessage();
            }
         }


         //Indica se há uma conexão aberta para aplicar a mudança na hora.
         //Sem ela, o valor só vale a partir do próximo connect().
         private static function openConnection(){

            if( ! is_object( self::$connection ) ) return false;

            try{
               return self::$connection->thread_id > 0;   //lança Error se o mysqli já foi fechado
            } catch( Error $e ){
               return false;   //mysqli já fechado
            }
         }



         /***   Metodos do Objeto ***/
         function configure($dbHost, $dbUser, $dbPass, $dbName = ''){

            self::$dbHost     = $dbHost;
            self::$dbUser     = $dbUser;
            self::$dbPassword = $dbPass;
            self::$database   = $dbName;

         }
        

         //Conecta no banco de dados
         function connect(){

            //Quando não existe o host, identifica como uma sessão sem conexão com o banco
            if( empty( self::$dbHost ) ){
               throw(new Exception( DB_CONNECTION_NO_HOST ) );
               exit();
            }
            
            //O erro do mysqli traz host e usuário: vai só para o log. A exceção leva
            //o código numérico. No PHP 8.1+ o mysqli lança mysqli_sql_exception, que
            //não é encadeada para o detalhe não reaparecer na saída de erro.
            try {
               //@: no PHP 8.0 o aviso do mysqli também traz usuário e host (vai para o log abaixo)
               $connection = @new mysqli(self::$dbHost, self::$dbUser, self::$dbPassword, self::$database );
            } catch( mysqli_sql_exception $e ){
               error_log( '[GOJIRA] Connection::connect falhou (' . $e->getCode() . '): ' . $e->getMessage() );
               throw(new Exception( sprintf(DB_CONNECTION_ERROR, 'código ' . $e->getCode() . ', detalhes no log do servidor') ) );
            }
            
            //Verifica se conectou corretamente
            if(mysqli_connect_errno()){
               error_log( '[GOJIRA] Connection::connect falhou (' . mysqli_connect_errno() . '): ' . mysqli_connect_error() );
               throw(new Exception( sprintf(DB_CONNECTION_ERROR, 'código ' . mysqli_connect_errno() . ', detalhes no log do servidor') ) );
               exit();
            }
            
            //set_charset (e não SET NAMES) para o real_escape_string conhecer o charset da conexão.
            //Ajusta character_set_client, character_set_connection e character_set_results.
            $error = self::applyCharset( $connection, self::$charset );
            if( $error !== null ){
               $connection->close();
               throw(new Exception( sprintf(DB_CONNECTION_INVALID_CHARSET, self::$charset, $error) ) );
            }

            self::$connection = $connection;

            $this->execute("SET lc_time_names='" . self::$lcTimeNames . "'");

            return $this;
         }


         //Seleciona a base de dados ativa da coexão
         function selectDatabase( $dbName ){
				
            self::$database = $dbName;
            $ret            = false;

            if( $this->connected ){
               $ret = self::$connection->select_db( self::$database );
            } 

            return $ret;
				
         }


         //Desconecta da base de dados
         function disconnect(){
            
            $ret = false;

            if( $this->connected ){
               $ret = @self::$connection->close();
            }

            return $ret;
         }


			//Reconecta a base de dados
			function reconnect(){
				
				$this->disconnect(); 
				return $this->connect();
				
			}


			//Verifica se o servidor está connectado
			function isConnected(){
				
				//Se não for objeto é por que não está conectado
				if( ! is_object( self::$connection ) ) return false;
				
				//Se for objeto, verifica se a conexão está ativa
				return true;
				
			}


         //Checa se está conectado e gera um erro caso não esteja
         private function checkConnection(){

            if( !$this->isConnected() ){
               $this->reconnect();
            }

            return true;
         }


         //Executa uma consulta no banco de dados
         function query( $sql ){
            
            $this->checkConnection();

            self::$lastCommand = $sql;
            
            //No PHP 8.1+ o mysqli lança mysqli_sql_exception com trecho do SQL na
            //mensagem. O detalhe vai para o log; a exceção leva a mensagem genérica
            //e o código do erro do banco (ex.: 1062), sem encadear a original.
            try {
               $query = self::$connection->query( $sql );
            } catch( mysqli_sql_exception $e ){
               $this->logLegacyFailure( 'query', $e->getCode(), $e->getMessage() );
               throw( new ConnectionException( DB_CONNECTION_INVALID_SQL, $e->getCode() ) );
            }
            
            if( !is_object($query) && $query === false ){
               $this->logLegacyFailure( 'query', self::$connection->errno, self::$connection->error );
               throw( new Exception(DB_CONNECTION_INVALID_SQL) );
            }

            return $query;
         }


         //Escapa um valor para uso seguro DENTRO de aspas em uma query.
         //Deve ser usado sempre que um valor for interpolado manualmente num SQL.
         function escape( $value ){

            $this->checkConnection();

            return self::$connection->real_escape_string( (string)( $value ?? '' ) );
         }


         //Liga (true), desliga (false) ou volta a seguir a constante GOJIRA_MODEL_STRICT (null).
         //A chave é global: vale para todos os Models e Connections até o fim da requisição.
         //No modo estrito, SQL cru do chamador só entra por Gojira_Raw / Connection::raw().
         //Não cobre query(), execute(), secureExec(), run*(), count($sql), max()/min() nem
         //o SQL que uma subclasse monta por conta própria: esses recebem SQL por contrato.
         static function setStrict( $on ){
            self::$strict = ( $on === null ? null : (bool)$on );
         }

         static function isStrict(){
            if( self::$strict !== null ) return self::$strict;
            return defined( 'GOJIRA_MODEL_STRICT' ) && (bool)constant( 'GOJIRA_MODEL_STRICT' );
         }

         //Marca um trecho como SQL intencional. Cada ? fora de aspas recebe um valor de $params, escapado.
         static function raw( $sql, array $params = [] ){
            return Gojira_Raw::sql( $sql, $params );
         }


         //Troca cada ? fora de aspas, crases e comentários (/* */, "-- " e #) por um literal
         //escapado de $params (na ordem). O ? de dentro de comentário fica como está.
         //Placeholder sem valor, valor sobrando, aspas ou comentário abertos lançam ModelException 0x201B.
         function bindSql( $sql, array $params = [] ){

            $sql    = (string)$sql;
            $params = array_values( $params );
            $len    = strlen( $sql );
            $out    = '';
            $quote  = null;
            $n      = 0;

            for( $i = 0; $i < $len; $i++ ){
               $c = $sql[ $i ];

               if( $quote !== null ){
                  $out .= $c;
                  if( $c === '\\' && $quote !== '`' && $i + 1 < $len ){
                     $out .= $sql[ ++$i ];
                  } elseif( $c === $quote ){
                     $quote = null;
                  }

               } elseif( $c === "'" || $c === '"' || $c === '`' ){
                  $quote = $c;
                  $out  .= $c;

               } elseif( $c === '/' && $i + 1 < $len && $sql[ $i + 1 ] === '*' ){
                  $end = strpos( $sql, '*/', $i + 2 );
                  if( $end === false ){
                     throw( new ModelException( 'Unterminated comment in SQL fragment', 0x201B ) );
                  }
                  $out .= substr( $sql, $i, $end + 2 - $i );
                  $i    = $end + 1;

               } elseif( $c === '#' || ( $c === '-' && substr( $sql, $i + 1, 1 ) === '-' && ( $i + 2 >= $len || ord( $sql[ $i + 2 ] ) <= 32 ) ) ){
                  //Comentário até o fim da linha (no MySQL, "--" só abre comentário seguido de espaço)
                  $end  = strpos( $sql, "\n", $i );
                  $end  = ( $end === false ? $len : $end );
                  $out .= substr( $sql, $i, $end - $i );
                  $i    = $end - 1;

               } elseif( $c === '?' ){
                  if( !array_key_exists( $n, $params ) ){
                     throw( new ModelException( 'Missing value for placeholder ' . ($n + 1), 0x201B ) );
                  }
                  $out .= $this->sqlLiteral( $params[ $n++ ] );

               } else {
                  $out .= $c;
               }
            }

            if( $quote !== null ){
               throw( new ModelException( 'Unterminated quote in SQL fragment', 0x201B ) );
            }
            if( $n !== count( $params ) ){
               throw( new ModelException( 'More values than placeholders', 0x201B ) );
            }

            return $out;
         }


         //Literal SQL de um valor PHP: NULL, 1/0, número, texto escapado entre aspas simples,
         //Gojira_Raw como está e array como lista "a, b" (para IN). O resto lança ModelException 0x201B.
         function sqlLiteral( $value, $allowList = true ){

            if( $value instanceof Gojira_Raw ) return $this->renderRaw( $value );
            if( is_null( $value ) )            return 'NULL';
            if( is_bool( $value ) )            return ( $value ? '1' : '0' );
            if( is_int( $value ) )             return (string)$value;
            if( is_float( $value ) && is_finite( $value ) ) return (string)$value;
            if( is_string( $value ) )          return "'" . $this->escape( $value ) . "'";

            if( is_array( $value ) && $allowList && count( $value ) > 0 ){
               $list = [];
               foreach( $value as $a ) $list[] = $this->sqlLiteral( $a, false );
               return implode( ', ', $list );
            }

            throw( new ModelException( 'Invalid value for a SQL placeholder', 0x201B ) );
         }


         //SQL de um Gojira_Raw, com os valores vinculados
         function renderRaw( Gojira_Raw $raw ){
            return ( $raw->getParams() === [] ? $raw->getSql() : $this->bindSql( $raw->getSql(), $raw->getParams() ) );
         }


         //Identificador simples: coluna, tabela.coluna (com ou sem crase) e, se $star, * ou tabela.*
         protected static function isIdentifier( $name, $star = false ){

            if( !is_string( $name ) ) return false;

            $part = '(?:`[^`]+`|[A-Za-z_]\w*)';
            $last = ( $star ? '(?:`[^`]+`|[A-Za-z_]\w*|\*)' : $part );

            return preg_match( '/\A\s*(?:' . $part . '\.)?' . $last . '\s*\z/', $name ) === 1 ||
                   ( $star && trim( $name ) === '*' );
         }


         //Caminho legado: no modo estrito lança ModelException 0x201A; fora dele emite
         //E_USER_DEPRECATED (uma vez por recurso) e deixa o chamador seguir.
         protected static function legacySql( $feature, $what, $use ){

            if( self::isStrict() ){
               throw( new ModelException( $what . ' is not allowed in strict mode; use ' . $use, 0x201A ) );
            }

            self::deprecated( $feature, $what . ' is deprecated; use ' . $use );
         }


         //Condição de exist()/getValue(): Gojira_Raw, array coluna => valor ou (legado) string crua
         protected function sqlCondition( $compare, $feature ){

            if( $compare instanceof Gojira_Raw ) return $this->renderRaw( $compare );

            if( is_array( $compare ) ){
               $sql = '';
               foreach( $compare as $col => $val ){
                  $sql .= ( $sql === '' ? '' : ' AND ' ) . '`' . str_replace( '`', '``', (string)$col ) . '`' .
                          ( is_null( $val ) ? ' IS NULL' : ' = ' . $this->sqlLiteral( $val, false ) );
               }
               return $sql;
            }

            self::legacySql( $feature, 'Connection::' . $feature . '(): a raw SQL condition', 'Connection::raw(\'col = ?\', [$value]) or an array of column => value' );
            return (string)$compare;
         }


         //Emite E_USER_DEPRECATED uma vez por requisição para cada recurso
         protected static function deprecated( $feature, $message ){

            if( isset( self::$deprecationsSent[ $feature ] ) ) return;
            self::$deprecationsSent[ $feature ] = true;

            trigger_error( $message, E_USER_DEPRECATED );
         }


         //Prepara, vincula (bind) e executa um statement. Uso interno de run/runAll/runGet.
         //Os tipos são inferidos: int -> i, float -> d, demais -> s.
         //Qualquer falha vira ConnectionException com mensagem genérica, igual em todas as
         //versões do PHP (no 8.0 o mysqli devolve false; no 8.1+ lança mysqli_sql_exception).
         //O detalhe (SQL com placeholders e erro do servidor) vai só para o error_log;
         //os valores dos parâmetros nunca são registrados.
         private function executeBound( $method, $sql, array $params ){

            $this->checkConnection();
            self::$lastCommand  = $sql;
            self::$affectedRows = 0;

            $stmt = false;
            try {

               $stmt = self::$connection->prepare( $sql );
               if( $stmt === false ){
                  $this->failBound( $method, $sql, self::$connection->errno, self::$connection->error );
               }

               if( !empty( $params ) ){
                  $types = '';
                  foreach( $params as $p ){
                     if( is_int( $p ) )        $types .= 'i';
                     elseif( is_float( $p ) )  $types .= 'd';
                     else                      $types .= 's';
                  }

                  if( strlen( $types ) !== $stmt->param_count ){
                     $this->failBound( $method, $sql, 0, 'esperados ' . $stmt->param_count . ' parâmetros, recebidos ' . strlen( $types ) );
                  }

                  //bind_param exige referências
                  $params = array_values( $params );
                  $bind   = [ $types ];
                  foreach( $params as $k => $v ) $bind[] = &$params[ $k ];
                  if( call_user_func_array( [ $stmt, 'bind_param' ], $bind ) === false ){
                     $this->failBound( $method, $sql, $stmt->errno, $stmt->error );
                  }
               } elseif( $stmt->param_count > 0 ){
                  $this->failBound( $method, $sql, 0, 'esperados ' . $stmt->param_count . ' parâmetros, recebidos 0' );
               }

               if( ! $stmt->execute() ){
                  $this->failBound( $method, $sql, $stmt->errno, $stmt->error );
               }

               self::$affectedRows = $stmt->affected_rows;

               return $stmt;

            } catch( mysqli_sql_exception $e ){
               if( $stmt instanceof mysqli_stmt ) $stmt->close();
               $this->failBound( $method, $sql, $e->getCode(), $e->getMessage() );
            } catch( ConnectionException $e ){
               if( $stmt instanceof mysqli_stmt ) $stmt->close();
               throw $e;
            }
         }


         //Registra o detalhe da falha no error_log e lança a exceção genérica.
         private function failBound( $method, $sql, $errno, $error ){

            error_log( sprintf( '[GOJIRA] Connection::%s falhou (%s): %s | SQL: %s',
                                $method, $errno, $error, $sql ) );

            throw( new ConnectionException( DB_CONNECTION_INVALID_SQL, DB_CONNECTION_ERR_BOUND ) );
         }


         //Executa um comando (INSERT/UPDATE/DELETE) com parâmetros vinculados.
         //Retorna true em caso de sucesso; falha lança ConnectionException.
         //O número de linhas afetadas fica em affectedRows().
         //Nenhum valor é concatenado no SQL.
         function run( $sql, array $params = [] ){

            $stmt = $this->executeBound( 'run', $sql, $params );
            $stmt->close();

            return true;
         }


         //Executa um SELECT com parâmetros vinculados e retorna TODAS as linhas.
         function runAll( $sql, array $params = [], $type = MYSQLI_ASSOC ){

            $stmt = $this->executeBound( 'runAll', $sql, $params );

            try {
               $res  = $stmt->get_result();
               $rows = ( $res ? $res->fetch_all( $type ) : [] );
            } catch( mysqli_sql_exception $e ){
               $stmt->close();
               $this->failBound( 'runAll', $sql, $e->getCode(), $e->getMessage() );
            }
            $stmt->close();

            return $rows;
         }


         //Executa um SELECT com parâmetros vinculados e retorna a PRIMEIRA linha (ou null).
         function runGet( $sql, array $params = [], $type = MYSQLI_ASSOC ){

            $rows = $this->runAll( $sql, $params, $type );
            return isset( $rows[0] ) ? $rows[0] : null;
         }


         //Linhas afetadas pelo último run/runAll/runGet.
         function affectedRows(){
            return self::$affectedRows;
         }


         //Executa um comando na base de dados
         function execute( $sql ){
            
            $this->checkConnection();
				
            $ret = false;
				
            self::$lastCommand = $sql;
                        
				//Executa o comando. Com o relatório do mysqli desligado (padrão do PHP
				//8.0) a falha devolve false; com ele ligado (padrão do 8.1+) continua
				//lançando, mas uma exceção genérica, sem o trecho de SQL da original.
				//O detalhe vai para o log nos dois casos.
            try {
               if( $stmt = self::$connection->prepare( $sql ) ){
                  if( $stmt->execute() ) return true;
                  $this->logLegacyFailure( 'execute', $stmt->errno, $stmt->error );
                  return false;
               }
               $this->logLegacyFailure( 'execute', self::$connection->errno, self::$connection->error );
            } catch( mysqli_sql_exception $e ){
               $this->logLegacyFailure( 'execute', $e->getCode(), $e->getMessage() );
               throw( new ConnectionException( DB_CONNECTION_INVALID_SQL, $e->getCode() ) );
            }
            
				return false;
         }


         //Log das falhas de query()/execute(). Leva o erro do banco, não o SQL montado
         //(que traz os valores).
         private function logLegacyFailure( $method, $errno, $error ){
            error_log( sprintf( '[GOJIRA] Connection::%s falhou (%s): %s', $method, $errno, $error ) );
         }


         //Executa uma serie de comandos sql em uma transação
         function secureExec( $sql ){
            
            $ret = true;

            $this->checkConnection();
            
            if( ! is_array( $sql ) ) $sql = array( $sql );
            
            register_shutdown_function(array($this, "__shutdown_check"));
            
            $this->transaction_in_progress = true;
            self::$connection->autocommit( false );
            self::$connection->begin_transaction( MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT );
               
            try{
               foreach( $sql as $a ){
                  if( ! $this->execute( $a ) ){
                     $ret = false;
                     break;
                  }
               }
            } catch( Exception $e ){
               $ret = false;
            }
            
            if( $ret ){
               self::$connection->commit();
            } else {
               self::$connection->rollback();
            }
            
            self::$connection->autocommit( true );
            $this->transaction_in_progress = false;
            
            return $ret;
         }


         //Carrega uma linha do resultSet
         function fetch( $query, $type = MYSQLI_ASSOC){
            
            if( ! is_object( $query ) ){
               throw( new Exception(DB_CONNECTION_INVALID_QUERY));
            }
            
            if( $type == $this->FETCH_OBJECT ){
               $row = $query->fetch_object();
            } else {
               $row = $query->fetch_array( $type );
            }
				
            return $row;
         }
         
         
         //Carrega todo o resultset em um array
         function fetchAll( $query, $type = MYSQLI_ASSOC, $map_function = null ){
            
            if( is_object( $map_function ) ){
               for($set = []; $row = $this->fetch( $query, $type ); $set[] = $map_function($row));               
            } else {
               for($set = []; $row = $this->fetch( $query, $type ); $set[] = $row);
            }
            
            return $set;
         }


         //Seleciona o valor mais alto de uma coluna
         function max( $table, $field ){
            
            if( empty( $table ) || empty( $field ) ){
               throw( new Exception( sprintf(DB_CONNECTION_INVALID_PARAMETER, 'MAX') ) );
            }

            $this->checkConnection();

            $query = $this->query( 'select max(`' . $field . '`) as a from `' . $table . '`' );
            $row = $this->fetch($query);
            return $row['a'];

         }


         //Seleciona o valor mais baixo
         function min( $table, $field ){
            
            if( empty( $table ) || empty( $field ) ){
               throw( new Exception(sprintf(DB_CONNECTION_INVALID_PARAMETER, 'MIN')) );
            }

            $this->checkConnection();

            $query = $this->query( 'select min(`' . $field . '`) as a from `' . $table . '`' );
            $row = $this->fetch($query);
            return $row['a'];

         }


         //Conta o numero de registros no resultSet
         function count( $query ){
            
            $n = false;
            
            if( is_string( $query ) ){
               
               $this->checkConnection();

               $sql = 'select count(*) as a from (' . $query . ') as a';
               $ret = $this->query( $sql );
               $row = $this->fetch( $ret );
               $n   = $row['a'];
            } elseif( is_object( $query ) && get_class( $query ) == 'mysqli_result' ) {
               $n = $query->num_rows;
            }
            
            return $n;
         }


         //Verifica se um valor existe em um campo da tabela
         function exist( $table, $column = '', $value = '', $compare = ''){
            
            if( empty( $table ) ){
               throw( new Exception(sprintf(DB_CONNECTION_INVALID_PARAMETER, 'EXIST')) );
            }

            $this->checkConnection();

            $sql = '';

            if( !empty( $column  ) ) $sql .= '`' . str_replace( '`', '``', $column ) . '`' . ( is_null($value) ? ' IS NULL ' : '="' . $this->escape( $value ) . '" ');
            //$compare: Gojira_Raw (com bind), array coluna => valor ou, no legado, SQL cru
            if( !empty( $compare ) ) $sql .= (empty( $sql ) ? '' : ' AND ') . $this->sqlCondition( $compare, 'exist' );
            if( !empty( $sql     ) ) $sql = 'SELECT COUNT(*) AS a FROM `' . str_replace( '`', '``', $table ) . '` WHERE ' . $sql;

            $query = $this->query( $sql );
            
            if( $this->count( $query ) > 0 ){
               $row = $this->fetch( $query );
               return $row['a'] > 0;
            }

            return false;
         }
			
			
			//Verifica se a tabela existe
			function tableExist( $table ){
            
            if( empty( $table ) ){
               throw( new Exception(DB_CONNECTION_INVALID_TABLE) );
            }

            $this->checkConnection();

				$sql = 'SELECT COUNT(*) AS a FROM information_schema.TABLES WHERE TABLE_SCHEMA="' . $this->escape( self::$database ) . '" and TABLE_NAME="' . $this->escape( $table ) . '"';
  				$row = $this->fetch( $this->query( $sql ) );

				return $row['a'] > 0;
				
			}
			
			
			//Verifica se um determinado campo existe na tabela
			function fieldExist( $table, $field ){
            
            if( empty( $table ) || empty( $field ) ){
               throw( new Exception(sprintf(DB_CONNECTION_INVALID_PARAMETER, 'FIELDEXIST')) );
            }

            $this->checkConnection();

				$sql = 'select count(*) as a from information_schema.COLUMNS where TABLE_SCHEMA = "' . $this->escape( self::$database ) . '" and TABLE_NAME="' . $this->escape( $table ) . '" and COLUMN_NAME="' . $this->escape( $field ) . '"';
				$row = $this->fetch( $this->query( $sql ) );
				
				return $row['a'] > 0;
				
			}
			

         //Busca um valor de um campo especifico
         function getValue( $table, $field, $compare, $max = 0 ){
            
            if( empty( $table ) || empty( $field ) || empty( $compare ) ){
               throw( new Exception(sprintf(DB_CONNECTION_INVALID_PARAMETER, 'GETVALUE')) );
            }

            $this->checkConnection();

            //$field: coluna ou Gojira_Raw; $table: nome simples; $compare: Gojira_Raw, array ou (legado) SQL cru
            if( $field instanceof Gojira_Raw ){
               $field = $this->renderRaw( $field );
            } elseif( !self::isIdentifier( $field ) ){
               self::legacySql( 'getValue-field', 'Connection::getValue(): a SQL expression as field', 'Connection::raw()' );
            }
            if( self::isStrict() && !self::isIdentifier( $table ) ){
               self::legacySql( 'getValue-table', 'Connection::getValue(): an invalid table name', 'a plain table name' );
            }

            $ret = array();
            $sql = 'select ' . $field . ' AS a from ' . $table . ' where ' . $this->sqlCondition( $compare, 'getValue' );
            
            //O limite aceita só inteiro, "n, m" ou "n OFFSET m"; o resto lança exceção
            if( $max > 0 || is_string( $max ) ){
               if( !( (is_int( $max ) && $max > 0) || (is_string( $max ) && preg_match( '/\A\s*\d+\s*((,\s*|\s+OFFSET\s+)\d+\s*)?\z/i', $max )) ) ){
                  throw( new Exception( sprintf(DB_CONNECTION_INVALID_PARAMETER, 'GETVALUE') ) );
               }
               $sql .= ' limit ' . $max;
            }
				
            
            //Se for trazer resultados
				if( $this->count( $sql ) ){
	            $query  = $this->query( $sql );
	            $result = $this->fetchAll( $query );
               foreach( $result as $a ) $ret[] = $a['a'];
				}

            return $ret;

         }

         
         //Verifica se tem alguma transaction rolando e da um rollback se precisar.
         function __shutdown_check(){
            if ($this->transaction_in_progress) {
               self::$connection->rollback();
            }
         }
      }


      //Falha de banco nas consultas parametrizadas (run/runAll/runGet).
      //A mensagem é sempre genérica; o detalhe fica no error_log.
      class ConnectionException extends Exception{

         function __construct( $message, $err_cod = 0 ){
            parent::__construct( $message, $err_cod );
         }

      }


      //Trecho de SQL intencional, escrito pelo programador. É a única forma de passar SQL cru
      //ao Model e à Connection no modo estrito. Cada ? fora de aspas recebe um valor de $params,
      //escapado: Gojira_Raw::sql('created > ?', [$data]). Nunca monte o $sql com dado do usuário.
      final class Gojira_Raw{

         private $sql;
         private $params;

         private function __construct( $sql, array $params ){
            $this->sql    = $sql;
            $this->params = array_values( $params );
         }

         static function sql( $sql, array $params = [] ){
            if( !is_string( $sql ) || trim( $sql ) === '' ){
               throw( new InvalidArgumentException( 'Gojira_Raw::sql() expects a non-empty SQL string' ) );
            }
            return new self( $sql, $params );
         }

         function getSql(){    return $this->sql; }
         function getParams(){ return $this->params; }

      }
