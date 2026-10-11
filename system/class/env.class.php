<?php
/* Gojira_Env - leitura opcional de um arquivo .env, sem dependência externa.

   Opt-in: nada é lido enquanto o projeto não chamar Gojira_Env::load().
   Sem o arquivo, load() não faz nada e as constantes seguem com o valor
   de fallback do define().

   Formato aceito (um par por linha):
      CHAVE=valor
      export CHAVE=valor
      CHAVE="valor com espaço e \n escapes"   (\n \r \t \" \\)
      CHAVE='valor literal'
      CHAVE=valor   # comentário depois de espaço
      # linha de comentário
   Não há interpolação de variáveis nem valor em várias linhas.

   Os valores ficam em $_ENV e num array interno; putenv() não é usado,
   para não alterar o ambiente do processo (e de processos filhos).
   Chaves PATH, LD_* e HTTP_* são recusadas. Uma variável que já existe no
   ambiente (getenv, $_ENV ou $_SERVER) não é sobrescrita, a menos que
   load() receba $override = true.

   Política de configuração insegura (reportIssue), na 1.x:
      ENVIRONMENT=DEV ................ só registro no error_log
      outro ENVIRONMENT .............. error_log e E_USER_WARNING a cada requisição
      PROD/PRODUCTION ou flag ........ exceção: o boot é recusado
   A flag é GOJIRA_STRICT_CONFIG (constante true ou variável de ambiente
   1/true/on/yes). Na 2.0 o boot passa a ser recusado sempre.

   O .env nunca deve ficar acessível pela web: mantenha-o fora do webroot
   (o project_demo/.htaccess nega arquivos que começam com ponto).
*/

final class Gojira_Env {

   /**
    * Valores de exemplo que não podem chegar à produção como credencial.
    * Lista conservadora: só nomes de exemplo, para não barrar uma senha real.
    */
   const PLACEHOLDERS = [
      'db_user', 'db_host', 'db_name', 'db_pwd', 'db_pass', 'db_password',
      'user$', 'homodb', 'homopwd',
      'changeme', 'change_me', 'change-me', 'your_password', 'sua_senha',
   ];


   /** Valores carregados do .env (têm precedência em get()). */
   private static $vars = [];


   /**
    * Carrega o arquivo em $_ENV e no array interno.
    * Retorna as chaves efetivamente definidas. Arquivo ausente: retorna [].
    */
   public static function load( string $file, bool $override = false ): array {

      if( ! is_file( $file ) || ! is_readable( $file ) ) return [];

      $content = file_get_contents( $file );
      if( $content === false ) return [];

      $loaded = [];
      foreach( self::parse( $content ) as $key => $value ){
         if( self::isForbiddenKey( $key ) ){
            trigger_error( 'Gojira_Env: chave ' . $key . ' recusada no .env', E_USER_WARNING );
            continue;
         }
         if( ! $override && ( isset( self::$vars[ $key ] ) || self::existsInEnvironment( $key ) ) ) continue;

         self::$vars[ $key ] = $value;
         $_ENV[ $key ]       = $value;
         $loaded[] = $key;
      }

      return $loaded;
   }


   /**
    * Carrega o primeiro arquivo existente da lista e devolve o caminho dele
    * (ou null se nenhum existir).
    */
   public static function loadFirst( array $files, bool $override = false ): ?string {
      foreach( $files as $file ){
         if( is_string( $file ) && $file !== '' && is_file( $file ) ){
            self::load( $file, $override );
            return $file;
         }
      }
      return null;
   }


   /**
    * O arquivo fica dentro do DOCUMENT_ROOT do servidor web? Sem
    * DOCUMENT_ROOT (CLI) ou com pasta inexistente, responde false.
    */
   public static function servedByWeb( string $file, array $server ): bool {
      if( empty( $server['DOCUMENT_ROOT'] ) || ! is_string( $server['DOCUMENT_ROOT'] ) ) return false;

      $root = realpath( $server['DOCUMENT_ROOT'] );
      $dir  = realpath( dirname( $file ) );
      if( $root === false || $dir === false ) return false;

      $root = rtrim( str_replace( '\\', '/', $root ), '/' ) . '/';
      $dir  = rtrim( str_replace( '\\', '/', $dir ), '/' ) . '/';
      return strncmp( $dir, $root, strlen( $root ) ) === 0;
   }


   /** Esquece os valores carregados (uso em testes). */
   public static function reset(): void {
      foreach( self::$vars as $key => $value ){
         if( isset( $_ENV[ $key ] ) && $_ENV[ $key ] === $value ) unset( $_ENV[ $key ] );
      }
      self::$vars = [];
   }


   /** PATH, LD_* e HTTP_* não podem vir do .env. */
   public static function isForbiddenKey( string $key ): bool {
      $upper = strtoupper( $key );
      return $upper === 'PATH' || strncmp( $upper, 'LD_', 3 ) === 0 || strncmp( $upper, 'HTTP_', 5 ) === 0;
   }


   /**
    * Interpreta o conteúdo de um .env. Linhas inválidas são ignoradas com
    * E_USER_WARNING que cita só o número da linha (nunca o conteúdo).
    */
   public static function parse( string $content ): array {

      if( strncmp( $content, "\xEF\xBB\xBF", 3 ) === 0 ) $content = substr( $content, 3 );

      $vars  = [];
      $lines = preg_split( '/\r\n|\n|\r/', $content );

      foreach( $lines as $n => $line ){
         $line = trim( $line );
         if( $line === '' || $line[0] === '#' ) continue;

         if( ! preg_match( '/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_.]*)\s*=\s*(.*)$/', $line, $m ) ){
            trigger_error( 'Gojira_Env: linha ' . ($n + 1) . ' do .env ignorada (formato inválido)', E_USER_WARNING );
            continue;
         }

         $value = self::parseValue( $m[2] );
         if( $value === null ){
            trigger_error( 'Gojira_Env: linha ' . ($n + 1) . ' do .env ignorada (aspas sem fechamento)', E_USER_WARNING );
            continue;
         }

         $vars[ $m[1] ] = $value;
      }

      return $vars;
   }


