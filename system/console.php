<?php
/*
   Console do GOJIRA.

   Desligado por padrão. Só roda em linha de comando e com a opção
   GOJIRA_ENABLE_CONSOLE (constante definida antes deste arquivo ou variável
   de ambiente). Fora disso responde 404.

   Uso:
      GOJIRA_ENABLE_CONSOLE=1 php system/console.php <comando> [parametros]
*/

   require_once( __DIR__ . '/class/gojiracore.class.php' );
   require_once( __DIR__ . '/class/tool.class.php' );
   require_once( __DIR__ . '/class/console.class.php' );

   if( ! Console::isEnabled( PHP_SAPI ) ){

      if( PHP_SAPI === 'cli' ){
         fwrite( STDERR, "Console desligado. Defina GOJIRA_ENABLE_CONSOLE=1 para usar.\n" );
         exit( 1 );
      }

      http_response_code( 404 );
      exit;
   }

   $cmd = implode( ' ', array_slice( $argv, 1 ) );
   if( $cmd === '' ){
      fwrite( STDERR, "Uso: php console.php <comando> [parametros]\n" );
      exit( 1 );
   }

   $console = new Console( new Tool() );

   echo str_replace( '<br />', '', $console->execute( $cmd ) ), PHP_EOL;
