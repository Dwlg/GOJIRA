<?php
/*
   Gojira_AuthException - Exceção de autenticação com código estável, para o
   controller tratar a UI sem depender de parsing de mensagem.

   Mantém o $code numérico legado (0x9001-0x9004) usado pela classe Login, e
   adiciona um código string (getAuthCode()) para consumidores novos (Guard).
*/

   class Gojira_AuthException extends Exception {

      const INVALID_USER     = 'AUTH_INVALID_USER';
      const INVALID_PASSWORD = 'AUTH_INVALID_PASSWORD';
      const INACTIVE         = 'AUTH_INACTIVE';
      const PENDING          = 'AUTH_PENDING';

      private $authCode;

      function __construct( string $message, int $legacyCode, string $authCode ){
         parent::__construct( $message, $legacyCode );
         $this->authCode = $authCode;
      }

      public function getAuthCode(): string {
         return $this->authCode;
      }

   }
