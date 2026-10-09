<?php
/*
   Gojira_MapAccessPolicy - Implementação padrão de Gojira_AccessPolicy, lida
   de um mapa declarativo central e/ou de uma propriedade estática por
   controller - as duas opções descritas em docs/specs/auth-redesign-spec.md (§3.7).

   Formato do mapa central (chave = nome do controller, minúsculo):
      [
         'home'  => [ 'public' => ['index','splash'] ],      // alguns métodos públicos
         'page'  => [ 'public' => true ],                    // controller inteiro público
         'admin' => [ 'requires' => ['ADM','MTR'] ],          // exige um desses papéis
      ]

   Formato da declaração por controller (opcional, estende/sobrescreve o mapa
   central para aquela classe - útil para manter a regra junto do código):
      class Admin_Controller extends AppController {
         public static $access = [ 'public' => [], 'requires' => ['ADM','MTR'] ];
      }

   Resolução de papel: usa a MESMA checagem multi-silo que o resto do GOJIRA
   já usa ($login->isLogged($role)), então funciona com Login tradicional e
   com Gojira_SessionGuard (que delega pro mesmo Login). O parâmetro $user do
   contrato é um sinal complementar, para policies futuras sem Login injetado.
*/

   require_once( __DIR__ . '/accesspolicy.interface.php' );

   class Gojira_MapAccessPolicy implements Gojira_AccessPolicy {

      private $map;
      private $login;  //Login|null

      function __construct( array $map = [], $login = null ){
         $this->map   = $map;
         $this->login = $login;
      }

      //Resolve o nome de classe de controller EXATAMENTE como Controller::Load() faz,
      //para localizar a declaração estática $access (se existir).
      private function controllerClassName( string $class ): string {
         return ucwords( strtolower( $class ), "_0123456789" ) . '_Controller';
      }

      private function rules( string $class ): array {

         $key   = strtolower( $class );
         $rules = isset( $this->map[ $key ] ) ? $this->map[ $key ] : [];
         $rules += [ 'public' => [], 'requires' => [] ];

         $controllerClass = $this->controllerClassName( $class );

         if( class_exists( $controllerClass ) && property_exists( $controllerClass, 'access' ) ){

            $decl = $controllerClass::$access;

            if( isset( $decl['public'] ) )   $rules['public']   = $decl['public'];
            if( isset( $decl['requires'] ) ) $rules['requires'] = $decl['requires'];
         }

         return $rules;
      }

      public function isPublic( string $class, string $method ): bool {

         $public = $this->rules( $class )['public'];

         if( $public === true ) return true;

         if( is_array( $public ) ){
            return in_array( strtolower( $method ), array_map( 'strtolower', $public ), true );
         }

         return false;
      }

      public function authorize( string $class, string $method, ?Gojira_Authenticatable $user ): bool {

         $requires = $this->rules( $class )['requires'];

         //Não público e sem papel exigido declarado -> deny-by-default.
         if( empty( $requires ) ) return false;

         foreach( $requires as $role ){

            if( $this->login instanceof Login && $this->login->isLogged( $role ) ) return true;

            if( $user !== null && in_array( strtoupper( $role ), $user->getRoles(), true ) ) return true;
         }

         return false;
      }

   }
