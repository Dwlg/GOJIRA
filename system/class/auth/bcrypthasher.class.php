<?php
/*
   Gojira_BcryptHasher - Hasher moderno padrão do GOJIRA.
   Usa password_hash()/password_verify() (bcrypt por padrão, ou argon2 se configurado).
   Aceita um hasher legado opcional para validar e migrar senhas antigas de forma
   transparente. Ver docs/migration/auth-redesign-migration-guide.md.

   Limite do bcrypt: só os primeiros 72 bytes da senha entram no hash. Duas
   senhas que coincidem nesses 72 bytes (em UTF-8, um caractere acentuado ocupa
   2 bytes) produzem o mesmo resultado, e o resto é ignorado sem erro. Quem
   precisar aceitar senhas maiores pode injetar um hasher argon2
   (PASSWORD_ARGON2ID), que não tem esse limite, ou limitar o tamanho da senha
   no cadastro.
*/

   require_once( __DIR__ . '/passwordhasher.interface.php' );

   class Gojira_BcryptHasher implements Gojira_PasswordHasher {

      private $legacy;    //Gojira_PasswordHasher|null - fallback para hashes antigos
      private $algo;
      private $options;

      //Custo padrão do bcrypt, fixo para não depender da versão do PHP
      //(o padrão do PHP é 10 até o 8.3 e 12 a partir do 8.4).
      const DEFAULT_COST = 12;

      //$options['cost'] troca o custo do bcrypt (ex.: ['cost' => 13]).
      function __construct( $legacy = null, $algo = PASSWORD_DEFAULT, array $options = [] ){
         $this->legacy  = $legacy;
         $this->algo    = $algo;

         if( $algo === PASSWORD_BCRYPT && !isset( $options['cost'] ) ){
            $options['cost'] = self::DEFAULT_COST;
         }
         $this->options = $options;
      }

      public function hash( string $plain ): string {
         return password_hash( $plain, $this->algo, $this->options );
      }

      public function verify( string $plain, string $hash ): bool {

         //Hash moderno reconhecido pelo PHP
         if( $this->isModern( $hash ) ){
            return password_verify( $plain, $hash );
         }

         //Fallback: valida contra o esquema legado (para permitir migração)
         if( $this->legacy instanceof Gojira_PasswordHasher ){
            return $this->legacy->verify( $plain, $hash );
         }

         return false;
      }

      public function needsRehash( string $hash ): bool {

         //Não é um hash moderno => precisa migrar
         if( ! $this->isModern( $hash ) ){
            return true;
         }

         return password_needs_rehash( $hash, $this->algo, $this->options );
      }

      //Detecta se $hash foi gerado por um algoritmo conhecido do password_hash().
      private function isModern( string $hash ): bool {
         $info = password_get_info( $hash );
         return !empty( $info['algo'] );
      }

   }
