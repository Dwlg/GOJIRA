<?php
/* Quando uma classe de auxilio for instanciada, verifica se ela está em uma dessas pastas, então da include na mesma
   ps: Só funciona em PHP5 ou superior;
*/

   //Trabalha com os helpers
   //O nome da classe pode vir de entrada externa (class_exists com nome montado a
   //partir da URL, por exemplo). Por isso cada segmento do namespace precisa ser
   //um nome seguro (GojiraCore::isSafeName) e o arquivo precisa estar dentro do
   //diretório onde é procurado (GojiraCore::confinedFile, que também recusa link
   //simbólico apontando para fora).
   function helper_loader($className, $lRec = false) {
      
      $env       = $GLOBALS['globals']->environment;
      $className = str_replace( "\\","/", $className );

      foreach( explode( '/', $className ) as $segment ){
         if( !GojiraCore::isSafeName( $segment ) ){
            return;
         }
      }

      foreach( array( $env->vendorPath, $env->systemIncPath, $env->systemVendorPath ) as $dir ){
         $full = GojiraCore::confinedFile( $dir, $className . '.php' );
         if( $full !== false ){
            require_once( $full );
            return;
         }
      }

      if( ! $lRec ){
         helper_loader( strtolower( $className ), true );
      }
      
   }
   
   spl_autoload_register( 'helper_loader' );