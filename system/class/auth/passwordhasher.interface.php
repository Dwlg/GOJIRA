<?php
/*
   Gojira_PasswordHasher - Contrato de hashing de senha do GOJIRA.
   Ver docs/specs/auth-redesign-spec.md (§3.3).
*/

   interface Gojira_PasswordHasher {

      //Gera o hash da senha em claro.
      public function hash( string $plain ): string;

      //Verifica a senha em claro contra um hash armazenado (tempo-constante).
      public function verify( string $plain, string $hash ): bool;

      //Indica se o hash deve ser regerado (esquema legado ou parâmetros desatualizados).
      public function needsRehash( string $hash ): bool;

   }
