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
// E_USER_DEPRECATED fora da tela: os avisos de transição do framework (ex.: $globals
// inteiro no template) têm teste próprio em TemplateRenderTest e não fazem parte do
// corpo HTTP comparado aqui. Qualquer outro erro continua aparecendo no corpo.
error_reporting(E_ALL & ~E_USER_DEPRECATED);

$absPath = str_replace('\\', '/', __DIR__) . '/';

// URL base fixa, como numa aplicação em PROD: sem ela o init.php recusa o boot
// com BASE_URL detectado pelo Host e sem TRUSTED_HOSTS (Gojira_Host).
$baseURL = 'http://127.0.0.1/';

$globals                     = new stdClass();
$globals->database           = new stdClass();
$globals->database->host     = '';
$globals->database->user     = '';
$globals->database->password = '';
$globals->database->name     = '';

require getenv('GOJIRA_SYSTEM_PATH') . 'init.php';

echo Engine::Render($class, $proc, $param);
