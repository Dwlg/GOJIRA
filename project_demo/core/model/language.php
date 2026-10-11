<?php
/*
   Language_Model - tabela `language` (ver project_demo/database/schema.sql).
   Sem comportamento próprio: o AppModel/Model genérico já cobre o que o
   configs.php precisa (getData, countData). Fica num arquivo próprio para o
   Model::Load('Language') do bootstrap encontrar a classe.
*/

      class Language_Model extends AppModel{

      }
