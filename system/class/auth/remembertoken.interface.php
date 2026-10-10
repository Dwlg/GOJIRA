<?php
/*
   Gojira_RememberToken - Registro de remember-me (selector/validator).
   Ver docs/migration/auth-redesign-migration-guide.md.

   O validator NUNCA é exposto por aqui - só o hash dele (validator_hash).
   O validator em claro existe apenas no cookie do navegador e no instante
   em que é gerado/comparado.
*/

   interface Gojira_RememberToken {

      public function getSelector(): string;
      public function getValidatorHash(): string;
      public function getUserId();
      public function getExpiresAt(): int;

   }
