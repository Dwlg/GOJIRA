<?php
/*
   Log_ComponentController - stub mínimo para o project_demo rodar de ponta a
   ponta. Grava no error_log do PHP, sem tabela própria. Um projeto real
   substitui este arquivo por um log persistido em banco (ou em arquivo
   dedicado), com rotação e nível de severidade.
*/

      class Log_ComponentController extends Component{

         //$level: 'info' (padrão), 'warning' ou 'error'; só identifica a
         //linha no error_log, não muda o comportamento.
         function write( $message, $level = 'info' ){
            error_log( '[' . PROJECT_ID . '][' . strtoupper( (string) $level ) . '] ' . (string) $message );
            return true;
         }

      }
