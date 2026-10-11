<?php
/*
   Mail_Sender_ComponentController - stub mínimo para o project_demo rodar de
   ponta a ponta. configure() só guarda os parâmetros SMTP (chamado pelo
   configs.php no bootstrap); send() não entrega e-mail de fato, registra no
   log. Um projeto real substitui este arquivo por um envio via PHPMailer
   (ver system/class/tool/network.class.php), usando os mesmos parâmetros.
*/

      class Mail_Sender_ComponentController extends Component{

         private $config = [];

         function configure( array $config ){
            $this->config = $config;
            return $this;
         }

         function send( $to, $subject, $body ){
            error_log( '[' . PROJECT_ID . '][mail_sender stub] para=' . (string) $to . ' assunto=' . (string) $subject );
            return true;
         }

      }
