<?php
/*
   Gojira_RememberToken - Registro de remember-me (selector/validator).
   Ver docs/specs/auth-redesign-spec.md (§3.4, Fase 4).

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
