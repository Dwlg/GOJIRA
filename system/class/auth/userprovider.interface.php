<?php
/*
   Gojira_UserProvider - Localiza usuários. NUNCA valida senha - só recupera o
   Authenticatable. Ver docs/specs/auth-redesign-spec.md (§3.2).

   Implementações DEVEM usar query parametrizada (Connection::run/runAll/runGet),
   nunca concatenar valores recebidos no SQL.
*/

   require_once( __DIR__ . '/authenticatable.interface.php' );

   interface Gojira_UserProvider {

      //Busca por login OU e-mail, restrito a um conjunto de tipos (vazio = qualquer).
      //Retorna null se não encontrar.
      public function findByLogin( string $login, array $types = [] ): ?Gojira_Authenticatable;

      //Revalida identidade por id (usado em sessão/remember-me).
      public function findById( $id, array $types = [] ): ?Gojira_Authenticatable;

      //Persiste um novo hash de senha (migração no login). Com $expectedOldHash,
      //só grava se o hash atual ainda for esse e devolve false se nada mudou.
      public function updatePasswordHash( $id, string $newHash, ?string $expectedOldHash = null ): bool;

   }
