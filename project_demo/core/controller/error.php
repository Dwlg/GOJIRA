<?php
/*
   Error_Controller - sem ele, o Engine responde 500 a qualquer rota
   inexistente ou método não despachável (Controller::Exists('Error') volta
   false e a ControllerException original é relançada). Com ele, 404/403/500
   saem com o status HTTP correto e uma página genérica, sem caminho de
   arquivo, SQL ou mensagem interna (o detalhe vai só para o error_log,
   feito pelo próprio Engine::jsonError/Render antes de chegar aqui).
*/

      class Error_Controller extends AppController{

         function error_404( $param ){
            return [];
         }

         function error_403( $param ){
            return [];
         }

         function error_500( $param ){
            return [];
         }

      }
