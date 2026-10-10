<?php

//Usuário inexistente e senha errada têm a mesma mensagem, para a resposta não
//indicar quais logins existem; os códigos (0x9001 e 0x9003) continuam distintos
//para log e auditoria. Um projeto pode definir as constantes antes do framework.
if( !defined( 'LGN_LOGIN_INVALID' ) ) define( 'LGN_LOGIN_INVALID', "Usuário ou senha inválidos");
if( !defined( 'LGN_PENDING' ) )       define( 'LGN_PENDING'      , "Conta com ativação pendente");
if( !defined( 'LGN_INATIVE' ) )       define( 'LGN_INATIVE'      , "Conta desativada");
if( !defined( 'LGN_PASS_INVALID' ) )  define( 'LGN_PASS_INVALID' , "Usuário ou senha inválidos");

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

   //Hasher de senha + contratos de usuário/guard. Ver docs/migration/auth-redesign-migration-guide.md
   require_once( __DIR__ . '/auth/passwordhasher.interface.php' );
   require_once( __DIR__ . '/auth/legacyhasher.class.php' );
   require_once( __DIR__ . '/auth/bcrypthasher.class.php' );
   require_once( __DIR__ . '/auth/authexception.class.php' );
   require_once( __DIR__ . '/auth/userprovider.interface.php' );
   require_once( __DIR__ . '/auth/tableuserprovider.class.php' );
   require_once( __DIR__ . '/auth/guard.interface.php' );
   require_once( __DIR__ . '/auth/remembertokenstore.interface.php' );
   require_once( __DIR__ . '/auth/remembertokenrecord.class.php' );
   require_once( __DIR__ . '/auth/tableremembertokenstore.class.php' );
   require_once( __DIR__ . '/auth/requestscheme.class.php' );
   require_once( __DIR__ . '/auth/sessionguard.class.php' );
   require_once( __DIR__ . '/session.class.php' );

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
         private $userProviderObj;    //Gojira_UserProvider (lazy)
         private $userProviderCustom = false;  //true quando o projeto injetou um provider próprio
         private static $cookieModeWarned = false;  //aviso de setCookie(true) uma vez por requisição
         private $rememberStore;            //Gojira_RememberTokenStore|null (revogação no logout e na troca de senha)
         private $rememberCookies = [];     //tipo => nome do cookie de remember-me
         private static $dummyHashes = [];  //classe do hasher injetado => hash fictício
         private static $missingStoreNoticed = false;  //aviso de store ausente uma vez por requisição

         //Hashes fictícios para o login de usuário inexistente, um por formato de
         //gravação (bcrypt com o custo padrão e legado). Não correspondem a senha
         //conhecida; servem só para gastar o mesmo tempo de uma verificação real.
         const DUMMY_BCRYPT_HASH = '$2y$12$xtMRsaNmEQIP6aRLlg4Gne4BP1CohPJQzYKhxnBKvcRsG8y2EpNCO';
         const DUMMY_LEGACY_HASH = '6c7bad6054b748bb9f5db6b05263440b5afc0554709c5f799e5eaaf1089996d75b910c6e0602a7bf33537840b88980a5b5f4d0762ce55d8d44fad38c761e27f6';
         
			/***   Construtores ***/
			function __construct(){
				
				//Com GOJIRA_NO_SESSION_AUTOSTART não abre a sessão aqui (ver Gojira_Session).
				Gojira_Session::autostart();
				
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

            //Descarta o provider padrão (lazy) para ele reconstruir com as novas tabelas.
            //Um provider injetado via setUserProvider() é preservado.
            if( !$this->userProviderCustom ) $this->userProviderObj = null;

            $this->configured = true;

			}



			//Retorna o UserProvider ativo (lazy: cria o padrão na 1ª chamada).
			private function userProvider(): Gojira_UserProvider {

				if( !( $this->userProviderObj instanceof Gojira_UserProvider ) ){
					$this->userProviderObj = new Gojira_TableUserProvider( $this->connection, $this->userTable, $this->typeTable, $this->useEmail );
				}

				return $this->userProviderObj;
			}

			//Expõe o provider ativo (usado pelo Gojira_SessionGuard e por quem quiser inspecioná-lo).
			public function getUserProvider(): Gojira_UserProvider {
				return $this->userProvider();
			}

			//Permite o projeto trocar como os usuários são localizados, sem tocar no core.
			//Ver docs/migration/auth-redesign-migration-guide.md.
			public function setUserProvider( Gojira_UserProvider $provider ){
				$this->userProviderObj    = $provider;
				$this->userProviderCustom = true;
			}

			//Fachada simplificada para um papel/silo de sessão. Ver docs/migration/auth-redesign-migration-guide.md.
			//$rememberStore é opcional (Fase 4 - remember-me); sem ele, Guard::attempt()
			//com $remember=true vira no-op e loginByRemember() sempre retorna null.
			//Com um store, ele e o nome do cookie ficam registrados no Login, que passa a
			//revogar o remember-me no logout() e todos os tokens do usuário no changePass().
			//Sem store, usa o registrado em setRememberTokenStore(), se houver.
			public function guard( string $sessionType, $rememberStore = null, string $rememberCookie = '', int $rememberTtl = 2592000 ): Gojira_Guard {

				if( $rememberStore instanceof Gojira_RememberTokenStore ){
					$this->rememberStore = $rememberStore;
				} else {
					$rememberStore = $this->rememberStore;
				}

				if( $rememberCookie !== '' ){
					$this->rememberCookies[ strtoupper( $sessionType ) ] = $rememberCookie;
				}

				return new Gojira_SessionGuard( $this, $sessionType, $rememberStore, $rememberCookie, $rememberTtl );
			}

			//Registra o store de remember-me sem criar um guard (ex.: na requisição de
			//troca de senha), para o changePass() e o logout() revogarem os tokens.
			public function setRememberTokenStore( Gojira_RememberTokenStore $store ){
				$this->rememberStore = $store;
			}

			public function getRememberTokenStore(){
				return $this->rememberStore;
			}

			//Nome do cookie de remember-me de um tipo (padrão: remember_<tipo>).
			public function rememberCookieName( $type ){
				$key = strtoupper( trim( (string)$type ) );
				return isset( $this->rememberCookies[ $key ] ) ? $this->rememberCookies[ $key ] : 'remember_' . strtolower( trim( (string)$type ) );
			}

			//Há cookie de remember-me na requisição? Com $type, só os desse(s) tipo(s);
			//sem, qualquer cookie remember_* ou com nome registrado. Indica que o
			//projeto usa remember-me.
			private function hasRememberCookie( $type = null ){

				if( $type !== null ){
					foreach( explode( ',', (string)$type ) as $t ){
						if( trim( $t ) !== '' && isset( $_COOKIE[ $this->rememberCookieName( $t ) ] ) ) return true;
					}
					return false;
				}

				foreach( array_keys( $_COOKIE ) as $name ){
					if( strncmp( (string)$name, 'remember_', 9 ) === 0 || in_array( $name, $this->rememberCookies, true ) ) return true;
				}
				return false;
			}

			//Avisa (uma vez por requisição) que o remember-me não pôde ser revogado
			//porque o store não está registrado neste Login.
			private function noticeMissingStore( $where ){

				if( self::$missingStoreNoticed ) return;
				self::$missingStoreNoticed = true;

				trigger_error( 'Login::' . $where . '(): há cookie de remember-me, mas nenhum Gojira_RememberTokenStore registrado; os tokens não foram revogados. Registre o store com Login::setRememberTokenStore() ou Login::guard().', E_USER_NOTICE );
			}

			//Revoga o token de remember-me do cookie atual de cada tipo e apaga o cookie.
			private function revokeRememberCookies( $type ){

				if( !( $this->rememberStore instanceof Gojira_RememberTokenStore ) ){
					if( $this->hasRememberCookie( $type ) ) $this->noticeMissingStore( 'logout' );
					return;
				}

				foreach( explode( ',', (string)$type ) as $t ){

					if( trim( $t ) === '' ) continue;

					$name = $this->rememberCookieName( $t );
					if( !isset( $_COOKIE[ $name ] ) ) continue;

					$parts = explode( ':', (string)$_COOKIE[ $name ], 2 );
					if( count( $parts ) === 2 && $parts[0] !== '' && strlen( $parts[0] ) <= 128 ){
						$this->rememberStore->revoke( $parts[0] );
					}

					$this->sendCookie( $name, '', [ 'expires' => time() - 3600, 'path' => '/', 'secure' => $this->isHttps(), 'httponly' => true, 'samesite' => 'Lax' ] );
					unset( $_COOKIE[ $name ] );
				}
			}
			
			
			//Executa o login.
			//$pwd_hash = true indica que $pwd JÁ é o hash final (ex: login por chave de
			//acesso) e deve ser comparado diretamente, sem re-hash. NUNCA derive esse
			//flag de entrada do usuário: ele é sempre definido por código do servidor.
			function login( $login, $pwd, $type, $pwd_hash = false ){
            
            if( !$this->configured ) $this->configure( $this->userTable, $this->typeTable);

				//Localiza o usuário via UserProvider (pluggável - ver setUserProvider()).
				//A query real (parametrizada) fica no provider; aqui só orquestramos.
				$types = array_filter( array_map( 'trim', explode( ',', (string)$type ) ), function( $t ){ return $t !== ''; } );
				$user  = $this->userProvider()->findByLogin( $login, $types );

				if( $user === null ){
					//Usuário inexistente faz uma verificação contra um hash fictício no formato
					//de gravação configurado, para o tempo de resposta se parecer com o de uma
					//senha errada e não revelar quais logins existem.
					if( $pwd_hash ){
						hash_equals( self::DUMMY_LEGACY_HASH, (string)$pwd );
					} else {
						$this->dummyVerify( (string)$pwd );
					}
					throw( new Gojira_AuthException( LGN_LOGIN_INVALID, 0x9001, Gojira_AuthException::INVALID_USER ) );
				}

				//Verifica se o password está correto, antes do status: a situação da conta
				//(pendente, inativa) só é informada a quem acertou a senha.
				//$pwd_hash=true: $pwd já é o hash final (ex.: login por chave) -> compara direto.
				//Caso contrário, valida via hasher (aceita hash moderno OU legado).
				if( $pwd_hash ){
					if( ! hash_equals( $user->getAuthPasswordHash(), (string)$pwd ) ){
						throw( new Gojira_AuthException( LGN_PASS_INVALID, 0x9003, Gojira_AuthException::INVALID_PASSWORD ) );
					}
				} else {
					//Durante a migração, o hash guardado pode estar num formato diferente do de
					//gravação (ex.: legado com a flag ligada). O usuário existente gasta então
					//também a verificação fictícia, e o tempo não separa existente de inexistente.
					if( $this->storedFormatDiffers( $user->getAuthPasswordHash() ) ){
						$this->dummyVerify( (string)$pwd );
					}

					if( ! $this->hasher()->verify( (string)$pwd, $user->getAuthPasswordHash() ) ){
						throw( new Gojira_AuthException( LGN_PASS_INVALID, 0x9003, Gojira_AuthException::INVALID_PASSWORD ) );
					}
				}

				//Verifica se o usuário está ativo. Com o provider padrão, distinguimos
				//PENDING de INACTIVE (mensagens legadas); um provider customizado só
				//garante isActive(), e nesse caso cai na mensagem genérica de inativo.
				if( ! $user->isActive() ){

					if( $this->userProvider() instanceof Gojira_TableUserProvider && method_exists( $user, 'getRawStatus' ) ){

						$statusList = $this->userProvider()->getStatusList();
						$raw        = $user->getRawStatus();

						if( (string)$raw === (string)$statusList['PENDING'] ){
							throw( new Gojira_AuthException( LGN_PENDING, 0x9004, Gojira_AuthException::PENDING ) );
						}
					}

					throw( new Gojira_AuthException( LGN_INATIVE, 0x9002, Gojira_AuthException::INACTIVE ) );
				}

				//Migração de hash legado/desatualizado (opt-in, ver LGN_PASSWORD_REHASH)
				if( ! $pwd_hash && $this->rehash && $this->hasher()->needsRehash( $user->getAuthPasswordHash() ) ){
					$this->rehashPassword( $user->getAuthId(), $user->getAuthPasswordHash(), (string)$pwd );
				}

				$row = $this->userToRow( $user );
				$this->establishSession( $row );

				return $row['code'];

			}



			//Abre a sessão para um usuário JÁ AUTENTICADO por outro meio (ex.: token de
			//remember-me verificado pelo Gojira_SessionGuard). Não verifica senha - quem
			//chama é responsável por já ter validado a identidade. Ainda assim, confirma
			//isActive() (uma conta desativada depois do cookie ter sido emitido não deve
			//conseguir abrir sessão). Ver docs/migration/auth-redesign-migration-guide.md.
			public function loginAs( Gojira_Authenticatable $user ){

				if( ! $user->isActive() ){
					throw( new Gojira_AuthException( LGN_INATIVE, 0x9002, Gojira_AuthException::INACTIVE ) );
				}

				$row = $this->userToRow( $user );
				$this->establishSession( $row );

				return $row['code'];
			}



			//Reconstrói o array $row no formato legado a partir do Authenticatable.
			private function userToRow( Gojira_Authenticatable $user ): array {

				return [
					'type'     => $user->getRoles()[0] ?? '',
					'type_cod' => $user->getAuthType(),
					'code'     => $user->getAuthId(),
					'login'    => $user->getAuthLogin(),
					'email'    => $user->getAuthEmail(),
					'name'     => method_exists( $user, 'getName' ) ? $user->getName() : '',
				];
			}



			//Detecta HTTPS, para não marcar cookies como Secure numa conexão HTTP (o
			//navegador simplesmente descartaria o cookie). Ver docs/migration/auth-redesign-migration-guide.md.
			//HTTPS direto, X-Forwarded-Proto de proxy confiável (GOJIRA_TRUSTED_PROXIES) ou
			//Secure forçado (GOJIRA_FORCE_SECURE_COOKIES). Ver Gojira_RequestScheme.
			private function isHttps(): bool {
				return Gojira_RequestScheme::isHttps();
			}

			//Grava um cookie de identidade com HttpOnly (JS não lê), Secure quando
			//HTTPS e SameSite=Lax.
			private function setIdentityCookie( string $name, $value, int $expires ){
				$this->sendCookie( $name, (string)$value, [
					'expires'  => $expires,
					'path'     => '/',
					'secure'   => $this->isHttps(),
					'httponly' => true,
					'samesite' => 'Lax',
				] );
			}

			//Único ponto que envia cookies de identidade (subclasses podem trocar,
			//por exemplo para testes).
			protected function sendCookie( string $name, string $value, array $options ): bool {
				return setcookie( $name, $value, $options );
			}

			//Troca o id da sessão mantendo os dados (session fixation). Só age com a
			//sessão ativa. Se já houve saída, o PHP não consegue enviar o cookie novo:
			//o id não é trocado, e o motivo vai para o log e para um E_USER_WARNING.
			protected function regenerateSession(): bool {

				if( session_status() !== PHP_SESSION_ACTIVE ) return false;

				$file = '';
				$line = 0;
				if( $this->headersAlreadySent( $file, $line ) ){
					$msg = '[GOJIRA] Login: id de sessão não trocado porque já houve saída antes do login/logout'
					     . ( $file !== '' ? ' (' . $file . ':' . $line . ')' : '' )
					     . '. Chame login() e logout() antes de enviar conteúdo.';
					error_log( $msg );
					trigger_error( $msg, E_USER_WARNING );
					return false;
				}

				return session_regenerate_id( true );
			}

			//headers_sent() isolado para subclasses (ex.: testes).
			protected function headersAlreadySent( &$file = '', &$line = 0 ): bool {
				return headers_sent( $file, $line );
			}

			//Grava a sessão/cookie de identidade (mesmo formato de sempre - ver
			//isLogged()/getLogged()/logout()). Extraído de login() para ser reutilizado
			//por loginAs() (remember-me) sem duplicar a lógica.
			private function establishSession( array $row ){

            if( $this->useCookie ){
               $exp = time() + $this->cookieTime;

               $this->setIdentityCookie( $this->cookieName( $row['type'], "Logged" ) , true            , $exp );
               $this->setIdentityCookie( $this->cookieName( $row['type'], "Cod" )    , $row['code']    , $exp );
               $this->setIdentityCookie( $this->cookieName( $row['type'], "Login" )  , $row['login']   , $exp );
               $this->setIdentityCookie( $this->cookieName( $row['type'], "Email" )  , $row['email']   , $exp );
               $this->setIdentityCookie( $this->cookieName( $row['type'], "Name" )   , $row['name']    , $exp );
               $this->setIdentityCookie( $this->cookieName( $row['type'], "Type" )   , $row['type']    , $exp );
               $this->setIdentityCookie( $this->cookieName( $row['type'], "TypeCod" ), $row['type_cod'], $exp );

            } else {

               //Previne session fixation: troca o id da sessão ao autenticar, mantendo
               //os dados. Ver docs/migration/auth-redesign-migration-guide.md.
               Gojira_Session::ensureStarted();   //sessão sob demanda (GOJIRA_NO_SESSION_AUTOSTART)
               $this->regenerateSession();

               $_SESSION[ $row['type'] . "Logged"]  = true;
               $_SESSION[ $row['type'] . "Cod"]     = $row['code'];
               $_SESSION[ $row['type'] . "Login"]   = $row['login'];
               $_SESSION[ $row['type'] . "Email"]   = $row['email'];
               $_SESSION[ $row['type'] . "Name"]    = $row['name'];
               $_SESSION[ $row['type'] . "Type"]    = $row['type'];
               $_SESSION[ $row['type'] . "TypeCod"] = $row['type_cod'];
				}
			}
			
			
			
			//Remove a sessão do usuario logado
			function logout( $type ){

				//Revoga o remember-me do cookie atual (quando há store registrado), mesmo
				//que a sessão já tenha expirado: o cookie não pode reabrir a sessão.
				$this->revokeRememberCookies( $type );

				if( $this->isLogged( $type ) ){

					//Loga a saida do sistema
					$usr = $this->getLogged( 'Cod', $type );
					
					$type   = $this->getType( $type, true );
					$type   = explode( ',', strtoupper( $type ) );
               $fields = ['Logged', 'Cod', 'Name', 'Login', 'Email', 'Type', 'Typecod'];
					//Desloga todos os tipos de usuários informados
					foreach( $type as $a ){
						
                  if( $this->useCookie ){

                     //Limpa com o MESMO path usado ao gravar ('/' - ver establishSession()).
                     //Sem isso o navegador pode não reconhecer como o mesmo cookie e não apagar.
                     $clear = ['expires' => time() - 3600, 'path' => '/', 'secure' => $this->isHttps(), 'httponly' => true, 'samesite' => 'Lax'];

                     foreach( $fields as $fld ){
                        $this->sendCookie( $this->cookieName( $a, $fld ), '', $clear );
                        unset( $_COOKIE[ $this->cookieName( $a, $fld ) ] );
                     }

                     //Para os sistemas que criam outras váriaveis de sessão para o usuário e não limpão manualmente no logoff
                     foreach( $_COOKIE as $i => $v ){
                        if( substr( $i, 0, strlen( $a ) ) === strtolower($a) ){
                           $this->sendCookie( $i, '', $clear );
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

					//Previne reuso do id de sessão após logout. Ver docs/migration/auth-redesign-migration-guide.md.
					$this->regenerateSession();

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
				
				if( !$this->useCookie ) Gojira_Session::resumeIfPresent();

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
            if( !$this->useCookie ) Gojira_Session::resumeIfPresent();
            
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

                        if( $this->cookieUserIsActive( $a, (string)$_COOKIE[ $this->cookieName( $a, 'Cod' ) ] ) ){
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
                        if( $this->connection->exist( $this->userTable, 'id', $_SESSION[ $a . 'Cod'], "type IN " . $typeCod . ' AND ' . $this->statusField . '=' . $this->statusList['ACTIVE'] ) ){
                           $n++;
                        }
                     }
                  }
               }
				}
				
		      return $n > 0;
		
		   }
		
			
			
			//Confere o id gravado no cookie de identidade: o usuário precisa existir, ser
			//do tipo e estar ativo, e o id do banco precisa ser igual ao do cookie. A
			//comparação exata evita que o MySQL converta "1x" ou "01" no id 1.
			private function cookieUserIsActive( $type, $cod ){

				if( $cod === '' || strlen( $cod ) > 64 ) return false;

				$typeCod = $this->getType( $type );
				if( $typeCod === '()' ) return false;   //tipo inexistente

				$row = $this->connection->runGet(
					'SELECT id FROM `' . $this->userTable . '` WHERE id = ? AND type IN ' . $typeCod .
					' AND `' . $this->statusField . '` = ? LIMIT 1',
					[ $cod, $this->statusList['ACTIVE'] ]
				);

				return $row !== null && (string)$row['id'] === $cod;
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

			//Verificação contra um hash fictício no formato de gravação configurado:
			//hasher injetado (hash gerado uma vez por processo), bcrypt com
			//LGN_PASSWORD_REHASH ligada ou legado com ela desligada. Usada quando o
			//usuário não existe, pelo mesmo caminho de uma verificação real.
			//true quando o hash guardado não está no formato de gravação configurado
			//(o mesmo formato do hash fictício do dummyVerify).
			private function storedFormatDiffers( $stored ){

				if( $this->hasherInjected || $this->rehash ){
					return $this->hasher()->needsRehash( (string)$stored );
				}

				return ! ( new Gojira_LegacyHasher() )->looksLegacy( (string)$stored );
			}

			private function dummyVerify( $plain ){

				if( $this->hasherInjected ){
					$key = get_class( $this->hasher() );
					if( !isset( self::$dummyHashes[ $key ] ) ){
						self::$dummyHashes[ $key ] = $this->hasher()->hash( bin2hex( random_bytes( 16 ) ) );
					}
					$dummy = self::$dummyHashes[ $key ];
				} else {
					$dummy = $this->rehash ? self::DUMMY_BCRYPT_HASH : self::DUMMY_LEGACY_HASH;
				}

				return $this->hasher()->verify( $plain, $dummy );
			}

			//Regrava o hash da senha do usuário com o hasher configurado, pelo UserProvider.
			//Só grava se o hash no banco ainda for o mesmo que foi verificado: outra
			//requisição que já trocou a senha não é sobrescrita. Uma falha aqui não
			//impede o login (a senha já foi validada); o motivo vai para o error_log.
			private function rehashPassword( $userId, $oldHash, $plain ){
				try {
					if( ! $this->userProvider()->updatePasswordHash( $userId, $this->hashPassword( $plain ), $oldHash ) ){
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
			
			
			
			//Gera uma senha aleatória com letras maiusculas, minusculas, numeros e caracteres especiais.
			//Usa random_int (gerador criptográfico), adequado para senha temporária.
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
		         $c = random_int( 1, 4 );
		         if( strpos( '  ' . $order, $c . '' ) <= 0 ) $order .= $c;
		      }
		
		      for( $i = 0; $i < 4; $i++ ){
		
		         switch( substr( $order, $i, 1 ) ){
		            case '1': 	//Numeros
		               for( $c = 0; $c < $nu ; $c++ ){
		                  $pwd .= chr(random_int( 48, 57 ));
		               }
		               break;
		
		            case '2':	//Caracteres minusculos
		               for( $c = 0; $c < $mi ; $c++ ){
		                  $pwd .= chr(random_int( 97, 122 ));
		               }
		               break;
		
		            case '3':	//Caracteres maiusculos
		               for( $c = 0; $c < $ma ; $c++ ){
		                  $pwd .= chr(random_int( 65, 90 ));
		               }
		               break;
		
		            case '4':	//Caracteres especiais
		               for( $c = 0; $c < $es ; $c++ ){
		                  $pwd .= substr( $special, random_int( 0, strlen( $special )-1 ), 1 );
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
			
			
			//Verifica se o usuário existe.
			//Nota: parametrizado nesta passagem (não fazia parte do escopo original da
			//Fase 2, mas era concatenação direta de entrada no SQL no mesmo arquivo).
			//Comportamento preservado tal qual estava, incluindo a assimetria de coluna
			//entre os dois ramos (type vs typecod) - não é uma decisão de design nova.
			function userExists( $type , $user ){

				$type = $this->getType( $type );  // mantém o fragmento SQL já escapado (ver getType)

				if( is_numeric( $user ) ){
					$sql = 'SELECT email AS email, id AS cod, COUNT(*) AS numUser FROM `' . $this->userTable . '` WHERE id = ? AND type in ' . $type;
					$ret = $this->connection->runGet( $sql, [ $user ] );
				} else {
					$sql = 'SELECT email AS email, id AS cod, COUNT(*) AS numUser FROM `' . $this->userTable . '` WHERE (login = ? OR email = ?) AND typecod IN ' . $type;
					$ret = $this->connection->runGet( $sql, [ $user, $user ] );
				}

				return [	"exists" => ($ret['numUser'] ? 1 : 0),
							"cod"		=> $ret['cod'],
							"email" 	=> $ret['email'] ];

			}
			
			
			//Altera a senha do usuário (hash moderno + query parametrizada).
			function changePass( $userId , $pass ){

				//Falha lança ConnectionException (subclasse de Exception), como o query() antigo.
				$cryptPass = $this->hashPassword( $pass );
				$ret = $this->connection->run(
					'UPDATE `' . $this->userTable . '` SET `password` = ? WHERE `id` = ? LIMIT 1',
					[ $cryptPass, $userId ]
				);

				//Senha nova encerra o remember-me em todos os dispositivos: um cookie
				//copiado antes da troca deixa de valer. Exige o store registrado
				//(guard() com store ou setRememberTokenStore()).
				if( $this->rememberStore instanceof Gojira_RememberTokenStore ){
					$this->rememberStore->revokeAllForUser( $userId );
				} elseif( $this->hasRememberCookie() ){
					$this->noticeMissingStore( 'changePass' );
				}

				return $ret;

         }
         
			
         //Seta a propriedade useCookie
         //Modo cookie: obsoleto, desligado por padrão. Para manter o usuário logado
         //entre visitas, use o remember-me do Gojira_SessionGuard. A 2.0 assina ou
         //remove este modo.
         function setCookie( $val, $cookieTime = ''){
            //Forçar valor lógico
            $this->useCookie  = ($val == true ? true : false);

            if( $this->useCookie && !self::$cookieModeWarned ){
               self::$cookieModeWarned = true;
               trigger_error( 'Login::setCookie(true) está obsoleto e será alterado na 2.0. Use Login::guard() com um Gojira_RememberTokenStore (remember-me).', E_USER_DEPRECATED );
            }

            if( is_numeric( $cookieTime ) )  $this->cookieTime = $cookieTime;
         }


         //Nome do cookie de um campo do usuário logado. Gravação (login), leitura
         //(isLogged, getLogged) e remoção (logout) usam o mesmo nome, em minúsculas.
         function cookieName( $type, $field ){
            return strtolower( $type . $field );
         }
		
		}

