<?php

define( 'LGN_LOGIN_INVALID', "Usuário inválido");
define( 'LGN_PENDING'      , "Conta com ativação pendente");
define( 'LGN_INATIVE'      , "Conta desativada");
define( 'LGN_PASS_INVALID' , "Senha inválida");

define( 'LGN_DEFAULT_USER_TABLE' , 'user' );
define( 'LGN_DEFAULT_TYPE_TABLE' , 'user_type' );
define( 'LGN_USE_EMAIL'          , true );
define( 'LGN_COOKIE_EXPIRE_TIME' , 1800);

//Formato de toda gravação de hash de senha (changePass, hashPassword, migração no login).
//A verificação aceita sempre os dois formatos (bcrypt e legado sha512+whirlpool).
//- false (padrão na 1.x): toda gravação usa o formato legado (Gojira_LegacyHasher)
//  e o login não regrava nada.
//- true: grava bcrypt e migra o hash legado/desatualizado no primeiro login válido.
//Para ligar, defina LGN_PASSWORD_REHASH como true antes de carregar o framework,
//ou chame Login::setPasswordRehash(true). Um hasher injetado com Login::setHasher
//tem precedência sobre a flag. Atenção: ligar é irreversível para todo usuário que
//trocar a senha ou for migrado (o hash bcrypt não volta ao legado). Na 2.0 o
//bcrypt passa a ser o padrão.
if( !defined( 'LGN_PASSWORD_REHASH' ) ) define( 'LGN_PASSWORD_REHASH', false );

   //Hasher de senha (contrato + implementações). Ver docs/specs/auth-redesign-spec.md
   require_once( __DIR__ . '/auth/passwordhasher.interface.php' );
   require_once( __DIR__ . '/auth/legacyhasher.class.php' );
   require_once( __DIR__ . '/auth/bcrypthasher.class.php' );

