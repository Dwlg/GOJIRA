<?php
/*
   Gojira_SessionGuard - Implementação padrão de Gojira_Guard.

   Delega toda a mecânica de sessão/cookie para uma instância de Login já
   existente (mesmas chaves de sessão, mesma configuração) - zero duplicação
   de estado e zero risco de regressão: Login::isLogged()/getLogged() de um
   tipo continuam enxergando uma sessão aberta via Guard, e vice-versa.

   O ganho do Guard é a fachada mais simples (user()/check()/logout() sem
   precisar repassar $type toda hora) e o retorno tipado (Gojira_Authenticatable)
   em vez do array $row cru.

   Ver docs/specs/auth-redesign-spec.md (§3.6, Fase 2).
*/

   require_once( __DIR__ . '/guard.interface.php' );

   class Gojira_SessionGuard implements Gojira_Guard {

      private $login;        //Login
      private $sessionType;  //papel/silo de sessão que este guard representa (ex.: 'ADM')

      function __construct( Login $login, string $sessionType ){
         $this->login       = $login;
         $this->sessionType = $sessionType;
      }

      public function attempt( string $login, string $plainPassword, array $types = [] ): Gojira_Authenticatable {

         $types = ! empty( $types ) ? $types : [ $this->sessionType ];

         //Login::login() já faz toda a validação/sessão/migração de hash;
         //aqui só traduzimos o retorno para o Authenticatable tipado.
         $code = $this->login->login( $login, $plainPassword, implode( ',', $types ) );

         $user = $this->login->getUserProvider()->findById( $code, $types );

         if( $user === null ){
            //Não deveria acontecer (login() já validou), mas não deixa o contrato mentir.
            throw( new Gojira_AuthException( LGN_LOGIN_INVALID, 0x9001, Gojira_AuthException::INVALID_USER ) );
         }

         return $user;
      }

      public function loginByRemember( string $cookieValue ): ?Gojira_Authenticatable {
         //Remember-me (selector/validator) é entregue na Fase 4 da spec.
         return null;
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
      }

   }
