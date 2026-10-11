<?php

      class Home_Controller extends AppController{

         function index( $param ){

         }

         //Alias de index(): a rota raiz do projeto ('' em routes.php) cai
         //aqui ("Splash Screen", ver o comentário de routes.php). Sem este
         //método a página inicial respondia 404, porque o Home_Controller
         //só tinha index().
         function splash( $param ){
            return $this->index( $param );
         }

      }