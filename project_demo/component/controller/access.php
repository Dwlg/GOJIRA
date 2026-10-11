<?php
/*
   Access_ComponentController - stub mínimo para o project_demo rodar de
   ponta a ponta. Não decide nada por si: delega ao controle de acesso do
   próprio framework (ACCESS_LEVEL em constants.php, Gojira_AccessPolicy via
   Controller::setAccessPolicy()). Um projeto real substitui este arquivo
   por regras específicas de permissão por tela/ação.
*/

      class Access_ComponentController extends Component{

         //Verifica se o nível informado está entre os níveis logados aceitos
         //pelo projeto (ver ACCESS_LEVEL em constants.php).
         function can( $level ){
            return $this->globals->login->isLogged( $level );
         }

      }
