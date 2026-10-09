<?php
/*
   Authdemo_Controller - Exemplo de referência (não é parte do fluxo padrão do
   demo) mostrando como usar a camada de autenticação das Fases 2-4 do
   redesenho: Guard, AccessPolicy (deny-by-default) e remember-me.

   Nada aqui é chamado automaticamente - é só leitura/cópia. Requer uma base
   com as tabelas `user`/`user_type` (ver constants.php) para efetivamente
   autenticar; o arquivo carrega normalmente mesmo sem banco configurado.

   Guia completo: docs/migration/auth-redesign-migration-guide.md
*/

      class Authdemo_Controller extends AppController{

         //--- Controle de acesso deny-by-default (Fase 3) -----------------------
         //Só entra em vigor se o projeto registrar uma AccessPolicy (ver o guia de
         //migração - normalmente em configs.php/appController::onLoad()). 'index' e
         //'login' ficam públicos; 'painel' e 'logout' exigem o papel ADM logado.
         public static $access = [
            'public'   => ['index', 'login'],
            'requires' => ['ADM'],
         ];


         //Tela/endpoint de login comum (usuário + senha).
         //Ex.: POST authdemo/login/user/admin/pass/minhasenha/remember/1
         function login( $param ){

            $guard    = $this->globals->login->guard('ADM');
            $remember = !empty( $param['remember'] );

            try{
               $user = $guard->attempt( $param['user'] ?? '', $param['pass'] ?? '', [], $remember );

               return [ 'success' => 1, 'msg' => 'Bem-vindo, ' . $user->getAuthLogin() ];

            //Gojira_AuthException carrega um código estável (getAuthCode()), útil
            //para a UI decidir a mensagem sem fazer parsing de texto.
            } catch( Gojira_AuthException $e ){

               return [ 'success' => 0, 'msg' => $e->getMessage(), 'code' => $e->getAuthCode() ];
            }
         }


         //Painel restrito - só chega aqui se a AccessPolicy autorizar (papel ADM).
         function painel( $param ){

            $guard = $this->globals->login->guard('ADM');
            $user  = $guard->user();

            return [ 'success' => 1, 'login' => $user ? $user->getAuthLogin() : null ];
         }


         //Encerra a sessão (e qualquer remember-me ativo, automaticamente).
         function logout( $param ){

            $this->globals->login->guard('ADM')->logout();

            return [ 'success' => 1 ];
         }


         //Tenta reabrir a sessão a partir do cookie de remember-me, se existir.
         //Normalmente chamado uma vez no bootstrap do projeto, não por rota.
         function index( $param ){

            $guard = $this->globals->login->guard('ADM');

            if( ! $guard->check() ){
               $guard->attemptFromRememberCookie();
            }

            return [ 'logged' => $guard->check() ];
         }

      }
