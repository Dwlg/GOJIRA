<?php
/*
   Gojira_RememberTokenStore - Persistência dos tokens de remember-me.
   Ver docs/specs/auth-redesign-spec.md (§3.4, Fase 4).
*/

   require_once( __DIR__ . '/remembertoken.interface.php' );

   interface Gojira_RememberTokenStore {

      //Cria e persiste um token. Retorna [selector, validator] - o validator em
      //claro só existe aqui e vai para o cookie; o banco guarda só o hash dele.
      public function issue( $userId, int $ttlSeconds ): array;

      public function findBySelector( string $selector ): ?Gojira_RememberToken;

      //Invalida o atual e emite um novo (rotação a cada uso). Retorna [selector, validator].
      public function rotate( string $selector, $userId, int $ttlSeconds ): array;

      public function revoke( string $selector ): void;

      public function revokeAllForUser( $userId ): void;

      public function purgeExpired(): void;

   }
