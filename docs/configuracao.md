# Configuração: `.env`, `BASE_URL` e hosts confiáveis

Tudo nesta página é opcional. Um projeto que não cria `.env` nem define as variáveis abaixo continua funcionando; fora de `ENVIRONMENT=DEV` ele passa a receber avisos (ver "Política de configuração insegura").

## `.env` opcional

`Gojira_Env` (`system/class/env.class.php`) lê um arquivo `.env` simples, sem dependência externa. O framework não chama `Gojira_Env::load()` por conta própria: quem decide é o projeto. O `project_demo/configs.php` procura, nesta ordem, e carrega o primeiro que existir:

1. o caminho da variável de servidor `GOJIRA_ENV_FILE` (`SetEnv` no Apache, `fastcgi_param` no nginx);
2. `.env` na pasta pai do projeto, só se ela estiver fora do `DOCUMENT_ROOT` (lá não há `.htaccess` que o proteja; dentro do `DOCUMENT_ROOT` ele é ignorado com uma linha no `error_log`);
3. `.env` na pasta do projeto, por compatibilidade (protegido pelo `.htaccess`).

```php
require_once( __DIR__ . '/' . SYSDIR_NAME . 'class/env.class.php' );
Gojira_Env::loadFirst( [ Gojira_Env::get( 'GOJIRA_ENV_FILE' ), dirname( __DIR__ ) . '/.env', __DIR__ . '/.env' ] );
require_once( __DIR__ . '/constants.php' );
```

E o `constants.php` usa o valor do ambiente com o antigo valor do `define()` como fallback:

```php
define('DB_PWD', Gojira_Env::get('DB_PWD', 'db_host'));
```

Formato aceito: `CHAVE=valor`, `export CHAVE=valor`, aspas duplas (com `\n`, `\r`, `\t`, `\"` e `\\`), aspas simples (literal), comentário em linha própria (`# ...`) ou depois de um espaço (`valor # ...`). Não há interpolação nem valor em várias linhas. Uma linha inválida é ignorada com `E_USER_WARNING` que cita só o número da linha.

- Os valores vão para `$_ENV` e para um array interno lido por `Gojira_Env::get()`. `putenv()` não é usado, então o ambiente do processo e dos processos filhos não muda.
- Chaves `PATH`, `LD_*` e `HTTP_*` são recusadas com `E_USER_WARNING`.
- `Gojira_Env::get()` com chave `HTTP_*` devolve sempre o padrão: em CGI/FPM essas chaves são cabeçalhos da requisição.
- Uma variável já definida no servidor (`getenv`, `$_ENV` ou `$_SERVER`) **não** é sobrescrita pelo `.env`. Para forçar, `Gojira_Env::load($arquivo, true)`.

Modelo: `project_demo/.env.example`. O `.env` e o `app_configs.ini` estão no `.gitignore`; nunca versione um `.env` com valor real.

## Política de configuração insegura

Vale para credenciais de exemplo e para `BASE_URL` derivado do `Host` (abaixo). Na 1.x:

| Situação | Efeito |
|---|---|
| `ENVIRONMENT=DEV` (ou constante não definida) | Credencial de exemplo: uma linha no `error_log`. `BASE_URL` detectado: nada |
| Outro `ENVIRONMENT` (ex.: `HOMOLOG`) | `error_log` e `E_USER_WARNING` em toda requisição; o boot segue |
| `ENVIRONMENT=PROD` ou `PRODUCTION` | `RuntimeException`: o boot é recusado |
| `GOJIRA_STRICT_CONFIG` ligado (constante `true` ou variável `1`/`true`/`on`/`yes`), em qualquer ambiente | `RuntimeException`: o boot é recusado |

**Na 2.0** o boot passa a ser recusado sempre nesses casos, fora de `DEV`.

### Credenciais de exemplo

No boot, o `init.php` compara o usuário e a senha do banco (`$globals->database->user` e `->password`, que no demo vêm de `DB_USER` e `DB_PWD`) com uma lista de valores de exemplo (`db_user`, `db_host`, `db_pwd`, `changeme` e outros, em `Gojira_Env::PLACEHOLDERS`). A mensagem cita a constante.

