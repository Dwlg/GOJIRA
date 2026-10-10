# Configuração: `.env`, `BASE_URL` e hosts confiáveis

Tudo nesta página é opcional. Um projeto que não cria `.env` nem define as variáveis abaixo funciona como antes.

## `.env` opcional

`Gojira_Env` (`system/class/env.class.php`) lê um arquivo `.env` simples, sem dependência externa. O framework não chama `Gojira_Env::load()` por conta própria: quem decide é o projeto. O `project_demo/configs.php` faz assim:

```php
require_once( __DIR__ . '/' . SYSDIR_NAME . 'class/env.class.php' );
Gojira_Env::load( __DIR__ . '/.env' );          // arquivo ausente: não faz nada
require_once( __DIR__ . '/constants.php' );
```

E o `constants.php` usa o valor do ambiente com o antigo valor do `define()` como fallback:

```php
define('DB_PWD', Gojira_Env::get('DB_PWD', 'db_host'));
```

Formato aceito: `CHAVE=valor`, `export CHAVE=valor`, aspas duplas (com `\n`, `\r`, `\t`, `\"` e `\\`), aspas simples (literal), comentário em linha própria (`# ...`) ou depois de um espaço (`valor # ...`). Não há interpolação nem valor em várias linhas. Uma linha inválida é ignorada com `E_USER_WARNING` que cita só o número da linha.

Precedência: uma variável já definida no ambiente do servidor (`getenv` ou `$_ENV`, por exemplo `SetEnv` no Apache ou `fastcgi_param`/`env` no PHP-FPM) **não** é sobrescrita pelo `.env`. Para forçar, `Gojira_Env::load($arquivo, true)`.

Modelo: `project_demo/.env.example`. O `.env` e o `app_configs.ini` estão no `.gitignore`; nunca versione um `.env` com valor real.

## Credenciais de exemplo

No boot, o `init.php` compara o usuário e a senha do banco (`$globals->database->user` e `->password`, que no demo vêm de `DB_USER` e `DB_PWD`) com uma lista de valores de exemplo (`db_user`, `db_host`, `db_pwd`, `changeme` e outros, em `Gojira_Env::PLACEHOLDERS`).

| `ENVIRONMENT` | Valor de exemplo encontrado |
|---|---|
| `DEV` (ou constante não definida) | Uma linha de aviso no `error_log`; o boot segue |
| Qualquer outro | `RuntimeException` que cita a constante; o boot para |

## `BASE_URL`

O `BASE_URL` (host e caminho, sem protocolo, terminado em `/`) é resolvido nesta ordem:

1. `$baseURL` definido pelo projeto antes do `init.php`. Continua gravado no `app_configs.ini`, mas só quando difere do valor do arquivo.
2. Variável de ambiente `GOJIRA_BASE_URL` (aceita com ou sem `http(s)://`).
3. `BASE_URL` da seção `[<PROJECT_ID>_ENGINE]` do `app_configs.ini`.
4. Em CLI, `cli://`.
5. Detectado da requisição: `SERVER_NAME` mais a pasta do script de entrada (`SCRIPT_NAME`).

**Mudança:** o valor detectado no passo 5 vale só para a requisição corrente e não é mais gravado no `app_configs.ini`. Antes, o `Host` da primeira requisição ficava gravado para sempre. Quem já tem `BASE_URL` no `app_configs.ini` continua com ele. Se esse valor veio de um `Host` indevido, apague a chave ou defina `GOJIRA_BASE_URL`, que tem precedência sobre o arquivo.

O `app_configs.ini` passou a ser gravado com trava exclusiva (`LOCK_EX`) e lido com trava compartilhada.

## Hosts confiáveis (`TRUSTED_HOSTS`)

Lista separada por vírgula, na variável `GOJIRA_TRUSTED_HOSTS` ou na chave `TRUSTED_HOSTS` da seção `[<PROJECT_ID>_ENGINE]` do `app_configs.ini` (a variável tem precedência).

- Vazia (padrão): qualquer `Host` é aceito, como antes.
- Preenchida: uma requisição web cujo `Host` não está na lista recebe `400 Bad Request` antes do despacho. A CLI não é afetada.
- `exemplo.com` casa só o host exato; `.exemplo.com` casa o domínio e os subdomínios; `*.exemplo.com` casa só os subdomínios. A porta é ignorada.

Em produção, recomenda-se definir `GOJIRA_BASE_URL` e `GOJIRA_TRUSTED_HOSTS`.

## O `.env` nunca pode ser servido pela web

Mantenha o `.env` na pasta do projeto (ao lado do `configs.php`), nunca dentro do `webroot/`.

**Apache.** O `project_demo/.htaccess` nega qualquer arquivo iniciado por ponto (`.env`, `.env.example`, `.git*`) e qualquer `.ini` (`app_configs.ini`), em qualquer subpasta, com `FilesMatch`, que não depende do `mod_rewrite`. Copie esse bloco para o `.htaccess` do seu projeto. Se o servidor tiver `AllowOverride None`, ponha o mesmo bloco no `VirtualHost`.

**nginx.** O nginx não lê `.htaccess`. No `server` do projeto, antes das demais `location` (expressões regulares são avaliadas na ordem em que aparecem, e a primeira que casa vence; por isso estas vêm antes da `location ~ \.php$`):

```nginx
# Arquivos iniciados por ponto (.env, .git, .htaccess), exceto .well-known
location ~ /\.(?!well-known/) {
    return 404;
}

# app_configs.ini e qualquer outro .ini
location ~* \.ini$ {
    return 404;
}
```

Para conferir depois do deploy, `curl -I https://seu-host/.env` e `curl -I https://seu-host/app_configs.ini` devem responder 403 ou 404, nunca 200.
