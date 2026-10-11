<?php
/*	GojiraCore.class.php - Extende as funcionalidades de um
	objeto para que possa trabalhar com as propriedades de
	forma mais segura. usando emcapsulamento, além de facilitar
   o uso do GLOBALS - BASEADO NO propert.class.php.

	por Carlson - 2016-07-12
*/


	abstract class GojiraCore{


      /***   Construtores ***/
      function __construct(){

         $args = func_get_args();
         $argn = func_num_args();

         if( $argn > 0 ){
            if (method_exists($this, $function = '__construct'.$argn)) {
               call_user_func_array(array($this,$function),$args);
            } else {
               throw( new Exception('Numero de parametros invalido') );
            }
         }
      }



      //Busca propriedades inexistentes dentro do globals
      function __get( $index ){

         //Funcionalidade original, para trabalhar com funções set/get
         if (method_exists($this, ($method = 'get_'.$index))) {
				return $this->$method();;

			} else {

            //Nova funcionálidade - Verifica se a propriedade existe
            //Se não existir, verifica se está dentro do globals
            //Se não tiver, retorna os erros padrões do PHP

            $stack = debug_backtrace();

            try{
               //Verifica se é uma propriedade private
               $rp = new ReflectionProperty(get_class($this),$index);
               if( $rp->isPrivate() ){
                  trigger_error('Uncaught Error: Cannot access private property ' . get_class($this) . '::' . $index . ' called from ' . $stack[0]['file'] . ' on line ' . $stack[0]['line'], E_USER_ERROR);

               //Se não for private, retorna a propriedade
               } else {
                  return $this->$index;
               }

            //Só chega aqui se a propriedade não existir
            }	catch( Exception $e ){

               //Se ela existe dentro do globals (E o globals existe), retorna
               if( isset( $this->globals ) && isset( $this->globals->$index ) ){
                  return $this->globals->$index;

               //Caso não exista a propriedade, dispara o erro padrão do PHP
               } else {
                  trigger_error('Undefined property ' . get_class($this) . '::' . $index . ' called from ' . $stack[0]['file'] . ' on line ' . $stack[0]['line']);

               }
            }
         }

         return null;
      }


		//Verifica se uma propriedade existe no objeto
		public function __isset($name) {
			if (method_exists($this, ($method = 'isset_'.$name))) {
				return $this->$method();
			}
			else return;
		}


		//Define um valor a uma propriedade
		public function __set($name, $value) {

			if (method_exists($this, ($method = 'set_'.$name))) {
				$this->$method($value);
			}
		}

		//Elimina uma propriedade
		public function __unset($name) {

			if (method_exists($this, ($method = 'unset_'.$name))) {
				$this->$method();
			}
		}


		//Valida um nome de classe/componente/model para uso seguro em caminhos de arquivo.
		//Impede path traversal (../) e inclusão de arquivos arbitrários via nome vindo da URL.
		//Nomes válidos contêm apenas letras, números e underscore.
		//\A e \z ancoram a string inteira ($ aceitaria uma quebra de linha no final).
		public static function isSafeName( $name ){
			return is_string( $name ) && $name !== '' && preg_match('/\A[A-Za-z0-9_]+\z/', $name) === 1;
		}


		//Resolve $dir/$file e devolve o caminho real somente se o arquivo existir
		//dentro de $dir (links simbólicos para fora do diretório são recusados).
		//Devolve false caso contrário. Há sempre um único separador entre $dir e
		//$file, com ou sem barra final em $dir.
		public static function confinedFile( $dir, $file ){
			$dir  = (string) $dir;
			$path = ( $dir === '' ? '' : rtrim( $dir, '/\\' ) . '/' ) . ltrim( (string) $file, '/\\' );

			$base = realpath( $dir );
			$real = realpath( $path );

			if( $base === false || $real === false || !is_file( $real ) ){
				return false;
			}

			$base = rtrim( $base, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;

			return strpos( $real, $base ) === 0 ? $real : false;
		}


		//Retorna o caminho absoluto atual
		public function __absPath(){
			return substr( __FILE__, 0, strlen( __FILE__ ) - strlen( end( explode( "/", __FILE__ ) ) ) );
		}

	}
