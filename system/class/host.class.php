<?php
/* Gojira_Host - host confiável e URL base da aplicação.

   BASE_URL (host + caminho, sem protocolo, terminado em '/') é resolvido
   nesta ordem:
      1. $baseURL definido pela aplicação antes do init.php;
      2. variável de ambiente GOJIRA_BASE_URL (ex.: no .env);
      3. [<PROJECT_ID>_ENGINE] BASE_URL do app_configs.ini;
      4. CLI: 'cli://';
      5. detectado da requisição (SERVER_NAME + caminho do script).

   O valor detectado vale só para a requisição corrente e nunca é gravado
   no app_configs.ini: um Host forjado não fica mais persistido para todos.
   O $baseURL explícito continua gravado, mas só quando difere do arquivo.

   Detectado sem TRUSTED_HOSTS, o BASE_URL depende do Host enviado pelo
   cliente (no Apache padrão, SERVER_NAME é o Host). checkBaseUrlSource()
   aplica a política do Gojira_Env::reportIssue(): aviso a cada requisição
   fora de DEV; boot recusado em PROD/PRODUCTION ou com GOJIRA_STRICT_CONFIG.
   Na 2.0, BASE_URL ou TRUSTED_HOSTS passam a ser obrigatórios na web.

   Hosts com caractere fora de [a-z0-9.-] (com porta opcional) ou que não
   sejam IPv6 literal entre colchetes são tratados como host vazio.

   TRUSTED_HOSTS ([<PROJECT_ID>_ENGINE] TRUSTED_HOSTS ou GOJIRA_TRUSTED_HOSTS)
   é uma lista separada por vírgula. Vazia: qualquer host é aceito (como
   antes). Preenchida: o init.php responde 400 a um Host fora da lista.
   'exemplo.com' casa só o host exato; '.exemplo.com' casa o domínio e os
   subdomínios; '*.exemplo.com' casa só os subdomínios.
*/

require_once __DIR__ . '/env.class.php';

final class Gojira_Host {

   /** Origens possíveis do BASE_URL (ver resolveBaseUrl). */
   const SOURCE_EXPLICIT = 'explicit';
   const SOURCE_ENV      = 'env';
   const SOURCE_CONFIG   = 'config';
   const SOURCE_CLI      = 'cli';
   const SOURCE_DETECTED = 'detected';

   /** Lista de hosts a partir de string (vírgula ou espaço) ou array. */
   public static function parseList( $list ): array {
      if( is_string( $list ) ) $list = preg_split( '/[\s,]+/', $list );
      if( ! is_array( $list ) ) return [];

      $out = [];
      foreach( $list as $h ){
         if( ! is_string( $h ) ) continue;
         $h = strtolower( trim( $h ) );
         if( $h !== '' ) $out[] = $h;
      }
      return array_values( array_unique( $out ) );
   }


   /** Host da requisição, sem porta e em minúsculas ('' se não houver ou for inválido). */
   public static function requestHost( array $server ): string {
      $host = '';
      if( isset( $server['HTTP_HOST'] ) && is_string( $server['HTTP_HOST'] ) && $server['HTTP_HOST'] !== '' ){
         $host = $server['HTTP_HOST'];
      } elseif( isset( $server['SERVER_NAME'] ) && is_string( $server['SERVER_NAME'] ) ){
         $host = $server['SERVER_NAME'];
      }
      return self::validHost( $host );
   }


   /**
    * Valida e normaliza um host: [a-z0-9.-] com porta opcional, ou IPv6
    * literal entre colchetes com porta opcional. Devolve sem a porta e sem
    * o ponto final, em minúsculas; '' se inválido.
    */
   public static function validHost( string $host ): string {
      $host = strtolower( trim( $host ) );

      if( preg_match( '/^(\[[0-9a-f:.]+\])(?::[0-9]{1,5})?$/D', $host, $m ) ) return $m[1];

      if( ! preg_match( '/^([a-z0-9.-]+)(?::[0-9]{1,5})?$/D', $host, $m ) ) return '';

      $name = rtrim( $m[1], '.' );
      if( $name === '' || $name[0] === '.' || $name[0] === '-' || strpos( $name, '..' ) !== false ) return '';
      return $name;
   }


   /** O host está na lista? Lista vazia aceita qualquer host. */
   public static function isTrusted( string $host, array $trusted ): bool {
      if( ! $trusted ) return true;

      $host = strtolower( $host );
      if( $host === '' ) return false;

      foreach( $trusted as $t ){
         if( $t === $host ) return true;

         if( strncmp( $t, '*.', 2 ) === 0 ){
            $suffix = substr( $t, 1 );                                 // .exemplo.com
            if( strlen( $host ) > strlen( $suffix ) && substr( $host, -strlen( $suffix ) ) === $suffix ) return true;

         } elseif( $t[0] === '.' ){
            if( $host === substr( $t, 1 ) ) return true;
            if( substr( $host, -strlen( $t ) ) === $t ) return true;
         }
      }
      return false;
   }


