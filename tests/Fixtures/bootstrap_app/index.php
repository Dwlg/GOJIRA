<?php
/*
   Front controller do projeto mínimo usado pelo BootstrapHttpTest.
   Roda no servidor embutido do PHP (php -S), numa cópia temporária desta pasta.

   As opções do framework vêm do cabeçalho X-Gojira-Test-Opts (só neste fixture),
   para cada requisição exercitar uma combinação diferente sem reiniciar o servidor.
*/

$opts = isset($_SERVER['HTTP_X_GOJIRA_TEST_OPTS']) ? explode(',', $_SERVER['HTTP_X_GOJIRA_TEST_OPTS']) : [];
foreach ($opts as $opt) {
    $opt = trim($opt);
    if ($opt === 'DEV') {
        define('ENVIRONMENT', 'DEV');
    } elseif ($opt === 'TRUST_LOCAL_PROXY') {
        define('GOJIRA_TRUSTED_PROXIES', '127.0.0.1');
    } elseif (preg_match('/^GOJIRA_[A-Z_]+$/', $opt)) {
        define($opt, true);
    }
}
if (!defined('ENVIRONMENT')) {
    define('ENVIRONMENT', 'PROD');
}

define('PROJECT_ID', 'HTTPTEST');
ini_set('display_errors', '1');
ini_set('html_errors', '0');
error_reporting(E_ALL);

$absPath = str_replace('\\', '/', __DIR__) . '/';

$globals                     = new stdClass();
$globals->database           = new stdClass();
$globals->database->host     = '';
$globals->database->user     = '';
$globals->database->password = '';
$globals->database->name     = '';

require getenv('GOJIRA_SYSTEM_PATH') . 'init.php';

echo Engine::Render($class, $proc, $param);
