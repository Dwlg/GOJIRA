<?php

   class Engine extends GojiraCore{

      //Variáveis soltas que o template nunca recebe (filtro antigo, por trecho do nome)
      const TEMPLATE_HIDDEN_GLOBALS = '/db|database|conn|connection|smarty/i';

      //Chaves que nunca entram em {$globals}: configuração do banco (com a senha),
      //conexão e o próprio Smarty. Valem com e sem allowlist.
      const TEMPLATE_HIDDEN_KEYS = [ 'db', 'database', 'conn', 'connection', 'smarty' ];

      //Allowlist definida por Engine::setTemplateGlobals(); null segue a constante
      private static $templateGlobals = null;

      private static $deprecationsSent = [];


      //Aplica ao Smarty as opções de saída. Com GOJIRA_ESCAPE_HTML, toda variável
      //impressa no template é escapada ({$x nofilter} imprime sem escape).
      static function configureSmarty( $smarty ){
         if( defined('GOJIRA_ESCAPE_HTML') && GOJIRA_ESCAPE_HTML ){
            $smarty->setEscapeHtml( true );
         }
      }


      //Define quais chaves de $globals o template recebe (allowlist opt-in).
      //null volta a seguir GOJIRA_TEMPLATE_GLOBALS; sem as duas, o template recebe
      //o $globals inteiro, como antes (obsoleto).
      static function setTemplateGlobals( $keys ){
         self::$templateGlobals = ( $keys === null ? null : self::normalizeKeys( $keys ) );
      }


      //Allowlist em vigor, ou null quando o modo antigo vale
      static function templateGlobals(){
         if( self::$templateGlobals !== null ) return self::$templateGlobals;
         if( defined('GOJIRA_TEMPLATE_GLOBALS') ) return self::normalizeKeys( GOJIRA_TEMPLATE_GLOBALS );
         return null;
      }


      //Aceita array ou lista separada por vírgula
      private static function normalizeKeys( $keys ){
         if( !is_array( $keys ) ) $keys = explode( ',', (string)$keys );
         $ret = [];
         foreach( $keys as $key ){
            $key = trim( (string)$key );
            if( $key !== '' ) $ret[] = $key;
         }
         return array_values( array_unique( $ret ) );
      }


      //Passa as variáveis para o template. 'globals' é sempre uma cópia rasa de
      //$globals sem banco, conexão e Smarty. Sem allowlist (modo antigo, obsoleto),
      //a cópia tem as demais chaves e cada uma vira variável, como antes. Com
      //allowlist, só as chaves listadas. 'env' (environment, com caminhos absolutos
      //do servidor) continua sendo passado nos dois modos.
      static function assignTemplateVars( $smarty, $class, $proc, $param, $objData, $globals ){

         $allow = self::templateGlobals();

         $smarty->assign( 'class'      , $class );
         $smarty->assign( 'proc'       , $proc );
         $smarty->assign( 'param'      , $param );
         $smarty->assign( 'objData'    , $objData );

         $visible = new StdClass();
         foreach( $globals as $index => $obj ){
            if( !in_array( (string)$index, self::TEMPLATE_HIDDEN_KEYS, true ) && ( $allow === null || in_array( (string)$index, $allow, true ) ) ){
               $visible->$index = $obj;
            }
         }
         $smarty->assign( 'globals' , $visible );

         $smarty->assign( 'env'        , $globals->environment );

         foreach( $globals as $index => $obj ){
            if( $allow === null ? !preg_match( self::TEMPLATE_HIDDEN_GLOBALS, $index ) : ( in_array( (string)$index, $allow, true ) && !in_array( (string)$index, self::TEMPLATE_HIDDEN_KEYS, true ) ) ){
               $smarty->assign( $index, $globals->$index );
            }
         }
      }


      //Emite E_USER_DEPRECATED uma vez por requisição para cada recurso
      private static function deprecated( $feature, $message ){
         if( isset( self::$deprecationsSent[ $feature ] ) ) return;
         self::$deprecationsSent[ $feature ] = true;
         trigger_error( $message, E_USER_DEPRECATED );
      }


      static function Render( $class, $proc, $param ){

         $class   = strtolower( $class );
         $proc    = strtolower( $proc );
         $globals = $GLOBALS['globals'];

         try{

            //Esses valores devem vir do [systemPath]/init.php
            $objData = Controller::Call( $class, $proc, $param, $globals );

            //Retorna o view
            $template_ext = $globals->cfg->getConfig( PROJECT_ID . '_ENGINE', 'TEMPLATE_EXTENSION', '.html');
            if( isset( $objData->view_file ) ){
               $file = $objData->view_file . $template_ext;
            } else {
               $file = $class . '/' . $proc . $template_ext;
            }

            //Passa as variaveis para o template
            self::assignTemplateVars( $globals->smarty, $class, $proc, $param, $objData, $globals );


            //Renderiza o view
            if( file_exists( $globals->environment->viewPath . $file ) ){
               if( self::templateGlobals() === null ){
                  self::deprecated( 'template-globals', 'Engine: exposing the whole $globals to the template is deprecated; define GOJIRA_TEMPLATE_GLOBALS (allowlist) or call Engine::setTemplateGlobals()' );
               }
               return $globals->smarty->fetch( $file );
            } elseif( is_object($objData) || is_array($objData) ){
               return json_encode( $objData );
            } elseif( !is_object( $objData ) ){
               return $objData;
            }

         
         //Se der erro em alguma coisa, faz com que todos fiquem sabendo
         } catch( ControllerException $e ){
            
            if( Controller::Exists('Error') ){
               
               $error = Controller::Load( 'Error' );
               $param['class'] = $class;
               $param['proc']  = $proc;
               $param['error'] = $e;
               
               switch( $e->getCode() ){
                  case 0x1001:
                  case 0x1011:
                     header("HTTP/1.0 404 Not Found");
                     if( method_exists( $error, 'error_404' ) ){
                        echo Engine::Render( 'Error', 'error_404', $param );
                        exit;
                     } else {
                        throw( $e );
                     }
                     break;
                  case 0x1012:
                     header('HTTP/1.0 403 Forbidden');
                     if( method_exists( $error, 'error_403' ) ){
                        echo Engine::Render( 'Error', 'error_403', $param );
                        exit;
                     } else {
                        throw( $e );
                     }
                     break;
                  case 0x1002:
                     header('HTTP/1.1 500 Internal Server Error');
                     if( method_exists( $error, 'error_500' ) ){
                        echo Engine::Render( 'Error', 'error_500', $param );
                        exit;
                     } else {
                        throw( $e );
                     }
                     break;
                  default:
                     header('HTTP/1.1 500 Internal Server Error');
                     if( method_exists( $error, 'error_unknow' ) ){
                        echo Engine::Render( 'Error', 'error_unknow', $param );
                        exit;
                     } else {
                        throw( $e );
                     }
               }
            } else {
               header('HTTP/1.1 500 Internal Server Error');
               throw( $e );
            }

         } catch( Exception $e ){
            
            if( Controller::Exists('Error') ){
               
               $error = Controller::Load( 'Error' );
               $param['class'] = $class;
               $param['proc']  = $proc;
               $param['error'] = $e;
               
               header('HTTP/1.1 500 Internal Server Error');
               if( method_exists( $error, 'error_500' ) ){
                  echo Engine::Render( 'Error', 'error_500', $param );
               } elseif( method_exists( $error, 'error_unknow' ) ){
                  echo Engine::Render( 'Error', 'error_unknow', $param );
               } else {
                  throw( $e );
               }
            } else {
               throw( $e );
            }
         }
         
      }
      
      
   }
