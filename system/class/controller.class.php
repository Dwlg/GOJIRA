<?php

      //Controle de acesso deny-by-default (opt-in). Ver docs/specs/auth-redesign-spec.md (Fase 3).
      require_once( __DIR__ . '/auth/accesspolicy.interface.php' );

		abstract class Controller extends GojiraCore{

			//Propriedades da classe
			protected $globals;
         protected $model;

         //AccessPolicy global (opcional). Nula por padrão: sem registro explícito via
         //setAccessPolicy(), o comportamento de Controller::Call não muda em nada.
         protected static $accessPolicy;

			function __construct1( $globals ){

				$this->globals = $globals;
            $class         = substr( get_class( $this ), 0, -11 );

            //Se o model não existir, gera um exception, mas nesse instante, isso é previsto
            //por isso não deve deixar ele passar daqui.
            try{
               $this->model = Model::Load( $class , $globals );
				} catch( Exception $e ){
               $this->model = new AppModel( $globals );
            }

         }
         
         //Construtor alternativo que recebe um model pré-instanciado
         function __construct2( $globals, $model ){

            $this->globals = $globals;
            $this->model   = $model;

         }


			//Instancia um controller a parte
         static function Load( $class, $globals = ''){

            if( $globals == '' ){
               $globals = $GLOBALS['globals'];
            }

            //Impede path traversal / inclusão de arquivo arbitrário via nome vindo da URL
            if( !GojiraCore::isSafeName( $class ) ){
               throw( new ControllerException( "Invalid controller name", 0x1001 ) );
            }

            $file  = strtolower($class) . '.php';
            $class = ucwords(strtolower($class), "_0123456789") . '_Controller';
				$dir   = $globals->environment->controllerPath;

				//Se localizar o arquivo no disco (e confinado ao diretório esperado)
				$full = GojiraCore::confinedFile( $dir, $file );
				if( $full !== false ){

               //Instancia o objeto
					require_once( $full );
               
               if( class_exists( $class ) ){
                  $objController = new $class( $globals );
                  $objController->onLoad();
                  return $objController;
               
               } else {
                  //Classe declarada errada
                  throw( new ControllerException( "Wrong class declaration for controller <strong>\"" . $class . "\"</strong> in <strong>\"" . $dir . $file . "\"</strong>", 0x1002 ) );
               }
            } else {
					//Controller não localizado no disco
					throw( new ControllerException( "Controller <strong>\"" . $class . "\"</strong> not found in <strong>\"" . $dir . $file . "\"</strong>", 0x1001 ) );

				}

         }

			//Registra/obtém a AccessPolicy global (opt-in). Null = sem controle de acesso
			//além do beforeCall() de cada controller (comportamento atual, inalterado).
			static function setAccessPolicy( $policy ){
				self::$accessPolicy = $policy;
			}

			static function getAccessPolicy(){
				return self::$accessPolicy;
			}

			//Executa um método dentro do controller
			static function Call( $class, $method, $param, $globals = ''){


            if( $globals == '' ){
               $globals = $GLOBALS['globals'];
            }

            $ret           = '';
            $objController = Controller::Load( $class, $globals );

            //Só considera o método se ele existir E for público. method_exists() sozinho
            //enxerga métodos protected/private, permitindo executar via URL lógica que o
            //controller nunca pretendeu expor (achado F05 da auditoria de segurança).
            //Tratamos "existe mas não é público" igual a "não existe" (not_found/0x1011),
            //para não vazar quais métodos protegidos existem.
            $methodPublic = false;
            if( method_exists( $objController, $method ) ){
               try{
                  $methodPublic = (new ReflectionMethod( $objController, $method ))->isPublic();
               } catch( ReflectionException $e ){
                  $methodPublic = false;
               }
            }

            if( $methodPublic ){

               //Controle de acesso deny-by-default (opt-in - ver setAccessPolicy()).
               $policy = Controller::getAccessPolicy();

               if( $policy instanceof Gojira_AccessPolicy && ! $policy->isPublic( $class, $method ) && ! $policy->authorize( $class, $method, null ) ){

                  if( method_exists( $objController, 'unauthorized' ) ){
                     return call_user_func_array( array( $objController, 'unauthorized'), array( $method, $param ));
                  }

                  throw( new ControllerException( "Access denied for method <strong>\"" . $method . "\"</strong> in Controller Object <strong>\"" . $class . "\"</strong>", 0x1012 ) );
               }

               //Retorna o resultado da função dentro do model
               if( $objController->beforeCall( $class, $method, $param) !== false ){
                  $ret = call_user_func_array( array( $objController, $method), array( $param ));

               } else {

                  if( method_exists( $objController, 'unauthorized' ) ){
                     $ret = call_user_func_array( array( $objController, 'unauthorized'), array( $method, $param ));

                  } else {
                     //Execução não altorizada
                     throw( new ControllerException( "Unauthorized execution for method <strong>\"" . $method . "\"</strong> in Controller Object <strong>\"" . $class . "\"</strong>", 0x1012 ) );

                  }
               }

            } elseif( method_exists( $objController, 'not_found')  ) {
               $ret = call_user_func_array( array( $objController, 'not_found'), array( $method, $param ));

            } else {
               //Metodo não localizado dentro do controller (ou não público - ver acima)
               throw( new ControllerException( "Method <strong>\"" . $method . "\"</strong> not found in Controller Object <strong>\"" . $class . "\"</strong>", 0x1011 ) );
            }

            return $ret;

         }
         
         
         //Verifica se um controller existe
         static function Exists( $class ){
            
            $ret     = false;
            $globals = $GLOBALS['globals'];

            //Nome inválido nunca existe como controller
            if( !GojiraCore::isSafeName( $class ) ){
               return false;
            }

            $file    = strtolower($class) . '.php';
            $class   = ucwords(strtolower($class), "_0123456789") . '_Controller';
				$dir     = $globals->environment->controllerPath;

            //Se localizar o arquivo no disco (e confinado ao diretório esperado)
				$full = GojiraCore::confinedFile( $dir, $file );
				if( $full !== false ){

               require_once( $full );
            
               if( class_exists( $class ) ){
                  $ret = true;
               }
            }
            
            return $ret;
            
         }
            

         //Executa quando o objeto é instanciado
         function onLoad(){}


         //Executa antes do método chamado ser executado
         function beforeCall( $class, $method, $param ){
            return true;
         }


		}


		//Objeto de erro personalizadp
		class ControllerException extends Exception{

			function __construct( $message, $err_cod ){
				parent::__construct( $message, $err_cod );
			}

		}
