<?php
/*
   Gojira_TableUserProvider - Implementação padrão de Gojira_UserProvider,
   compatível com a convenção atual do GOJIRA (tabelas `user`/`user_type`).

   Reproduz fielmente a detecção de esquema que Login::configure() já fazia
   (coluna status x active, presença da coluna name), mas monta TODAS as
   consultas via Connection::run/runAll/runGet (prepared statements + bind) -
   nenhum valor recebido é concatenado no SQL.

   Ver docs/specs/auth-redesign-spec.md (§3.2, Fase 2).
*/

   require_once( __DIR__ . '/userprovider.interface.php' );
   require_once( __DIR__ . '/genericuser.class.php' );

   class Gojira_TableUserProvider implements Gojira_UserProvider {

      private $connection;
      private $userTable;
      private $typeTable;
      private $useEmail;
      private $passwordField = 'password';
      private $statusField   = 'status';
      private $statusList    = [ 'PENDING' => 0, 'INACTIVE' => 1, 'ACTIVE' => 2 ];
      private $hasName       = true;

      function __construct( $connection, string $userTable = 'user', string $typeTable = 'user_type', bool $useEmail = true ){

         $this->connection = $connection;
         $this->userTable   = $userTable;
         $this->typeTable   = $typeTable;
         $this->useEmail    = $useEmail;

         //Mesma detecção de esquema que Login::configure() já fazia
         if( ! $this->connection->fieldExist( $userTable, $this->statusField ) ){
            $this->statusField = 'active';
            $this->statusList  = [ 'PENDING' => -1, 'INACTIVE' => 0, 'ACTIVE' => 1 ];
         }

         $this->hasName = $this->connection->fieldExist( $userTable, 'name' );
      }

      //Mapa de status, usado pelo Login (adapter) para distinguir PENDING de INACTIVE.
      public function getStatusList(): array {
         return $this->statusList;
      }

      //Resolve shortcodes/ids/nomes de tipo para uma lista de ids numéricos.
      //Array vazio de entrada = sem restrição (retorna vazio, sem filtrar).
      private function resolveTypeIds( array $values ): array {

         $values = array_values( array_filter( $values, function( $v ){ return $v !== '' && $v !== null; } ) );
         if( empty( $values ) ) return [];

         $ph  = implode( ',', array_fill( 0, count( $values ), '?' ) );
         $sql = 'SELECT id FROM `' . $this->typeTable . '` WHERE id IN (' . $ph . ') OR shortcode IN (' . $ph . ') OR name IN (' . $ph . ')';

         $rows = $this->connection->runAll( $sql, array_merge( $values, $values, $values ) );

         return array_map( function( $r ){ return $r['id']; }, $rows );
      }

      private function baseSelect(): string {

         $nameExpr = $this->hasName ? 'a.name AS name' : "'' AS name";

         return 'SELECT b.shortcode AS role, b.id AS type_id, a.id AS id, ' .
                'a.`' . $this->passwordField . '` AS pwd, a.login AS login, a.email AS email, ' .
                'a.`' . $this->statusField . '` AS status, ' . $nameExpr . ' ' .
                'FROM `' . $this->userTable . '` AS a ' .
                'INNER JOIN `' . $this->typeTable . '` AS b ON a.type = b.id';
      }

      private function rowToUser( array $row ): Gojira_GenericUser {

         $active = ( (string)$row['status'] === (string)$this->statusList['ACTIVE'] );

         return new Gojira_GenericUser(
            $row['id'], (string)$row['login'], (string)( $row['email'] ?? '' ), (string)$row['pwd'],
            $row['type_id'], (string)$row['role'], (string)( $row['name'] ?? '' ), $active, $row['status']
         );
      }

      private function findBy( string $where, array $params, array $types ): ?Gojira_Authenticatable {

         $typeIds = $this->resolveTypeIds( $types );

         //Tipos foram pedidos mas nenhum existe na base -> nunca vai achar usuário
         if( ! empty( $types ) && empty( $typeIds ) ) return null;

         if( ! empty( $typeIds ) ){
            $ph     = implode( ',', array_fill( 0, count( $typeIds ), '?' ) );
            $where .= ' AND a.type IN (' . $ph . ')';
            $params = array_merge( $params, $typeIds );
         }

         $sql = $this->baseSelect() . ' WHERE ' . $where . ' LIMIT 1';
         $row = $this->connection->runGet( $sql, $params );

         return $row ? $this->rowToUser( $row ) : null;
      }

      public function findByLogin( string $login, array $types = [] ): ?Gojira_Authenticatable {

         if( $this->useEmail ){
            return $this->findBy( '(a.login = ? OR a.email = ?)', [ $login, $login ], $types );
         }

         return $this->findBy( 'a.login = ?', [ $login ], $types );
      }

      public function findById( $id, array $types = [] ): ?Gojira_Authenticatable {
         return $this->findBy( 'a.id = ?', [ $id ], $types );
      }

      public function updatePasswordHash( $id, string $newHash ): bool {

         return $this->connection->run(
            'UPDATE `' . $this->userTable . '` SET `' . $this->passwordField . '` = ? WHERE id = ? LIMIT 1',
            [ $newHash, $id ]
         );
      }

   }
