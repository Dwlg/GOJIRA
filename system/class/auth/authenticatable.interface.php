<?php
/*
   Gojira_Authenticatable - Representa o usuário autenticável, isolando o core
   do esquema real de banco do projeto. Ver docs/migration/auth-redesign-migration-guide.md.
*/

   interface Gojira_Authenticatable {

      public function getAuthId();                   // chave primária (mixed)
      public function getAuthLogin(): string;         // campo de login
      public function getAuthEmail(): string;         // e-mail (pode ser '')
      public function getAuthPasswordHash(): string;   // hash armazenado
      public function getAuthType();                  // id (ou shortcode) do tipo
      public function getRoles(): array;               // papéis/claims (ex.: ['ADM'])
      public function isActive(): bool;                // status ativo

   }
