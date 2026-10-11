<?php
/*
   Gojira_RememberTokenRecord - Implementação concreta e simples de
   Gojira_RememberToken, usada pelo Gojira_TableRememberTokenStore.
*/

   require_once( __DIR__ . '/remembertoken.interface.php' );

   class Gojira_RememberTokenRecord implements Gojira_RememberToken {

      private $selector;
      private $validatorHash;
      private $userId;
      private $expiresAt;

      function __construct( string $selector, string $validatorHash, $userId, int $expiresAt ){
         $this->selector      = $selector;
         $this->validatorHash = $validatorHash;
         $this->userId        = $userId;
         $this->expiresAt     = $expiresAt;
      }

      public function getSelector(): string { return $this->selector; }
      public function getValidatorHash(): string { return $this->validatorHash; }
      public function getUserId(){ return $this->userId; }
      public function getExpiresAt(): int { return $this->expiresAt; }

   }
