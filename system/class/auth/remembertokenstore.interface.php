<?php
/*
   Gojira_RememberTokenStore - Persistência dos tokens de remember-me.
   Ver docs/migration/auth-redesign-migration-guide.md.
*/

   require_once( __DIR__ . '/remembertoken.interface.php' );

   interface Gojira_RememberTokenStore {

      //Cria e persiste um token. Retorna [selector, validator] - o validator em
      //claro só existe aqui e vai para o cookie; o banco guarda só o hash dele.
      public function issue( $userId, int $ttlSeconds ): array;

      public function findBySelector( string $selector ): ?Gojira_RememberToken;

      //Invalida o atual e emite um novo (rotação a cada uso). A invalidação é
      //atômica e condicionada ao selector e ao hash do validator: retorna
      //[selector, validator] só para quem consumiu o token, e null se ele já
      //tinha sido usado ou revogado (ex.: o mesmo cookie em duas requisições).
      public function rotate( string $selector, string $validatorHash, $userId, int $ttlSeconds ): ?array;

      public function revoke( string $selector ): void;

      public function revokeAllForUser( $userId ): void;

      public function purgeExpired(): void;

   }