//TODO - Permitir USAR ou NÃO USAR USER_TYPE
//TODO - Permitir configurar a tabela para o USER_TYPE
//TODO - Permitir configurar os campos da tabela

		class Login extends GojiraCore{
			
			//Propriedades da classe
			private $connection;
			private $tools;
			private $id;
         
         private $configured  = false;
         private $userTable   = LGN_DEFAULT_USER_TABLE;
         private $typeTable   = LGN_DEFAULT_TYPE_TABLE;
			private $useEmail    = LGN_USE_EMAIL;
         private $statusList  = ['PENDING' => 0, 'INACTIVE' => 1, 'ACTIVE' => 2];
         private $statusField = 'status';
         private $useCookie   = false;
         private $cookieTime  = LGN_COOKIE_EXPIRE_TIME;
         private $hasher;     //Gojira_PasswordHasher de verificação (lazy)
         private $hasherInjected = false;   //true quando veio de setHasher
         private $rehash      = LGN_PASSWORD_REHASH;
         
			/***   Construtores ***/
			function __construct(){
				
				@session_start();
				
				$a = func_get_args();
				$i = func_num_args();
				
				if (method_exists($this,$f='__construct'.$i)) {
					call_user_func_array(array($this,$f),$a); 
				} else {
					throw( new Exception('Numero de parametros invalido') );
				}
			}
			
			//Constructor com 2 parametros	 
			function __construct2( $connection, $tools ){
				$this->connection = $connection;
            $this->tools      = $tools;
			}
			
			//Constructor com 4 parametros
			function __construct4( $connection, $tools, $userTable, $typeTable ){
				
				$this->connection = $connection;
				$this->tools      = $tools;
            
            $this->configure( $userTable, $typeTable);
			}

			
			
         function get_userTable(){ return $this->userTable; }
         function get_typeTable(){ return $this->typeTable; }
			function get_useEmail(){ return $this->useEmail; }

			function configure($userTable, $typeTable, $cookieTime = ''){

				$this->userTable = $userTable;
				$this->typeTable = $typeTable;

            //Se existe a coluna status
            if( !$this->connection->fieldExist( $userTable, $this->statusField ) ){
               $this->statusField = 'active';
               $this->statusList  = ['PENDING' => -1, 'INACTIVE' => 0, 'ACTIVE' => 1];
            }

            $this->cookieTime = (is_numeric( $cookieTime ) ? $cookieTime : $this->cookieTime);

            $this->configured = true;

			}
			
			
			//Executa o login.
			//$pwd_hash = true indica que $pwd JÁ é o hash final (ex: login por chave de
			//acesso) e deve ser comparado diretamente, sem re-hash. NUNCA derive esse
			//flag de entrada do usuário: ele é sempre definido por código do servidor.
			function login( $login, $pwd, $type, $pwd_hash = false ){
            
            if( !$this->configured ) $this->configure( $this->userTable, $this->typeTable);

				//exist() escapa o valor; o SQL montado abaixo usa a versão escapada.
				$rawLogin = $login;
				$login    = $this->connection->escape( $login );
				$type     = $this->getType( $type );

				//Verifica se o usuario é valido (pelo campo login e, se useEmail, pelo campo email)
				if( !$this->connection->exist( $this->userTable, "login", $rawLogin, Gojira_Raw::sql( "type in " . $type ) ) ){
					if( !$this->useEmail || !$this->connection->exist( $this->userTable, "email", $rawLogin, Gojira_Raw::sql( "type in " . $type ) ) )
								throw( new Exception( LGN_LOGIN_INVALID, 0x9001 ) );
				}
				
				
				//Monta a sentença SQL para verificar se o usuário existe
				$sql   = 'SELECT b.shortcode AS type, ' . 
                            'b.id AS type_cod, ' . 
									 'a.id AS code, ' .
				   				 'a.password AS pwd, ' .
									 'a.login AS login, ' .
									 'a.email AS email, ' .
									 'a.' . $this->statusField . ' AS status, ' .
							($this->connection->fieldExist( $this->userTable, 'name' ) ? 
									 'a.name AS name ' : '"" AS name ') .
                     ' FROM ' . $this->userTable . ' AS a ' . 
                        'INNER JOIN ' . $this->typeTable . ' AS b ON a.type = b.id ' .
										'where (a.login = "' . $login . '" ' . 
				  ($this->useEmail ? ' or a.email = "' . $login . '"' : '') .
				  					') and a.' . 'type in ' . $type . ' limit 1';
				
            
				$query = $this->connection->query( $sql );
				$row   = $this->connection->fetch( $query );

				//A verificação da senha (com migração de hash) acontece mais abaixo.

				//Verifica se o usuário está ativo
				if( $row[ 'status' ] == $this->statusList['PENDING'] ){
					throw( new Exception( LGN_PENDING, 0x9004 ) );
				}

				//Verifica se o usuário está ativo
				if( $row[ 'status' ] == $this->statusList['INACTIVE'] ){
					throw( new Exception( LGN_INATIVE, 0x9002 ) );
				}
				
				//Verifica se o password está correto.
				//$pwd_hash=true: $pwd já é o hash final (ex.: login por chave) -> compara direto.
				//Caso contrário, valida via hasher (aceita hash moderno OU legado) e, se o hash
				//armazenado estiver legado/desatualizado, migra de forma transparente.
				if( $pwd_hash ){
					if( ! hash_equals( (string)$row['pwd'], (string)$pwd ) ){
						throw( new Exception( LGN_PASS_INVALID, 0x9003 ) );
					}
				} else {
					if( ! $this->hasher()->verify( (string)$pwd, (string)$row['pwd'] ) ){
						throw( new Exception( LGN_PASS_INVALID, 0x9003 ) );
					}

					//Migração de hash legado/desatualizado (opt-in, ver LGN_PASSWORD_REHASH)
					if( $this->rehash && $this->hasher()->needsRehash( (string)$row['pwd'] ) ){
						$this->rehashPassword( $row['code'], (string)$row['pwd'], (string)$pwd );
					}
				}
				
				
				//Salva a sessão
            if( $this->useCookie ){
               setcookie( $this->cookieName( $row['type'], "Logged" ) , true, time() + $this->cookieTime, '/');
               setcookie( $this->cookieName( $row['type'], "Cod" )    , $row['code'], time() + $this->cookieTime, '/');
               setcookie( $this->cookieName( $row['type'], "Login" )  , $row['login'], time() + $this->cookieTime, '/');
               setcookie( $this->cookieName( $row['type'], "Email" )  , $row['email'], time() + $this->cookieTime, '/');
               setcookie( $this->cookieName( $row['type'], "Name" )   , $row['name'], time() + $this->cookieTime, '/');
               setcookie( $this->cookieName( $row['type'], "Type" )   , $row['type'], time() + $this->cookieTime, '/');
               setcookie( $this->cookieName( $row['type'], "TypeCod" ), $row['type_cod'], time() + $this->cookieTime, '/');

            } else {
               $_SESSION[ $row['type'] . "Logged"]  = true;
               $_SESSION[ $row['type'] . "Cod"]     = $row['code'];
               $_SESSION[ $row['type'] . "Login"]   = $row['login'];
               $_SESSION[ $row['type'] . "Email"]   = $row['email'];
               $_SESSION[ $row['type'] . "Name"]    = $row['name'];
               $_SESSION[ $row['type'] . "Type"]    = $row['type'];
               $_SESSION[ $row['type'] . "TypeCod"] = $row['type_cod'];
				}
            
				return $row['code'];
				
			}
			
			
			
			//Remove a sessão do usuario logado
			function logout( $type ){

				if( $this->isLogged( $type ) ){

					//Loga a saida do sistema
					$usr = $this->getLogged( 'Cod', $type );
					
					$type   = $this->getType( $type, true );
					$type   = explode( ',', strtoupper( $type ) );
               $fields = ['Logged', 'Cod', 'Name', 'Login', 'Email', 'Type', 'Typecod'];
					//Desloga todos os tipos de usuários informados
					foreach( $type as $a ){
						
                  if( $this->useCookie ){
                     
                     foreach( $fields as $fld ){
                        setcookie($this->cookieName( $a, $fld ), 0, 2);
                        unset( $_COOKIE[ $this->cookieName( $a, $fld ) ] );
                     }
                     
                     //Para os sistemas que criam outras váriaveis de sessão para o usuário e não limpão manualmente no logoff
                     foreach( $_COOKIE as $i => $v ){
                        if( substr( $i, 0, strlen( $a ) ) === strtolower($a) ){
                           setcookie($i, 0, -60, '/');
                           unset($_COOKIE[ $i ]);
                        }
                     }
                  } else {
                     foreach( $fields as $fld ){
                        unset( $_SESSION[ $a . $fld] );
                     }
                     
                     //Para os sistemas que criam outras váriaveis de sessão para o usuário e não limpão manualmente no logoff
                     foreach( $_SESSION as $i => $v ){
                        if( substr( $i, 0, strlen( $a ) ) === $a ) unset($_SESSION[ $i ]);
                     }
                  }
					}
					
				} else {
					
					return false;
					
				}
				
				return false;
				
			}
			
			
			//Busca qual o tipo de usuário logado.
			//Retorno pode ser 1 = SHORTCODE, 2 - ID, 3 - NAME
			function loggedType( $type, $return = 1 ){
            
				//Converte o tipo para o formato certo ou busca ele da db caso não seja informado
				if( empty( $types ) ){
					
					$query = $this->connection->query( 'SELECT shortcode FROM ' . $this->typeTable );
					$type = array();
					while( $row = $this->connection->fetch( $query ) ){
						$type[] = $row['shortcode'];
					}
					
				} else {
					
					$type = $this->getType( $type, true );
					
				}
				
				//Verifica qual dos tipos estão conectado, o primeiro a ser localizado será o usado
				foreach( $type as $a ){
					if( $this->isLogged( $a ) ){
						if( $return == 1 ){
							return strtoupper( $a );
						} else {
							$sql = 'SELECT ' . ($return == 2 ? 'id AS a' : 'name AS a' ) . ' FROM ' . $this->typeTable . ' WHERE shortcode = "' . $a . '"';
							$row = $this->connection->fetch( $this->connection->query( $sql ) );
							return $row[ 'a' ];
						}
					}
				}
				
				return false;
			
			}
			
			
			//Retorna um campo especifico, independente de quem estiver logado
			function getLogged( $field, $type = ''){
				
				if( empty( $type ) ){
					
					$query = $this->connection->query( 'SELECT shortcode from ' . $this->typeTable );
					$type = [];
					while( $row = $this->connection->fetch( $query ) ){
						$type[] = $row['shortcode'];
					}
					
				} else {
					$type = explode( ',', $type );
				}
				
				/*$field    = strtolower( $field );
				$field[0] = strtoupper( $field[0] );*/
				
				foreach( $type as $a ){
               if( $this->useCookie ){
                  if( isset( $_COOKIE[ $this->cookieName( $a, $field ) ] ) &&
                     !empty( $_COOKIE[ $this->cookieName( $a, $field ) ] ) ){
                     return $_COOKIE[ $this->cookieName( $a, $field ) ];
                  }
               } else {
                  if( isset( $_SESSION[ strtoupper( $a ) . $field ] ) &&
                     !empty( $_SESSION[ strtoupper( $a ) . $field ] ) ){
                     return $_SESSION[ strtoupper( $a ) . $field ];
                  }
               }
				}
				
				return '';
			}
         
         
			//Verifica se o usuario está logado
		   function isLogged( $type ){
            
            if( !$this->configured ) $this->configure( $this->userTable, $this->typeTable);
            
				$type = $this->getType( $type, true );
				$type = explode( ',', strtoupper( $type ) );
				$n    = 0;
				
				foreach( $type as $a ){
               if( $this->useCookie ){
                  $a = strtolower( $a );
                  if( isset( $_COOKIE[ $this->cookieName( $a, 'Logged' ) ] ) &&
                      isset( $_COOKIE[ $this->cookieName( $a, 'Cod' ) ] ) &&
                      isset( $_COOKIE[ $this->cookieName( $a, 'Name' ) ] ) &&
                      isset( $_COOKIE[ $this->cookieName( $a, 'Login' ) ] ) &&
                      isset( $_COOKIE[ $this->cookieName( $a, 'Email' ) ] ) ){

                     if( $_COOKIE[ $this->cookieName( $a, 'Logged' ) ] == true &&
                        !empty( $_COOKIE[ $this->cookieName( $a, 'Cod' ) ] ) &&
                        !empty( $_COOKIE[ $this->cookieName( $a, 'Login' ) ] ) &&
                        !empty( $_COOKIE[ $this->cookieName( $a, 'Email' ) ] ) ){

                        $typeCod = $this->getType( $a );
                        $cod     = $_COOKIE[ $this->cookieName( $a, 'Cod' ) ];   //exist() escapa o valor

                        if( $this->connection->exist( $this->userTable, 'id', $cod, Gojira_Raw::sql( "type IN " . $typeCod . ' AND ' . $this->statusField . '=' . $this->statusList['ACTIVE'] ) ) ){
                           $n++;
                        }
                     }
                  }
               } else {
                  if( isset( $_SESSION[ $a . 'Logged'] ) &&
                      isset( $_SESSION[ $a . 'Cod'] ) &&
                      isset( $_SESSION[ $a . 'Name'] ) &&
                      isset( $_SESSION[ $a . 'Login'] ) &&
                      isset( $_SESSION[ $a . 'Email'] ) ){

                     if( $_SESSION[ $a . 'Logged'] == true &&
                        !empty( $_SESSION[ $a . 'Cod'] ) &&
                        !empty( $_SESSION[ $a . 'Login'] ) &&
                        !empty( $_SESSION[ $a . 'Email'] ) ){
                           
                        $typeCod = $this->getType( $a );
                        if( $this->connection->exist( $this->userTable, 'id', $_SESSION[ $a . 'Cod'], Gojira_Raw::sql( "type IN " . $typeCod . ' AND ' . $this->statusField . '=' . $this->statusList['ACTIVE'] ) ) ){
                           $n++;
                        }
                     }
                  }
               }
				}
				
		      return $n > 0;
		
		   }
		
			
			
			//Hasher de verificação (lazy). Padrão: bcrypt com fallback legado, que
			//aceita os dois formatos independentemente de LGN_PASSWORD_REHASH.
			private function hasher(){
				if( !( $this->hasher instanceof Gojira_PasswordHasher ) ){
					$this->hasher = new Gojira_BcryptHasher( new Gojira_LegacyHasher() );
				}
				return $this->hasher;
			}

			//Hasher de gravação. Único ponto que decide o formato de um hash novo:
			//hasher injetado > LGN_PASSWORD_REHASH ligada (bcrypt) > legado.
			private function writeHasher(){
				if( $this->hasherInjected || $this->rehash ){
					return $this->hasher();
				}
				return new Gojira_LegacyHasher();
			}

			//Permite o projeto injetar um hasher próprio (ex.: argon2 com opções).
			//Ele passa a ser usado na verificação e em toda gravação, com precedência
			//sobre LGN_PASSWORD_REHASH.
			public function setHasher( Gojira_PasswordHasher $hasher ){
				$this->hasher         = $hasher;
				$this->hasherInjected = true;
			}

			//Gera o hash de uma senha no formato de gravação atual. Projetos devem usar
			//este método no cadastro de usuário em vez de toPassword().
			public function hashPassword( $plain ){
				return $this->writeHasher()->hash( (string)$plain );
			}

			//Liga ou desliga a regravação do hash no login (padrão: LGN_PASSWORD_REHASH).
			public function setPasswordRehash( $enabled ){
				$this->rehash = ( $enabled == true );
			}

			//Regrava o hash da senha do usuário com o hasher configurado.
			//Só grava se o hash no banco ainda for o mesmo que foi verificado: outra
			//requisição que já trocou a senha não é sobrescrita. Uma falha aqui não
			//impede o login (a senha já foi validada); o motivo vai para o error_log.
			private function rehashPassword( $userId, $oldHash, $plain ){
				try {
					$this->connection->run(
						'UPDATE `' . $this->userTable . '` SET `password` = ? WHERE `id` = ? AND `password` = ? LIMIT 1',
						[ $this->hashPassword( $plain ), $userId, $oldHash ]
					);
					if( $this->connection->affectedRows() !== 1 ){
						error_log( '[GOJIRA] Login: hash da senha do usuário ' . $userId . ' não regravado (alterado por outra requisição)' );
						return false;
					}
					return true;
				} catch( Throwable $e ){
					error_log( '[GOJIRA] Login: falha ao regravar o hash da senha do usuário ' . $userId . ': ' . get_class( $e ) . ' - ' . $e->getMessage() );
					return false;
				}
			}



			//Criptografa a senha (ESQUEMA LEGADO - mantido por compatibilidade).
			//Novos hashes devem vir de hashPassword(); este método será descontinuado.
		   function toPassword( $val ){
				
		      $n = 3;
		
		      for( $c = 0; $c < $n; $c++ ){
					$val = hash('sha512'   , $val, false );
					$val = hash('whirlpool', $val, false );
		      }
				
		      return $val;
		
		   }
			
			
			
			//Gera uma senha aleatória com letras maiusculas, minusculas, numeros e caracteres especiais
		   function getPassword( $n ){
		
		      $pwd     = '';
		      $special = '@#$%&+';
		      $order   = '';
		
		      //Define quantos caracteres vão ter na senha
		      if( $n > 4 ){
					
		         $ma = (int)($n * 0.25);
		         $mi = (int)($n * 0.25);
		         $es = (int)($n * 0.25);
		         $nu = (int)($n * 0.25);
		
		         if( ($ma + $mi + $es + $nu) < $n){
		            $mi += ($n - ($ma + $mi + $es + $nu));
		         }
					
		      } else {
					
		         $ma = 0;
		         $mi = $n;
		         $es = 0;
		         $nu = 0;
					
		      }
				
				
		      //Define a ordem que cada parte da senha vira
		      while( strlen( $order ) < 4 ){
		         $c = rand( 1, 4 );
		         if( strpos( '  ' . $order, $c . '' ) <= 0 ) $order .= $c;
		      }
		
		      for( $i = 0; $i < 4; $i++ ){
		
		         switch( substr( $order, $i, 1 ) ){
		            case '1': 	//Numeros
		               for( $c = 0; $c < $nu ; $c++ ){
		                  $pwd .= chr(rand( 48, 57 ));
		               }
		               break;
		
		            case '2':	//Caracteres minusculos
		               for( $c = 0; $c < $mi ; $c++ ){
		                  $pwd .= chr(rand( 97, 122 ));
		               }
		               break;
		
		            case '3':	//Caracteres maiusculos
		               for( $c = 0; $c < $ma ; $c++ ){
		                  $pwd .= chr(rand( 65, 90 ));
		               }
		               break;
		
		            case '4':	//Caracteres especiais
		               for( $c = 0; $c < $es ; $c++ ){
		                  $pwd .= substr( $special, rand( 0, mb_strlen( $special, 'UTF-8' )-1 ), 1 );
		               }
		               break;
		         }
		      }
		      return $pwd;
		   }
			
			
			
			//Tenta converter o nome do tipo para o código do tipo
			function getType( $type, $useSmall = false ){
				
				$ret   = '';
				$table = $this->typeTable;
				$where = '';
				
				if( $this->connection->tableExist( $table ) ){
					
					$type  = explode(',',$type);
					foreach( $type as $a ){
						$a      = $this->connection->escape( $a );
						$where .= (empty( $where ) ? '' : ',') . '"' . $a . '"';
					}
					
					$sql   = 'SELECT id AS cod, shortcode AS small FROM ' . $table .
									' WHERE id IN (' . $where . ') OR shortcode IN (' . $where . ') OR name IN (' . $where . ')';
					
					$query = $this->connection->query( $sql );
					while( $row = $this->connection->fetch( $query ) ){
						
						$ret .= (empty( $ret ) ? '' : ',');
						
						if( $useSmall ){
							$ret .= trim($row["small"]);
						} else {
							$ret .= '"' . $row["cod"] . '"';
						}
						
					}
					
					if( ! $useSmall ) $ret = '(' . $ret . ')';
					
				} else {
					
					if( $useSmall ){
						$ret = $type;
					} else {
						$ret = '("' . $this->connection->escape( $type ) . '")';
					}
				}
				
				return $ret;
			}
			
			
			//Verifica se o usuário existe
			function userExists( $type , $user ){
				
				$type = $this->getType( $type );
				if(is_numeric($user)){
					$sql 	= "SELECT email AS email, id AS cod, COUNT(*) AS numUser FROM " . $this->userTable . " WHERE id = '" . $user . "' AND type in " . $type;
				}else{
					$sql 	= "SELECT email AS email, id AS cod, COUNT(*) AS numUser FROM " . $this->userTable . " WHERE (login = '" . $user . "' OR email = '" . $user . "') AND typecod IN " . $type;
				}
				$ret 	= $this->connection->fetch($this->connection->query($sql));
				return [	"exists" => ($ret['numUser'] ? 1 : 0),
							"cod"		=> $ret['cod'],
							"email" 	=> $ret['email'] ];
				
			}
			
			
			//Altera a senha do usuário (hash moderno + query parametrizada).
			function changePass( $userId , $pass ){

				//Falha lança ConnectionException (subclasse de Exception), como o query() antigo.
				$cryptPass = $this->hashPassword( $pass );
				return $this->connection->run(
					'UPDATE `' . $this->userTable . '` SET `password` = ? WHERE `id` = ? LIMIT 1',
					[ $cryptPass, $userId ]
				);

         }
         
			
         //Seta a propriedade useCookie
         function setCookie( $val, $cookieTime = ''){
            //Forçar valor lógico
            $this->useCookie  = ($val == true ? true : false);

            if( is_numeric( $cookieTime ) )  $this->cookieTime = $cookieTime;
         }


         //Nome do cookie de um campo do usuário logado. Gravação (login), leitura
         //(isLogged, getLogged) e remoção (logout) usam o mesmo nome, em minúsculas.
         function cookieName( $type, $field ){
            return strtolower( $type . $field );
         }
		
		}

