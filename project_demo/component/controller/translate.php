<?php
/*
   Translate_ComponentController - stub mínimo para o project_demo rodar de
   ponta a ponta. Não lê tabela nenhuma: devolve a própria chave (ou o padrão
   informado) quando não há tradução configurada. Um projeto real substitui
   este arquivo por uma implementação que usa a tabela `language` e um
   dicionário de termos.
*/

      class Translate_ComponentController extends Component{

         //$key: identificador do termo. $default: usado quando informado;
         //sem ele, devolve a própria chave (facilita notar o que falta traduzir).
         function get( $key, $default = null ){
            return $default !== null ? $default : $key;
         }

      }