## `BASE_URL`

O `BASE_URL` (host e caminho, sem protocolo, terminado em `/`) é resolvido nesta ordem:

1. `$baseURL` definido pelo projeto antes do `init.php`. Continua gravado no `app_configs.ini`, mas só quando difere do valor do arquivo.
2. Variável de ambiente `GOJIRA_BASE_URL` (aceita com ou sem `http(s)://`).
3. `BASE_URL` da seção `[<PROJECT_ID>_ENGINE]` do `app_configs.ini`.
4. Em CLI, `cli://`.
5. Detectado da requisição: `SERVER_NAME` (validado) mais a pasta do script de entrada (`SCRIPT_NAME`).

**Mudança:** o valor detectado no passo 5 vale só para a requisição corrente e não é mais gravado no `app_configs.ini`. Antes, o `Host` da primeira requisição ficava gravado para sempre. Quem já tem `BASE_URL` no `app_configs.ini` continua com ele. Se esse valor veio de um `Host` indevido, apague a chave ou defina `GOJIRA_BASE_URL`, que tem precedência sobre o arquivo.

No Apache padrão (`UseCanonicalName Off`), `SERVER_NAME` é o `Host` enviado pelo cliente. Por isso o passo 5 sem `TRUSTED_HOSTS` segue a política de configuração insegura acima.

O `app_configs.ini` é gravado com trava exclusiva (`LOCK_EX`). Dentro da trava, o arquivo é relido e só as chaves alteradas pelo processo são aplicadas, para não apagar o que outro processo gravou. A leitura usa trava compartilhada.

## Hosts confiáveis (`TRUSTED_HOSTS`)

Lista separada por vírgula, na variável `GOJIRA_TRUSTED_HOSTS` ou na chave `TRUSTED_HOSTS` da seção `[<PROJECT_ID>_ENGINE]` do `app_configs.ini` (a variável tem precedência).

- Vazia (padrão): qualquer `Host` é aceito, como antes.
- Preenchida: uma requisição web cujo `Host` não está na lista recebe `400 Bad Request` antes do despacho. A CLI não é afetada.
- `exemplo.com` casa só o host exato; `.exemplo.com` casa o domínio e os subdomínios; `*.exemplo.com` casa só os subdomínios. A porta é ignorada.
- Um `Host` com caractere fora de `[a-z0-9.-]` (mais a porta) e que não seja IPv6 literal entre colchetes é tratado como vazio e não casa nenhuma entrada.

Em produção, defina `GOJIRA_BASE_URL` e `GOJIRA_TRUSTED_HOSTS`.

## O `.env` nunca pode ser servido pela web

Prefira o `.env` fora da pasta servida (pasta pai do projeto quando o `DOCUMENT_ROOT` é o projeto, ou `GOJIRA_ENV_FILE`). Nunca dentro do `webroot/`.

**Apache.** O `project_demo/.htaccess` nega qualquer arquivo iniciado por ponto (`.env`, `.env.example`, `.git*`) e qualquer `.ini` com suas cópias (`app_configs.ini`, `app_configs.ini.bak`, `app_configs.ini~`), em qualquer subpasta, com `FilesMatch`, que não depende do `mod_rewrite`. Copie esse bloco para o `.htaccess` do seu projeto. Se o servidor tiver `AllowOverride None`, ponha o mesmo bloco no `VirtualHost`.

**nginx.** O nginx não lê `.htaccess`. No `server` do projeto, antes das demais `location` (expressões regulares são avaliadas na ordem em que aparecem, e a primeira que casa vence; por isso estas vêm antes da `location ~ \.php$`):

```nginx
# Arquivos iniciados por ponto (.env, .git, .htaccess), exceto .well-known
location ~ /\.(?!well-known/) {
    return 404;
}

# app_configs.ini, qualquer outro .ini e cópias (.ini.bak, .ini~)
location ~* \.ini(\..*|~)?$ {
    return 404;
}
```

Para conferir depois do deploy, `curl -I https://seu-host/.env` e `curl -I https://seu-host/app_configs.ini` devem responder 403 ou 404, nunca 200.
