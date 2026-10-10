<?php
/*
   Gojira_Guard - Fachada de autenticação. Ver docs/migration/auth-redesign-migration-guide.md.

   Cada instância representa UM papel/silo de sessão (ex.: um Guard para 'ADM',
   outro para 'USR'), compatível com o modelo multi-tipo de sessão do GOJIRA
   (vários tipos podem estar logados simultaneamente na mesma sessão).
*/

   require_once( __DIR__ . '/authenticatable.interface.php' );

   interface Gojira_Guard {

      //Autentica por login+senha. Lança Gojira_AuthException em falha.
      public function attempt( string $login, string $plainPassword, array $types = [] ): Gojira_Authenticatable;

      //Tenta autenticar a partir de um cookie de remember-me (selector:validator).
      //Retorna null se não houver suporte a remember-me configurado (Fase 4) ou
      //se o cookie for inválido/expirado.
      public function loginByRemember( string $cookieValue ): ?Gojira_Authenticatable;

      public function user(): ?Gojira_Authenticatable;  // usuário atual (ou null)
      public function check(): bool;                    // está logado?
      public function logout(): void;                   // encerra sessão

   }
