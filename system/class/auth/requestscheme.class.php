<?php
/*
   Gojira_RequestScheme - Decide se a requisição chegou por HTTPS, para marcar
   os cookies de login e de remember-me como Secure.

   Sem configuração, só $_SERVER['HTTPS'] vale (comportamento anterior).
   Opções (constantes definidas antes de carregar o framework, ou configure()):
   - GOJIRA_FORCE_SECURE_COOKIES = true: todo cookie de login sai com Secure,
     para sites servidos só por HTTPS.
   - GOJIRA_TRUSTED_PROXIES = '10.0.0.5, 10.1.0.0/16': o cabeçalho
     X-Forwarded-Proto só é considerado quando REMOTE_ADDR está nessa lista
     (IP exato ou faixa CIDR, IPv4 ou IPv6). Vindo de qualquer outro endereço,
     o cabeçalho é ignorado.

   Ver docs/migration/auth-redesign-migration-guide.md.
*/

   class Gojira_RequestScheme {

      private static $configured = false;
      private static $forceSecure = false;
      private static $trustedProxies = [];

      //Configuração em código. Sem chamar, as constantes acima são lidas.
      public static function configure( bool $forceSecure = false, array $trustedProxies = [] ): void {
         self::$forceSecure    = $forceSecure;
         self::$trustedProxies = array_values( array_filter( array_map( 'trim', $trustedProxies ), 'strlen' ) );
         self::$configured     = true;
      }

      //Volta a ler as constantes na próxima chamada.
      public static function reset(): void {
         self::$configured = false;
      }

      public static function isHttps(): bool {

         self::loadConstants();

         if( self::$forceSecure ) return true;

         if( isset( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] !== '' && strtolower( (string)$_SERVER['HTTPS'] ) !== 'off' ){
            return true;
         }

         if( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && self::fromTrustedProxy() ){
            //Pode vir uma lista ("https, http"); vale o primeiro, que é o do cliente.
            $proto = strtolower( trim( explode( ',', (string)$_SERVER['HTTP_X_FORWARDED_PROTO'] )[0] ) );
            return $proto === 'https';
         }

         return false;
      }

      private static function loadConstants(): void {

         if( self::$configured ) return;

         $proxies = defined( 'GOJIRA_TRUSTED_PROXIES' ) ? GOJIRA_TRUSTED_PROXIES : [];
         if( is_string( $proxies ) ) $proxies = explode( ',', $proxies );

         self::configure(
            defined( 'GOJIRA_FORCE_SECURE_COOKIES' ) && GOJIRA_FORCE_SECURE_COOKIES == true,
            is_array( $proxies ) ? $proxies : []
         );
      }

      private static function fromTrustedProxy(): bool {

         $remote = isset( $_SERVER['REMOTE_ADDR'] ) ? (string)$_SERVER['REMOTE_ADDR'] : '';
         if( $remote === '' ) return false;

         foreach( self::$trustedProxies as $entry ){
            if( self::ipMatches( $remote, $entry ) ) return true;
         }

         return false;
      }

      //IP exato ou faixa CIDR (ex.: 10.0.0.0/8, fd00::/8).
      private static function ipMatches( string $ip, string $entry ): bool {

         $parts  = explode( '/', $entry, 2 );
         $ipBin  = @inet_pton( $ip );
         $netBin = @inet_pton( $parts[0] );

         if( $ipBin === false || $netBin === false || strlen( $ipBin ) !== strlen( $netBin ) ) return false;

         $bits = isset( $parts[1] ) ? $parts[1] : (string)( strlen( $netBin ) * 8 );
         if( ! ctype_digit( $bits ) || (int)$bits > strlen( $netBin ) * 8 ) return false;
         $bits = (int)$bits;

         $bytes = intdiv( $bits, 8 );
         if( substr( $ipBin, 0, $bytes ) !== substr( $netBin, 0, $bytes ) ) return false;

         $rest = $bits % 8;
         if( $rest === 0 ) return true;

         $mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
         return ( ord( $ipBin[ $bytes ] ) & $mask ) === ( ord( $netBin[ $bytes ] ) & $mask );
      }

   }
