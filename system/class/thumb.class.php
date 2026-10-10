<?php
/*
   Gojira_Thumb - validação da entrada do system/thumb.php.

   - O arquivo pedido precisa existir dentro da raiz do projeto (pasta acima de
     system/, ou GOJIRA_THUMB_ROOT), conferido com realpath().
   - URL remota (http/https) só com a variável de ambiente GOJIRA_THUMB_ALLOW_REMOTE.
     Qualquer outro wrapper de stream é recusado.
   - Largura, altura e tamanho máximo limitados a GOJIRA_THUMB_MAX_SIZE (padrão 4000 px).
   - Só abre JPEG, PNG e GIF, identificados pelo conteúdo (getimagesize), não pela extensão.
*/

   class Gojira_Thumb_Exception extends Exception{

      //Código HTTP sugerido para a resposta
      public function getStatus(){
         return $this->getCode() > 0 ? $this->getCode() : 400;
      }
   }


   class Gojira_Thumb{

      const DEFAULT_MAX_SIZE = 4000;

      //Tipos aceitos: IMAGETYPE_* => mime
      private static $types = array(
         IMAGETYPE_JPEG => 'image/jpeg',
         IMAGETYPE_PNG  => 'image/png',
         IMAGETYPE_GIF  => 'image/gif',
      );


      //Raiz permitida: GOJIRA_THUMB_ROOT ou a pasta acima de system/.
      //$systemDir é a pasta do thumb.php como o servidor a vê (sem resolver links simbólicos).
      public static function root( $systemDir = null ){

         $root = getenv( 'GOJIRA_THUMB_ROOT' );
         if( $root === false || $root === '' ){
            $root = dirname( $systemDir === null ? dirname( __DIR__ ) : $systemDir );
         }

         $real = realpath( $root );

         return $real === false ? null : $real;
      }


      public static function allowRemote(){

         $env = getenv( 'GOJIRA_THUMB_ALLOW_REMOTE' );

         return $env !== false && in_array( strtolower( trim( $env ) ), array( '1', 'true', 'on', 'yes' ), true );
      }


      public static function maxSize(){

         $env = getenv( 'GOJIRA_THUMB_MAX_SIZE' );

         return ( $env !== false && ctype_digit( $env ) && (int) $env > 0 ) ? (int) $env : self::DEFAULT_MAX_SIZE;
      }


      //Resolve o valor de ?img= num caminho local confinado à raiz (ou numa URL, se permitido).
      //Caminho relativo é resolvido a partir de $baseDir (a pasta do thumb.php).
      public static function resolveSource( $img, $root, $baseDir, $allowRemote = false ){

         if( ! is_string( $img ) || $img === '' ){
            throw new Gojira_Thumb_Exception( 'Imagem não informada.', 400 );
         }

         if( strpos( $img, "\0" ) !== false ){
            throw new Gojira_Thumb_Exception( 'Caminho inválido.', 400 );
         }

         //Qualquer esquema (http:, php:, phar:, data:, file: ...) passa por aqui
         if( preg_match( '#^([a-z][a-z0-9+.\-]*):#i', $img, $m ) && ! preg_match( '#^[a-z]:[\\\\/]#i', $img ) ){

            $scheme = strtolower( $m[1] );

            if( $scheme === 'http' || $scheme === 'https' ){

               static $warned = false;
               if( ! $warned ){
                  $warned = true;
                  trigger_error( 'thumb.php com URL remota está descontinuado; a URL só é aceita com GOJIRA_THUMB_ALLOW_REMOTE.', E_USER_DEPRECATED );
               }

               if( $allowRemote && filter_var( $img, FILTER_VALIDATE_URL ) !== false ){
                  return $img;
               }
            }

            throw new Gojira_Thumb_Exception( 'Origem da imagem não permitida.', 404 );
         }

         //Remove a query string que alguns projetos anexam ao nome (?v=123)
         if( strpos( $img, '?' ) > 0 ){
            $img = substr( $img, 0, strpos( $img, '?' ) );
         }

         if( $root === null ){
            throw new Gojira_Thumb_Exception( 'Raiz do projeto não encontrada.', 404 );
         }

         $isAbsolute = substr( $img, 0, 1 ) === '/' || preg_match( '#^[a-z]:[\\\\/]#i', $img );
         $path       = $isAbsolute ? $img : rtrim( $baseDir, '/\\' ) . DIRECTORY_SEPARATOR . $img;
         $real       = realpath( $path );
         $root       = rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR;

         if( $real === false || ! is_file( $real ) || strncmp( $real, $root, strlen( $root ) ) !== 0 ){
            throw new Gojira_Thumb_Exception( 'Imagem não encontrada.', 404 );
         }

         return $real;
      }


      //Identifica o tipo pelo conteúdo. Só JPEG, PNG e GIF.
      public static function detectType( $source ){

         $info = @getimagesize( $source );

         if( $info === false || ! isset( self::$types[ $info[2] ] ) ){
            throw new Gojira_Thumb_Exception( 'Formato de imagem não suportado.', 415 );
         }

         return array(
            'width'  => (int) $info[0],
            'height' => (int) $info[1],
            'mime'   => self::$types[ $info[2] ],
         );
      }


      //Converte um parâmetro de dimensão em inteiro entre 0 e $max.
      //Vazio vira 0 (não informado). Valor não numérico, negativo ou acima do limite é recusado.
      public static function dimension( $value, $max ){

         if( $value === null || $value === '' || $value === 0 || $value === '0' ){
            return 0;
         }

         if( is_int( $value ) ){
            $value = (string) $value;
         }

         if( ! is_string( $value ) || ! ctype_digit( $value ) ){
            throw new Gojira_Thumb_Exception( 'Dimensão inválida.', 400 );
         }

         $value = (int) $value;
         if( $value > $max ){
            throw new Gojira_Thumb_Exception( 'Dimensão acima do limite de ' . $max . ' px.', 400 );
         }

         return $value;
      }
   }
