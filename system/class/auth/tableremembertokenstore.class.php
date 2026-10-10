<?php
/*
   Gojira_TableRememberTokenStore - Implementação padrão de
   Gojira_RememberTokenStore (tabela `auth_remember_token`).

   O framework NUNCA cria tabelas sozinho por padrão (filosofia data-first do
   GOJIRA - quem modela o banco é o projeto/DBA). createTableIfMissing() é um
   helper OPCIONAL para quem não tiver um processo de migração próprio; o
   projeto decide se e quando chamá-lo.

   Ver docs/migration/auth-redesign-migration-guide.md.
*/

   require_once( __DIR__ . '/remembertokenstore.interface.php' );
   require_once( __DIR__ . '/remembertokenrecord.class.php' );

   class Gojira_TableRememberTokenStore implements Gojira_RememberTokenStore {

      private $connection;
      private $table;

      function __construct( $connection, string $table = 'auth_remember_token' ){
         $this->connection = $connection;
         $this->table      = $table;
      }

      //DDL de referência (ver docs/migration/auth-redesign-migration-guide.md).
      public static function schemaSql( string $table = 'auth_remember_token' ): string {
         return 'CREATE TABLE IF NOT EXISTS `' . $table . '` (' .
                ' selector CHAR(24) NOT NULL,' .
                ' validator_hash CHAR(64) NOT NULL,' .
                ' user_id INT NOT NULL,' .
                ' expires_at INT NOT NULL,' .
                ' user_agent VARCHAR(255) NULL,' .
                ' created_at INT NOT NULL,' .
                ' PRIMARY KEY (selector),' .
                ' KEY idx_user (user_id),' .
                ' KEY idx_expires (expires_at)' .
                ' )';
      }

      //Helper opcional - cria a tabela se ainda não existir. Nunca chamado
      //automaticamente pelo framework.
      public function createTableIfMissing(): void {
         if( ! $this->connection->tableExist( $this->table ) ){
            $this->connection->execute( self::schemaSql( $this->table ) );
         }
      }

      private function newSelector(): string { return bin2hex( random_bytes( 12 ) ); }   // 24 chars
      private function newValidator(): string { return bin2hex( random_bytes( 32 ) ); }  // 64 chars
      private function hashValidator( string $validator ): string { return hash( 'sha256', $validator ); }

      public function issue( $userId, int $ttlSeconds ): array {

         $selector  = $this->newSelector();
         $validator = $this->newValidator();
         $expires   = time() + $ttlSeconds;
         $ua        = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( $_SERVER['HTTP_USER_AGENT'], 0, 255 ) : null;

         $this->connection->run(
            'INSERT INTO `' . $this->table . '` (selector, validator_hash, user_id, expires_at, user_agent, created_at) VALUES (?,?,?,?,?,?)',
            [ $selector, $this->hashValidator( $validator ), $userId, $expires, $ua, time() ]
         );

         return [ $selector, $validator ];
      }

      public function findBySelector( string $selector ): ?Gojira_RememberToken {

         $row = $this->connection->runGet(
            'SELECT selector, validator_hash, user_id, expires_at FROM `' . $this->table . '` WHERE selector = ? LIMIT 1',
            [ $selector ]
         );

         if( ! $row ) return null;

         //Expirado: limpeza oportunista, trata como não encontrado.
         if( (int)$row['expires_at'] < time() ){
            $this->revoke( $selector );
            return null;
         }

         return new Gojira_RememberTokenRecord( $row['selector'], $row['validator_hash'], $row['user_id'], (int)$row['expires_at'] );
      }

      //Consome o token num único DELETE condicionado ao selector e ao hash do
      //validator, e só emite o novo se essa linha foi apagada aqui. Duas
      //requisições com o mesmo cookie: só uma recebe o token novo.
      public function rotate( string $selector, string $validatorHash, $userId, int $ttlSeconds ): ?array {

         $this->connection->run(
            'DELETE FROM `' . $this->table . '` WHERE selector = ? AND validator_hash = ?',
            [ $selector, $validatorHash ]
         );

         if( $this->connection->affectedRows() !== 1 ) return null;

         return $this->issue( $userId, $ttlSeconds );
      }

      public function revoke( string $selector ): void {
         $this->connection->run( 'DELETE FROM `' . $this->table . '` WHERE selector = ?', [ $selector ] );
      }

      public function revokeAllForUser( $userId ): void {
         $this->connection->run( 'DELETE FROM `' . $this->table . '` WHERE user_id = ?', [ $userId ] );
      }

      public function purgeExpired(): void {
         $this->connection->run( 'DELETE FROM `' . $this->table . '` WHERE expires_at < ?', [ time() ] );
      }

   }
