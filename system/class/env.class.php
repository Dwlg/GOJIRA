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

   Uma variável que já existe no ambiente (getenv ou $_ENV) não é
   sobrescrita, a menos que load() receba $override = true.

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


   /**
    * Carrega o arquivo no ambiente (putenv e $_ENV).
    * Retorna as chaves efetivamente definidas. Arquivo ausente: retorna [].
    */
   public static function load( string $file, bool $override = false ): array {

      if( ! is_file( $file ) || ! is_readable( $file ) ) return [];

      $content = file_get_contents( $file );
      if( $content === false ) return [];

      $loaded = [];
      foreach( self::parse( $content ) as $key => $value ){
         if( ! $override && self::exists( $key ) ) continue;

         putenv( $key . '=' . $value );
         $_ENV[ $key ] = $value;
         $loaded[] = $key;
      }

      return $loaded;
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


   /** Lê do ambiente (getenv, $_ENV, $_SERVER), com valor padrão. */
   public static function get( string $key, $default = null ){

      $value = getenv( $key );
      if( $value !== false ) return $value;

      if( isset( $_ENV[ $key ] ) && is_scalar( $_ENV[ $key ] ) ) return (string) $_ENV[ $key ];
      if( isset( $_SERVER[ $key ] ) && is_scalar( $_SERVER[ $key ] ) ) return (string) $_SERVER[ $key ];

      return $default;
   }


   /** O valor é um placeholder conhecido (comparação sem diferenciar maiúsculas)? */
   public static function isPlaceholder( $value ): bool {
      if( ! is_string( $value ) || $value === '' ) return false;
      return in_array( strtolower( trim( $value ) ), self::PLACEHOLDERS, true );
   }


   /**
    * Confere credenciais contra os placeholders conhecidos.
    * $values: nome legível (ex.: 'DB_PWD') => valor.
    * Fora de DEV lança RuntimeException citando os nomes; em DEV só devolve
    * a lista, para quem chamou registrar o aviso.
    */
   public static function checkPlaceholders( array $values, string $environment ): array {

      $found = [];
      foreach( $values as $name => $value ){
         if( self::isPlaceholder( $value ) ) $found[] = (string) $name;
      }

      if( $found && strtoupper( trim( $environment ) ) !== 'DEV' ){
         throw new RuntimeException(
            'Configuração com valor de exemplo fora do ambiente DEV: ' . implode( ', ', $found )
            . '. Defina o valor real no .env ou nas variáveis de ambiente.'
         );
      }

      return $found;
   }


   private static function exists( string $key ): bool {
      return getenv( $key ) !== false || array_key_exists( $key, $_ENV );
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
