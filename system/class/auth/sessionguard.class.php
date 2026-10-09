<?php
/*
   Gojira_SessionGuard - Implementação padrão de Gojira_Guard.

   Delega toda a mecânica de sessão/cookie de IDENTIDADE para uma instância de
   Login já existente (mesmas chaves de sessão, mesma configuração) - zero
   duplicação de estado e zero risco de regressão: Login::isLogged()/getLogged()
   de um tipo continuam enxergando uma sessão aberta via Guard, e vice-versa.

   Remember-me (Fase 4, opcional): se um Gojira_RememberTokenStore for injetado,
   attempt(..., $remember=true) emite um cookie de remember-me (selector:validator,
   HttpOnly/Secure/SameSite=Lax) e loginByRemember() consome esse cookie para
   reabrir a sessão sem senha - com rotação a cada uso e revogação automática em
   caso de validator inválido (possível roubo). Sem store injetado, remember-me
   é inerte (attempt() ignora $remember, loginByRemember() sempre retorna null).

   Ver docs/specs/auth-redesign-spec.md (§3.6, §4.2, Fases 2 e 4).
*/

   require_once( __DIR__ . '/guard.interface.php' );
   require_once( __DIR__ . '/remembertokenstore.interface.php' );

   class Gojira_SessionGuard implements Gojira_Guard {

      private $login;           //Login
      private $sessionType;     //papel/silo de sessão que este guard representa (ex.: 'ADM')
      private $rememberStore;   //Gojira_RememberTokenStore|null
      private $rememberCookie;  //nome do cookie de remember-me
      private $rememberTtl;     //segundos de validade do token

      function __construct( Login $login, string $sessionType, $rememberStore = null, string $rememberCookie = '', int $rememberTtl = 2592000 ){

         $this->login          = $login;
         $this->sessionType    = $sessionType;
         $this->rememberStore  = $rememberStore;
         $this->rememberCookie = $rememberCookie !== '' ? $rememberCookie : ( 'remember_' . strtolower( $sessionType ) );
         $this->rememberTtl    = $rememberTtl;
      }

      //$remember=true (opcional, além do contrato Gojira_Guard) emite um cookie de
      //remember-me quando um RememberTokenStore estiver configurado. Adicionar um
      //parâmetro opcional ao final é compatível com a interface (callers que usam
      //o tipo Gojira_Guard continuam passando só os 3 primeiros argumentos).
      public function attempt( string $login, string $plainPassword, array $types = [], bool $remember = false ): Gojira_Authenticatable {

         $types = ! empty( $types ) ? $types : [ $this->sessionType ];

         //Login::login() já faz toda a validação/sessão/migração de hash;
         //aqui só traduzimos o retorno para o Authenticatable tipado.
         $code = $this->login->login( $login, $plainPassword, implode( ',', $types ) );

         $user = $this->login->getUserProvider()->findById( $code, $types );

         if( $user === null ){
            //Não deveria acontecer (login() já validou), mas não deixa o contrato mentir.
            throw( new Gojira_AuthException( LGN_LOGIN_INVALID, 0x9001, Gojira_AuthException::INVALID_USER ) );
         }

         if( $remember && $this->rememberStore instanceof Gojira_RememberTokenStore ){
            $this->issueRememberCookie( $user->getAuthId() );
         }

         return $user;
      }

      public function loginByRemember( string $cookieValue ): ?Gojira_Authenticatable {

         if( ! ( $this->rememberStore instanceof Gojira_RememberTokenStore ) ) return null;

         $parts = explode( ':', $cookieValue, 2 );
         if( count( $parts ) !== 2 ) return null;

         [ $selector, $validator ] = $parts;
         if( $selector === '' || $validator === '' ) return null;

         $record = $this->rememberStore->findBySelector( $selector );
         if( $record === null ) return null;  //não achou/expirou -> anônimo

         //Comparação em tempo constante. Validator não confere -> possível roubo:
         //revoga o token e limpa o cookie, trata como anônimo.
         $validatorHash = hash( 'sha256', $validator );
         if( ! hash_equals( $record->getValidatorHash(), $validatorHash ) ){
            $this->rememberStore->revoke( $selector );
            $this->clearRememberCookie();
            return null;
         }

         $user = $this->login->getUserProvider()->findById( $record->getUserId(), [ $this->sessionType ] );

         if( $user === null ){
            $this->rememberStore->revoke( $selector );
            $this->clearRememberCookie();
            return null;
         }

         //Abre a sessão através do Login (mesmo formato de sempre - interop total).
         //Se a conta foi desativada depois do cookie emitido, loginAs() rejeita.
         try{
            $this->login->loginAs( $user );
         } catch( Gojira_AuthException $e ){
            $this->rememberStore->revoke( $selector );
            $this->clearRememberCookie();
            return null;
         }

         //Rotação a cada uso: invalida o token antigo e emite um novo.
         [ $newSelector, $newValidator ] = $this->rememberStore->rotate( $selector, $record->getUserId(), $this->rememberTtl );
         $this->setRememberCookie( $newSelector . ':' . $newValidator, time() + $this->rememberTtl );

         return $user;
      }

      //Conveniência: lê o cookie de remember-me configurado e tenta autenticar com
      //ele. Não faz parte do contrato Gojira_Guard (que recebe o valor explicitamente).
      public function attemptFromRememberCookie(): ?Gojira_Authenticatable {

         if( ! isset( $_COOKIE[ $this->rememberCookie ] ) ) return null;

         return $this->loginByRemember( (string)$_COOKIE[ $this->rememberCookie ] );
      }

      public function user(): ?Gojira_Authenticatable {

         if( ! $this->check() ) return null;

         $id = $this->login->getLogged( 'Cod', $this->sessionType );
         if( $id === '' ) return null;

         return $this->login->getUserProvider()->findById( $id, [ $this->sessionType ] );
      }

      public function check(): bool {
         return $this->login->isLogged( $this->sessionType );
      }

      public function logout(): void {

         $this->login->logout( $this->sessionType );

         //Encerrar a sessão também revoga qualquer remember-me ativo desta sessão
         //(lido do cookie atual, se presente) - evita reabrir sessão sozinha depois
         //de um logout explícito.
         if( $this->rememberStore instanceof Gojira_RememberTokenStore && isset( $_COOKIE[ $this->rememberCookie ] ) ){

            $parts = explode( ':', (string)$_COOKIE[ $this->rememberCookie ], 2 );
            if( count( $parts ) === 2 && $parts[0] !== '' ){
               $this->rememberStore->revoke( $parts[0] );
            }

            $this->clearRememberCookie();
         }
      }

      private function issueRememberCookie( $userId ){

         [ $selector, $validator ] = $this->rememberStore->issue( $userId, $this->rememberTtl );
         $this->setRememberCookie( $selector . ':' . $validator, time() + $this->rememberTtl );
      }

      private function isHttps(): bool {
         return isset( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] !== '' && strtolower( (string)$_SERVER['HTTPS'] ) !== 'off';
      }

      private function setRememberCookie( string $value, int $expires ){

         setcookie( $this->rememberCookie, $value, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => $this->isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
         ] );

         $_COOKIE[ $this->rememberCookie ] = $value;
      }

      private function clearRememberCookie(){

         setcookie( $this->rememberCookie, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => $this->isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
         ] );

         unset( $_COOKIE[ $this->rememberCookie ] );
      }

   }
