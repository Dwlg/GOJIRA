<?php

class Demo_Controller extends AppController
{
    public function data($p)
    {
        return ['ok' => true, 'n' => 1];
    }

    public function state($p)
    {
        return [
            'smarty_loaded'  => class_exists('Smarty\\Smarty', false),
            'session_active' => session_status() === PHP_SESSION_ACTIVE,
        ];
    }

    // Devolve um parâmetro da URL dentro de um array (eco de entrada).
    public function echo($p)
    {
        return ['v' => $p['v'] ?? null];
    }

    public function text($p)
    {
        return 'plain text';
    }

    // Abre a sessão sob demanda e grava nela, como o Login faz ao autenticar.
    public function login($p)
    {
        $started          = Gojira_Session::start();
        $_SESSION['seen'] = true;

        return ['started' => $started];
    }

    // Reabre a sessão só se o navegador mandou o cookie, como o Login faz ao ler.
    public function resume($p)
    {
        $resumed = Gojira_Session::resume();

        return ['resumed' => $resumed, 'seen' => $resumed ? ($_SESSION['seen'] ?? null) : null];
    }

    // Erro interno com SQL, caminho e HTML na mensagem: nada disso pode sair na resposta JSON.
    public function boom($p)
    {
        throw new Exception('Falha: SELECT secret_col FROM t_secret em /srv/app/core/model/x.php <b>x</b>');
    }

    // Tem template em core/view/demo/view.html.
    public function view($p)
    {
        return ['v' => 1];
    }

    public function secret($p)
    {
        return ['never' => true];
    }

    public function beforeCall($class, $method, $param)
    {
        return $method !== 'secret';
    }
}
