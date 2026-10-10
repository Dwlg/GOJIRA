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
                  $this->save();
               }
            }
            
				return $ret;
				
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

               foreach( $this->cache as $i => $a ){
                  foreach( $a as $ii => $aa ){
                     $this->cache[$i][$ii] = urldecode($aa);
                  }
               }

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

            $data = parse_ini_string( (string) $content, true );
            return is_array( $data ) ? $data : array();

         }


         //Salva o cache devolta no arquivo, com trava exclusiva (LOCK_EX)
         function save(){

            $content = '';
            foreach( $this->cache as $ci => $ck ){
               if( is_array( $ck ) && count( $ck ) > 0 ){
                  $content .= "\n[" . $ci . "]\n";
                  foreach( $ck as $ki => $kk ){
                     $content .= $ki . '="' . urlencode($kk) . "\"\n";
                  }
               }
            }

            return file_put_contents( $this->file, $content, LOCK_EX ) !== false;

         }
			
			
		} 
   
   