   /**
    * URL base detectada da requisição: SERVER_NAME + diretório do script de
    * entrada (configs.php/index.php). Sem SCRIPT_NAME, usa o caminho do
    * REQUEST_URI até a última barra, como fazia o init.php.
    */
   public static function detectBaseUrl( array $server ): string {
      $name = isset( $server['SERVER_NAME'] ) && is_string( $server['SERVER_NAME'] ) ? self::validHost( $server['SERVER_NAME'] ) : '';

      if( isset( $server['SCRIPT_NAME'] ) && is_string( $server['SCRIPT_NAME'] ) && $server['SCRIPT_NAME'] !== '' ){
         $dir = str_replace( '\\', '/', dirname( $server['SCRIPT_NAME'] ) );
         $dir = trim( $dir, '/.' );
         return self::normalizeBaseUrl( $name . '/' . ( $dir === '' ? '' : $dir . '/' ) );
      }

      $url = $name . ( isset( $server['REQUEST_URI'] ) ? (string) $server['REQUEST_URI'] : '' );
      $url = substr( $url, 0, min( (int) strrpos( $url, '/' ), strlen( $url ) ) );
      if( substr( $url, -1, 1 ) != '/' ) $url .= '/';
      return $url;
   }


   /** Remove o protocolo, query e fragmento, e garante a barra final. */
   public static function normalizeBaseUrl( string $url ): string {
      $url = trim( $url );
      $url = preg_replace( '#^[a-z][a-z0-9+.-]*://#i', '', $url );
      $url = preg_replace( '/[?#].*$/', '', $url );
      if( $url === '' ) return '';
      if( substr( $url, -1 ) !== '/' ) $url .= '/';
      return $url;
   }


   /**
    * Lista de hosts confiáveis: GOJIRA_TRUSTED_HOSTS no ambiente, senão a
    * chave TRUSTED_HOSTS da seção do engine. Não grava nada no arquivo.
    */
   public static function trustedHosts( Config $cfg, string $section ): array {
      $env = Gojira_Env::get( 'GOJIRA_TRUSTED_HOSTS' );
      if( is_string( $env ) && trim( $env ) !== '' ) return self::parseList( $env );

      return self::parseList( $cfg->getConfig( $section, 'TRUSTED_HOSTS' ) );
   }


   /**
    * Resolve o BASE_URL na ordem descrita no topo do arquivo.
    * $source recebe uma das constantes SOURCE_*.
    */
   public static function resolveBaseUrl( Config $cfg, string $section, ?string $explicit, array $server, string $sapi, ?string &$source = null ): string {

      if( $explicit !== null ){
         // Valor da aplicação: continua persistido, mas sem regravar a cada requisição.
         if( $cfg->getConfig( $section, 'BASE_URL' ) !== $explicit ){
            $cfg->setConfig( $section, 'BASE_URL', $explicit );
         }
         $source = self::SOURCE_EXPLICIT;
         return $explicit;
      }

      $env = Gojira_Env::get( 'GOJIRA_BASE_URL' );
      if( is_string( $env ) && trim( $env ) !== '' ){
         $source = self::SOURCE_ENV;
         return self::normalizeBaseUrl( $env );
      }

      $stored = $cfg->getConfig( $section, 'BASE_URL' );
      if( ! empty( $stored ) ){
         $source = self::SOURCE_CONFIG;
         return $stored;
      }

      if( $sapi === 'cli' ){
         $source = self::SOURCE_CLI;
         return 'cli://';
      }

      $source = self::SOURCE_DETECTED;
      return self::detectBaseUrl( $server );
   }


   /**
    * BASE_URL detectado sem TRUSTED_HOSTS: aplica a política de
    * Gojira_Env::reportIssue() (nada em DEV, aviso fora de DEV, exceção em
    * PROD/PRODUCTION ou com GOJIRA_STRICT_CONFIG). Devolve o que foi feito.
    */
   public static function checkBaseUrlSource( ?string $source, array $trusted, string $environment ): string {
      if( $source !== self::SOURCE_DETECTED || $trusted ) return 'none';

      return Gojira_Env::reportIssue(
         'BASE_URL detectado a partir do Host da requisição, sem TRUSTED_HOSTS. '
         . 'Defina GOJIRA_BASE_URL (ou BASE_URL no app_configs.ini) ou GOJIRA_TRUSTED_HOSTS.',
         $environment,
         false
      );
   }

}
