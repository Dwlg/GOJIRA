<?php
/* Config.class.php - Classe que gerencia as configurações do sistema
   Permite criar nova config, recuperar ou setar o valor de varias delas
	criar tabela e verificar se a config existe
	
	
   por Carlson A. Soares - 2011-08-03 12:46
   
   Ultimo Update:
	
*/


		class Config extends GojiraCore{
    
         private $cache;
         private $file;
         private $dirty = array();   //Chaves alteradas por este processo desde o último save()

         const DEFAULT_TIMEZONE = 'America/Sao_Paulo';


         function __construct1( $appRoot ){
            $this->file = $appRoot . 'app_configs.ini';
            $this->create();
         }
			
         
        
        
        /***   Metodos do Objeto ***/
        
			//Seta configurações
			function setConfig( $class, $id, $val ){
				
				if( empty( $class ) || empty( $id ) ){
					return false;
				}
				
            if( ! isset( $this->cache[ $class ] ) ) $this->cache[ $class ] = array();
				
            $this->cache[ $class ][ $id ] = $val;
            $this->dirty[ $class ][ $id ] = $val;
            $this->save();
            
				return true;
				
			}
			
			
			
			//Busca configuraçoes da base de dados
			function getConfig( $class, $id = '', $def = null ){
				
				$ret = $def;
				
            //Classe é obrigatório
				if( empty( $class ) ) return;
				
            //Se não existe, inicia a classe
            if( ! isset( $this->cache[ $class ] ) ) $this->cache[ $class ] = array();

            //Se o id não for informado, retorna toda a classe
            if( empty( $id ) ){
               $ret = $this->cache[ $class ];
            
            } else {
               //Se o valor existe, retorna ele
               if( isset( $this->cache[ $class ][ $id ] ) ){
                  $ret = $this->cache[ $class ][ $id ];
               
               //Caso contrario, cria no arquivo
               } elseif( !is_null( $def ) ) {
                  $this->cache[ $class ][ $id ] = $def;
                  $this->dirty[ $class ][ $id ] = $def;
                  $this->save();
               }
            }
            
				return $ret;
				
			}
			
			
			
			//Aplica o timezone do PHP e o lc_time_names do banco a partir de uma seção
			//(no init.php, PROJECT_ID . '_ENGINE'). Chaves TIMEZONE e LC_TIME_NAMES; ausentes,
			//valem os padrões America/Sao_Paulo e pt_BR. Valor inválido gera E_USER_WARNING
			//e cai no padrão. Não grava as chaves no arquivo.
			function applyLocale( $class ){

            $timezone = $this->getConfig( $class, 'TIMEZONE' );
            if( empty( $timezone ) || ! is_string( $timezone ) ) $timezone = self::DEFAULT_TIMEZONE;

            if( ! @date_default_timezone_set( $timezone ) ){
               trigger_error( sprintf( "TIMEZONE '%s' inválido em [%s]; usando %s", $timezone, $class, self::DEFAULT_TIMEZONE ), E_USER_WARNING );
               date_default_timezone_set( self::DEFAULT_TIMEZONE );
            }

            $lcTimeNames = $this->getConfig( $class, 'LC_TIME_NAMES' );
            try{
               Connection::setLcTimeNames( is_string( $lcTimeNames ) ? $lcTimeNames : null );
            } catch( InvalidArgumentException $e ){
               trigger_error( sprintf( "LC_TIME_NAMES inválido em [%s]; usando %s", $class, Connection::DEFAULT_LC_TIME_NAMES ), E_USER_WARNING );
               Connection::setLcTimeNames( null );
            }

			}



			//Cria a tabela na base de dados, se ainda não existir
			function create(){
				
            //Se não existir, cria um novo
            if( ! file_exists( $this->file ) ){
               $this->cache = array();
               touch( $this->file );
            
            //Carrega todo o arquivo config na memória
            } else {
               $this->cache = $this->read();

            }
				
			}
         
         
         //Lê o arquivo com trava compartilhada, para não ver um save() pela metade
         private function read(){

            $f = @fopen( $this->file, 'r' );
            if( $f === false ) return array();

            flock( $f, LOCK_SH );
            $content = stream_get_contents( $f );
            flock( $f, LOCK_UN );
            fclose( $f );

            return $this->decode( (string) $content );

         }


         //Converte o conteúdo do ini em array, com os valores decodificados
         private function decode( $content ){

            $data = parse_ini_string( $content, true );
            if( ! is_array( $data ) ) return array();

            foreach( $data as $i => $a ){
               if( ! is_array( $a ) ) continue;
               foreach( $a as $ii => $aa ){
                  $data[$i][$ii] = urldecode($aa);
               }
            }
            return $data;

         }


         //Converte o array no conteúdo do ini
         private function encode( $data ){

            $content = '';
            foreach( $data as $ci => $ck ){
               if( is_array( $ck ) && count( $ck ) > 0 ){
                  $content .= "\n[" . $ci . "]\n";
                  foreach( $ck as $ki => $kk ){
                     $content .= $ki . '="' . urlencode($kk) . "\"\n";
                  }
               }
            }
            return $content;

         }


         //Salva no arquivo com trava exclusiva (LOCK_EX). Dentro da trava, relê o
         //arquivo e aplica só as chaves alteradas aqui, para não apagar o que outro
         //processo gravou depois que este carregou o cache.
         function save(){

            $f = @fopen( $this->file, 'c+' );
            if( $f === false ) return false;

            if( ! flock( $f, LOCK_EX ) ){
               fclose( $f );
               return false;
            }

            $data = $this->decode( (string) stream_get_contents( $f ) );
            foreach( $this->dirty as $ci => $ck ){
               foreach( $ck as $ki => $kk ){
                  if( ! isset( $data[ $ci ] ) || ! is_array( $data[ $ci ] ) ) $data[ $ci ] = array();
                  $data[ $ci ][ $ki ] = $kk;
               }
            }

            $ok = ftruncate( $f, 0 ) && rewind( $f ) && fwrite( $f, $this->encode( $data ) ) !== false && fflush( $f );

            flock( $f, LOCK_UN );
            fclose( $f );

            if( $ok ){
               $this->cache = $data;
               $this->dirty = array();
            }
            return $ok;

         }
			
			
		} 
   
   