   /**
    * Lê o valor: .env carregado, getenv, $_ENV e $_SERVER, com valor padrão.
    * Chaves HTTP_* devolvem sempre o padrão: em CGI/FPM elas são cabeçalhos
    * da requisição, controlados pelo cliente.
    */
   public static function get( string $key, $default = null ){

      if( strncmp( strtoupper( $key ), 'HTTP_', 5 ) === 0 ) return $default;

      if( array_key_exists( $key, self::$vars ) ) return self::$vars[ $key ];

      $value = getenv( $key );
      if( $value !== false ) return $value;

      if( isset( $_ENV[ $key ] ) && is_scalar( $_ENV[ $key ] ) ) return (string) $_ENV[ $key ];
      if( isset( $_SERVER[ $key ] ) && is_scalar( $_SERVER[ $key ] ) ) return (string) $_SERVER[ $key ];

      return $default;
   }


   /** ENVIRONMENT é PROD ou PRODUCTION? */
   public static function isProduction( string $environment ): bool {
      return in_array( strtoupper( trim( $environment ) ), [ 'PROD', 'PRODUCTION' ], true );
   }


   /** GOJIRA_STRICT_CONFIG ligado (constante ou variável de ambiente)? */
   public static function strictConfig(): bool {
      if( defined( 'GOJIRA_STRICT_CONFIG' ) ) return (bool) constant( 'GOJIRA_STRICT_CONFIG' );
      $v = self::get( 'GOJIRA_STRICT_CONFIG' );
      return is_string( $v ) && in_array( strtolower( trim( $v ) ), [ '1', 'true', 'on', 'yes' ], true );
   }


   /**
    * Aplica a política de configuração insegura descrita no topo do arquivo.
    * Retorna o que foi feito: 'log', 'warning' (ou 'none' com $logInDev
    * false em DEV). Em PROD/PRODUCTION ou com a flag, lança RuntimeException.
    */
   public static function reportIssue( string $message, string $environment, bool $logInDev = true ): string {

      if( self::isProduction( $environment ) || self::strictConfig() ){
         throw new RuntimeException( $message );
      }

      if( strtoupper( trim( $environment ) ) === 'DEV' ){
         if( ! $logInDev ) return 'none';
         error_log( 'GOJIRA: ' . $message );
         return 'log';
      }

      error_log( 'GOJIRA: ' . $message );
      trigger_error( $message, E_USER_WARNING );
      return 'warning';
   }


   /** O valor é um placeholder conhecido (comparação sem diferenciar maiúsculas)? */
   public static function isPlaceholder( $value ): bool {
      if( ! is_string( $value ) || $value === '' ) return false;
      return in_array( strtolower( trim( $value ) ), self::PLACEHOLDERS, true );
   }


   /**
    * Confere credenciais contra os placeholders conhecidos e aplica a
    * política de reportIssue(). $values: nome legível (ex.: 'DB_PWD') => valor.
    * Devolve os nomes encontrados.
    */
   public static function checkPlaceholders( array $values, string $environment ): array {

      $found = [];
      foreach( $values as $name => $value ){
         if( self::isPlaceholder( $value ) ) $found[] = (string) $name;
      }

      if( $found ){
         self::reportIssue(
            'Configuração com valor de exemplo: ' . implode( ', ', $found )
            . '. Defina o valor real no .env ou nas variáveis de ambiente.',
            $environment
         );
      }

      return $found;
   }


   private static function existsInEnvironment( string $key ): bool {
      return getenv( $key ) !== false || array_key_exists( $key, $_ENV ) || array_key_exists( $key, $_SERVER );
   }


   /** Valor de uma linha; null quando as aspas não fecham. */
   private static function parseValue( string $raw ): ?string {

      if( $raw === '' ) return '';

      $quote = $raw[0];

      if( $quote === '"' ){
         $out = '';
         $len = strlen( $raw );
         for( $i = 1; $i < $len; $i++ ){
            $c = $raw[ $i ];
            if( $c === '\\' && $i + 1 < $len ){
               $next = $raw[ ++$i ];
               $map  = [ 'n' => "\n", 'r' => "\r", 't' => "\t", '"' => '"', '\\' => '\\' ];
               $out .= isset( $map[ $next ] ) ? $map[ $next ] : '\\' . $next;
               continue;
            }
            if( $c === '"' ) return self::restIsComment( substr( $raw, $i + 1 ) ) ? $out : null;
            $out .= $c;
         }
         return null;
      }

      if( $quote === "'" ){
         $end = strpos( $raw, "'", 1 );
         if( $end === false ) return null;
         return self::restIsComment( substr( $raw, $end + 1 ) ) ? substr( $raw, 1, $end - 1 ) : null;
      }

      // Sem aspas: comentário só depois de espaço, para aceitar '#' dentro do valor.
      $value = preg_replace( '/\s+#.*$/', '', $raw );
      return trim( $value );
   }


   private static function restIsComment( string $rest ): bool {
      $rest = trim( $rest );
      return $rest === '' || $rest[0] === '#';
   }

}
