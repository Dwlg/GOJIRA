<?php
/*
   Gojira_LegacyHasher - Esquema de hash antigo do GOJIRA (sha512 + whirlpool, 3 voltas).
   Mantido APENAS para validar senhas legadas e migrá-las para um esquema moderno.
   Não deve ser usado para gerar hashes novos. Ver docs/specs/auth-redesign-spec.md (§3.3).
*/

   require_once( __DIR__ . '/passwordhasher.interface.php' );

   class Gojira_LegacyHasher implements Gojira_PasswordHasher {

      //Reproduz exatamente o algoritmo legado (Login::toPassword).
      public function hash( string $plain ): string {

         $val = $plain;
         for( $c = 0; $c < 3; $c++ ){
            $val = hash( 'sha512'   , $val, false );
            $val = hash( 'whirlpool', $val, false );
         }

         return $val;
      }

      public function verify( string $plain, string $hash ): bool {
         return hash_equals( $hash, $this->hash( $plain ) );
      }

      //Hash legado sempre deve ser migrado.
      public function needsRehash( string $hash ): bool {
         return true;
      }

      //Heurística: identifica um hash no formato legado (128 hex).
      public function looksLegacy( string $hash ): bool {
         return (bool) preg_match( '/^[a-f0-9]{128}$/i', $hash );
      }

   }
