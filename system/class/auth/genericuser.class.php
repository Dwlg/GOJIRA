<?php
/*
   Gojira_GenericUser - Implementação concreta e genérica de Gojira_Authenticatable,
   usada pelo Gojira_TableUserProvider (provider padrão).

   Expõe, ALÉM do contrato, alguns getters extras (getName, getRawStatus) usados
   internamente pelo adapter Login para preservar mensagens de erro legadas
   (PENDING vs INACTIVE) ao usar o provider padrão. Um provider customizado não
   precisa implementar esses extras - nesse caso o Login degrada graciosamente
   para uma mensagem genérica de "conta inativa".
*/

   require_once( __DIR__ . '/authenticatable.interface.php' );

   class Gojira_GenericUser implements Gojira_Authenticatable {

      private $id;
      private $login;
      private $email;
      private $passwordHash;
      private $typeId;
      private $role;
      private $name;
      private $active;
      private $rawStatus;

      function __construct( $id, string $login, string $email, string $passwordHash,
                             $typeId, string $role, string $name, bool $active, $rawStatus = null ){

         $this->id           = $id;
         $this->login         = $login;
         $this->email         = $email;
         $this->passwordHash = $passwordHash;
         $this->typeId        = $typeId;
         $this->role           = strtoupper( $role );
         $this->name           = $name;
         $this->active         = $active;
         $this->rawStatus     = $rawStatus;
      }

      //--- Gojira_Authenticatable ---

      public function getAuthId(){ return $this->id; }
      public function getAuthLogin(): string { return $this->login; }
      public function getAuthEmail(): string { return $this->email; }
      public function getAuthPasswordHash(): string { return $this->passwordHash; }
      public function getAuthType(){ return $this->typeId; }
      public function getRoles(): array { return [ $this->role ]; }
      public function isActive(): bool { return $this->active; }

      //--- Extras (fora do contrato) ---

      public function getName(): string { return $this->name; }
      public function getRawStatus(){ return $this->rawStatus; }

   }
