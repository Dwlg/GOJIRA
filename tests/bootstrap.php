<?php
/*
   Bootstrap dos testes do GOJIRA.

   Carrega as classes do framework sem passar pelo system/init.php:
   sem session_start(), sem Smarty, sem Config e sem despacho de controller.
   Cada teste monta o $globals mínimo de que precisa.
*/

error_reporting(E_ALL);

define('GOJIRA_TESTS_ROOT', dirname(__DIR__) . '/');
define('GOJIRA_SYSTEM_PATH', GOJIRA_TESTS_ROOT . 'system/');

require_once GOJIRA_SYSTEM_PATH . 'class/gojiracore.class.php';
require_once GOJIRA_SYSTEM_PATH . 'class/tool.class.php';
require_once GOJIRA_SYSTEM_PATH . 'class/connection.class.php';
require_once GOJIRA_SYSTEM_PATH . 'class/model.class.php';

// routes.php só aplica as rotas quando $routes existe no escopo; aqui ele não existe.
require_once GOJIRA_SYSTEM_PATH . 'routes.php';

// Classes de apoio no namespace Gojira\Tests\ (PSR-4 sobre tests/).
spl_autoload_register(static function ($class) {
    $prefix = 'Gojira\\Tests\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

// Models de teste no namespace global (o Model deriva a tabela do nome da classe).
require_once __DIR__ . '/Support/RecordingModel.php';
