# Guia de atualização — 1.0.2 → 1.1.0

Este fork (`Dwlg/GOJIRA`) endurece o framework em 15 PRs pequenas e
retrocompatíveis. **Nenhuma muda o comportamento padrão sem uma opção
explícita.** As exceções (comportamento que só muda para entrada maliciosa
ou já fatal no PHP 8) estão marcadas como tais abaixo. Projeto que não ativa
nada continua rodando exatamente como antes.

As PRs antigas do fork (#1 a #5, séries anteriores ao PR0) foram superadas
pela PR3 (#13-#16) e ficam só como histórico.

## Sumário

1. [PHPMailer pelo Composer](#1-phpmailer-pelo-composer)
2. [Hash de senha — `LGN_PASSWORD_REHASH`](#2-hash-de-senha--lgn_password_rehash)
3. [Despacho: actions estáticas e métodos de framework](#3-despacho-actions-estáticas-e-métodos-de-framework)
4. [Helpers do `AppController` devem ser `protected`](#4-helpers-do-appcontroller-devem-ser-protected)
5. [`GOJIRA_DISABLE_POST_DISPATCH`](#5-gojira_disable_post_dispatch)
6. [`Model`: `delete('')` agora lança exceção](#6-model-delete-agora-lança-exceção)
7. [`Model`: pré-escape antes do `exist()` mudou](#7-model-pré-escape-antes-do-exist-mudou)
8. [`Model`: exceções de `LIMIT` e de direção de ordenação](#8-model-exceções-de-limit-e-de-direção-de-ordenação)
9. [`GOJIRA_BASE_URL` e `GOJIRA_TRUSTED_HOSTS`](#9-gojira_base_url-e-gojira_trusted_hosts)
10. [Política de placeholder de credencial](#10-política-de-placeholder-de-credencial)
11. [Opções de sessão — `GOJIRA_SESSION_HARDENING` e correlatas](#11-opções-de-sessão--gojira_session_hardening-e-correlatas)
12. [Opções de JSON — `GOJIRA_JSON_RESPONSES`](#12-opções-de-json--gojira_json_responses)
13. [`GOJIRA_ESCAPE_HTML` e a allowlist `GOJIRA_TEMPLATE_GLOBALS`](#13-gojira_escape_html-e-a-allowlist-gojira_template_globals)
14. [`GOJIRA_MODEL_STRICT`](#14-gojira_model_strict)
15. [`project_demo`: `ENVIRONMENT` padrão passa a `PROD`](#15-project_demo-environment-padrão-passa-a-prod)
16. [Limitações conhecidas](#16-limitações-conhecidas)

---

## 1. PHPMailer pelo Composer

**PR10.** `system/vendor/PHPMailer-6.0.7` (CVE-2020-13625, CVE-2021-34551,
CVE-2021-3603) foi removido. `phpmailer/phpmailer ^6.9` entra pelo
`composer.json` da raiz (`vendor-dir: system/vendor`) e
`system/class/tool/network.class.php` usa o autoload do Composer.

**Migração:** nenhuma, se o projeto usa `Component::Load('mail_sender')` ou
a classe de rede do próprio GOJIRA. Código que incluía
`system/vendor/PHPMailer-6.0.7/...` diretamente por caminho precisa trocar
para `\PHPMailer\PHPMailer\PHPMailer` (namespace do Composer).

## 2. Hash de senha — `LGN_PASSWORD_REHASH`

**PR2.** Uma única flag governa toda gravação de hash de senha.

- `false` (padrão): `changePass`, cadastro e login gravam no formato legado
  (`Gojira_LegacyHasher`, sha512+whirlpool). Nada muda.
- `true`: gravam bcrypt (`Gojira_BcryptHasher`, custo 12) e migram o hash
  legado/desatualizado no primeiro login válido.
- Um hasher injetado com `Login::setHasher()` tem precedência sobre a flag.

```php
define('LGN_PASSWORD_REHASH', true);   // antes de carregar o framework
// ou
$globals->login->setHasher( new Gojira_BcryptHasher() );
```

**Atenção:** ligar é irreversível para todo usuário que trocar a senha ou
for migrado — o hash bcrypt não volta ao formato legado. Na 2.0 o bcrypt
passa a ser o padrão.

## 3. Despacho: actions estáticas e métodos de framework

**PR4.** `Controller::isDispatchable()` passou a exigir, além de público e
sem `__`, que o método **não seja estático** e esteja **declarado numa
subclasse de `Controller`** (nunca em `Controller`/`GojiraCore`). Então:

- `/home/__construct`, `/home/onload`, `/home/beforecall`,
  `/home/setaccesspolicy` (e qualquer gancho do framework) caem em
  `not_found`/`0x1011`, não mais executam o método real.
- **Uma action estática deixa de ser despachável.** Se algum projeto definia
  `static function minhaAction($param)` esperando que a URL a chamasse,
  ela precisa deixar de ser estática.

**Migração:** audite o `grep -rn 'static function' core/controller/` do
projeto; remova `static` das actions que recebem requisição.

## 4. Helpers do `AppController` devem ser `protected`

**PR4 (ajuste da revisão).** Todo método público do `AppController` do
projeto vira action em **todos** os controllers (é como o despacho sempre
funcionou). Isso inclui helpers que nunca deveriam ser chamados pela URL.

**Migração:** troque `function` por `protected function` em qualquer
helper do `AppController` que não seja uma tela/endpoint de propósito.

## 5. `GOJIRA_DISABLE_POST_DISPATCH`

**PR4.** O despacho legado por `$_POST['class']`/`$_POST['proc']` (usado por
chamadas `$.post(..., {class:'home', proc:'index'})`) emite
`E_USER_DEPRECATED` a cada uso. Com a constante definida, o POST deixa de
ser usado para despacho (a URL passa a ser a única fonte).

```php
define('GOJIRA_DISABLE_POST_DISPATCH', true);
```

Recomendado em produção, uma vez que o projeto migre as chamadas AJAX
legadas para a URL (`$.post(baseURL + 'home/index/', {})`).

## 6. `Model`: `delete('')` agora lança exceção

**PR5.** Antes, `delete('')` montava `WHERE 1=1` e apagava a tabela
inteira silenciosamente. Agora lança `ModelException` (0x2018) — chave
vazia nunca foi uma intenção válida de `delete()`.

**Migração:** nenhuma, a menos que o projeto dependesse (por acidente) desse
comportamento. Se precisar mesmo apagar tudo, use `deleteWhere` explícito ou
SQL direto.

## 7. `Model`: pré-escape antes do `exist()` mudou

**PR5.** O `Login` deixou de pré-escapar o valor antes de chamar
`exist()`/`getValue()` — essas funções já escapam internamente desde a PR5,
e o escape duplo alterava o dado comparado (ex.: uma senha com `'` nunca
batia). Qualquer código do projeto que também pré-escapava antes de chamar
essas funções do Model deve parar de fazer isso.

## 8. `Model`: exceções de `LIMIT` e de direção de ordenação

**PR5.** `limit` só aceita inteiro, `"n"`, `"n, m"`, `"n OFFSET m"` ou
`[n, m]`; qualquer outro valor lança `ModelException`. A direção de
`order()`/`sort` fora de `ASC`/`DESC` também lança. Antes, um valor inválido
entrava cru na consulta (um dos vetores de SQL injection corrigidos).

**Migração:** se algum projeto montava `limit` ou a direção por
concatenação de string com valor fora desse formato, normalize antes de
chamar o Model.

## 9. `GOJIRA_BASE_URL` e `GOJIRA_TRUSTED_HOSTS`

**PR12.** `BASE_URL` detectado pela requisição (`Host`) deixou de ser
gravado em `app_configs.ini` — um `Host` forjado não fica mais persistido
para todo mundo. Para fixar a URL base e a lista de hosts aceitos:

```bash
# .env
GOJIRA_BASE_URL=www.exemplo.com.br/app/
GOJIRA_TRUSTED_HOSTS=www.exemplo.com.br,.exemplo.com.br
```

Sem `GOJIRA_TRUSTED_HOSTS`, qualquer `Host` é aceito (como antes) — mas ver
a política do item 10. Com a lista preenchida, um `Host` fora dela recebe
`400 Bad Request` antes de qualquer uso.

## 10. Política de placeholder de credencial

**PR12.** `Gojira_Env::checkPlaceholders()` compara `DB_USER`/`DB_PWD` (e o
`BASE_URL` detectado sem `TRUSTED_HOSTS`) contra uma lista conservadora de
valores de exemplo (`db_user`, `changeme`, `homopwd`, ...). O que acontece
depende do `ENVIRONMENT`:

| `ENVIRONMENT` | Credencial de exemplo | `BASE_URL` detectado sem `TRUSTED_HOSTS` |
|---|---|---|
| `DEV` | só log | só log |
| outro valor | `error_log` + `E_USER_WARNING` por requisição | idem |
| `PROD`/`PRODUCTION` ou `GOJIRA_STRICT_CONFIG=1` | **boot recusado** (exceção) | **boot recusado** |

**Migração:** projetos que sobem em produção com `ENVIRONMENT` diferente de
`DEV` precisam ter `DB_USER`/`DB_PWD` reais (via `.env` ou variável de
ambiente) e, se quiserem evitar o aviso por requisição, `GOJIRA_BASE_URL`
e/ou `GOJIRA_TRUSTED_HOSTS` configurados.

## 11. Opções de sessão — `GOJIRA_SESSION_HARDENING` e correlatas

**PR8.** Com `GOJIRA_NO_SESSION_AUTOSTART`, nem o `system/init.php` nem o
`Login` abrem sessão automaticamente — uma requisição sem necessidade de
sessão não envia `Set-Cookie`. A sessão abre sob demanda com
`Gojira_Session::start()` (ou `resumeIfPresent()`, usado internamente pelo
`isLogged()`). `GOJIRA_SESSION_HARDENING` (e as opções de cookie que vêm
junto: `HttpOnly`, `SameSite=Lax`, `Secure` em HTTPS) endurecem o cookie de
sessão quando ligada.

**Migração:** nenhuma sem a opção. Projeto que liga
`GOJIRA_NO_SESSION_AUTOSTART` precisa garantir que todo ponto que leia/grave
`$_SESSION` passe por `Gojira_Session::start()` primeiro.

## 12. Opções de JSON — `GOJIRA_JSON_RESPONSES`

**PR8.** Sem a opção, a saída do `Engine` é idêntica, byte a byte, à
anterior (exceto o cabeçalho `X-Content-Type-Options: nosniff`, sempre
enviado — ver `GOJIRA_DISABLE_NOSNIFF` para desligar). Com
`GOJIRA_JSON_RESPONSES`:

- Controller que devolve array/objeto responde
  `Content-Type: application/json; charset=utf-8`.
- 404/403/500 (e qualquer `ControllerException`/`Exception`) respondem JSON
  (`{"error":{"code","message"}}`) com o status HTTP correto, sem HTML,
  caminho de arquivo nem SQL. O campo `detail` só aparece com
  `ENVIRONMENT=DEV`.

## 13. `GOJIRA_ESCAPE_HTML` e a allowlist `GOJIRA_TEMPLATE_GLOBALS`

**PR9.** Com `GOJIRA_ESCAPE_HTML`, o Smarty escapa HTML por padrão
(`{$param.x}` com `<script>` sai escapado). Sem a opção, nada muda — mas
ela é a correção estrutural para XSS refletido em template, então vale
ligar em qualquer projeto que renderiza valor vindo de usuário.

`GOJIRA_TEMPLATE_GLOBALS` é uma allowlist de quais chaves de `$globals`
chegam ao template (sem ela, o `$globals` inteiro chega — deprecated,
`E_USER_DEPRECATED` uma vez por requisição). Os helpers
(`pagination()`, `getSeo()`, `columnHeader()`, `embedVideo()`) já escapam
atributos (`escapeAttr`) e URLs (`escapeUrl`, com allowlist de esquema)
independentemente da opção, porque isso corrige entrada maliciosa.

## 14. `GOJIRA_MODEL_STRICT`

**PR6.** Com o modo estrito (constante ou `Model::setStrict(true)`):

- `getData`/`countData`/`delete`/`getWhere` com uma string crua como chave
  lançam `ModelException` — use array de coluna => valor ou
  `Model::raw('col = ?', [$valor])`/`whereRaw()`.
- `sort`/`order()` livre, operador de `where()` fora da lista
  (`=, <>, !=, <, >, <=, >=, LIKE, NOT LIKE, IN, NOT IN, IS, IS NOT`), tipo
  de `join()` fora de `INNER, LEFT, RIGHT, CROSS, LEFT OUTER, RIGHT OUTER` e
  função SQL como valor fora de `Gojira_Raw` (exceto `NOW()`, `CURDATE()`,
  `CURTIME()`, `CURRENT_TIMESTAMP()`, `UUID()`, sem argumento) lançam.
- `Gojira_Raw::sql('NOW()')` continua funcionando em `saveRow()` e
  `where()`.

Sem a opção, tudo isso continua funcionando como na PR5 (com
`E_USER_DEPRECATED`, não exceção). Ativar é o caminho recomendado para
projeto novo; a 2.0 inverte o padrão.

## 15. `project_demo`: `ENVIRONMENT` padrão passa a `PROD`

**PR13.** `project_demo/constants.php` e `.env.example` trocam o fallback
de `ENVIRONMENT` de `'DEV'` para `'PROD'`. Motivo: com `DEV` como padrão, um
deploy que esquece de definir `ENVIRONMENT` (ou o `.env`) fica com
`display_errors` ligado e com a política de placeholder (item 10) no modo
mais permissivo — falha aberta. Com `PROD` como padrão:

- `display_errors` vem desligado por padrão (`configs.php`).
- Credencial de exemplo ou `BASE_URL` detectado sem `TRUSTED_HOSTS` **recusam
  o boot** em vez de só avisar.

**Migração:** quem desenvolve localmente a partir deste `.env.example` deve
definir `ENVIRONMENT=DEV` explicitamente no próprio `.env` (fora do git).
Isso não afeta projetos que já definem `ENVIRONMENT` nas suas variáveis de
ambiente ou `.env` — o fallback só vale quando nada foi definido.

## 16. Limitações conhecidas

Fora de escopo nesta versão, registradas para avaliação futura:

- **`thumb.php` sem restrição de host na URL remota.** Com a variável de
  ambiente `GOJIRA_THUMB_ALLOW_REMOTE` ligada, qualquer URL é aceita — não
  há allowlist de domínio. Deixe a variável desligada (padrão) a menos que
  o host de origem seja confiável.
- **`encrypt_str`/`decrypt_str` sem rota de migração automática.** Dado já
  criptografado com mcrypt não é convertido para o novo `encrypt()`/`decrypt()`
  (AES-256-GCM); a migração de dado existente é responsabilidade do projeto.
- **`NO_BACKSLASH_ESCAPES` no fallback do `DataDictionary`.** O escape usado
  ali assume o modo SQL padrão do MySQL/MariaDB; um servidor com
  `NO_BACKSLASH_ESCAPES` ativado no `sql_mode` não é tratado especificamente.
- **Sem regra equivalente para nginx.** As proteções de `.htaccess`
  (`system/.htaccess`, `project_demo/.htaccess`) só valem sob Apache. Uma
  instalação em nginx precisa de blocos `location` equivalentes (negar
  `.env`, `*.php` fora dos controllers/`thumb.php`, `vendor/`).
- **Requisições paralelas no remember-me.** A rotação do token de
  remember-me não é atômica entre requisições concorrentes do mesmo
  navegador; uma corrida pode invalidar um token que outra aba acabou de
  rotacionar.
- **`servedByWeb()` falha aberto em sistema de arquivos sem diferenciar
  maiúsculas/minúsculas, ou com symlink.** A checagem de que um `.env` fora
  do projeto está fora do `DOCUMENT_ROOT` compara caminhos normalizados por
  `realpath()`; num FS case-insensitive (ex.: macOS padrão, Windows) ou com
  symlink apontando para dentro do docroot por um caminho que o
  `realpath()` não resolve da mesma forma nos dois lados, a checagem pode
  concluir "fora" quando na prática está acessível. Prefira
  `GOJIRA_ENV_FILE` apontando para um caminho absoluto fora de qualquer
  docroot, em vez de depender dessa checagem.
