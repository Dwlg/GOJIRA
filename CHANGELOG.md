# Changelog

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/).
Este fork segue versionamento semântico; ver `UPGRADE.md` para o que muda de
comportamento e como migrar.

## [1.1.0] - 2026-10 (fork `Dwlg/GOJIRA`)

Quinze PRs de endurecimento (segurança, testes e um `project_demo` que roda de
ponta a ponta), todas retrocompatíveis: nenhuma trocou o comportamento padrão
sem uma opção explícita para ativá-lo. Detalhe de cada mudança e como migrar:
`UPGRADE.md`.

### Adicionado

- Base de testes: PHPUnit (`tools/phpunit`, isolado do `composer.json` da
  raiz), CI no GitHub Actions (lint, `unit` e `integration` em PHP 8.0–8.4 ×
  MySQL 8.0/MariaDB 10.6), `tests/docker-compose.yml`.
- Camada de autenticação (fases 0-5): `Connection::run/runAll/runGet`,
  hashing moderno (`LGN_PASSWORD_REHASH`), `Guard`, `AccessPolicy`
  deny-by-default, remember-me com rotação e revogação.
- `Model`: escape real em todo valor, `limit` numérico, modo estrito opt-in
  (`GOJIRA_MODEL_STRICT`) e `Gojira_Raw`/`Model::raw`/`whereRaw`/`havingRaw`.
- Charset configurável (`utf8mb4`), timezone e `lc_time_names`.
- Bootstrap/Engine opt-in: sessão sob demanda (`GOJIRA_NO_SESSION_AUTOSTART`),
  Smarty desativável (`GOJIRA_DISABLE_SMARTY`), respostas e erros em JSON
  (`GOJIRA_JSON_RESPONSES`).
- XSS: `GOJIRA_ESCAPE_HTML`, allowlist `GOJIRA_TEMPLATE_GLOBALS`, escape nos
  helpers de paginação, SEO e cabeçalho de coluna.
- `Gojira_Env` (.env opcional), `Gojira_Host` (`GOJIRA_BASE_URL`,
  `GOJIRA_TRUSTED_HOSTS`), política de placeholder de credencial.
- `project_demo` executável: `.htaccess` sem `RewriteBase` fixo, stubs dos
  componentes `Translate`, `Access`, `Log` e `mail_sender`, model `Language`
  com `database/schema.sql`, `Error_Controller` (404/403/500),
  `docker-compose.yml` do demo e smoke test de HTTP na CI.
- `encrypt()`/`decrypt()` com `openssl` AES-256-GCM, substituindo o
  `encrypt_str`/`decrypt_str` (mcrypt, fatal no PHP 8).

### Alterado

- `ENVIRONMENT` padrão do `project_demo` passa de `'DEV'` para `'PROD'`
  (`project_demo/constants.php` e `.env.example`). Ver `UPGRADE.md`.
- PHPMailer passa a vir pelo Composer (`phpmailer/phpmailer ^6.9`), em vez da
  cópia 6.0.7 em `system/vendor/`.
- `composer.json`: `minimum-stability: stable`, `prefer-stable: true`.

### Corrigido

- SQL injection e path traversal no login e no `Model` (`getWhere`,
  `sqlField`, `saveRow`, `antiInjection`), bug de `tableExist`/`fieldExist`
  usando banco errado.
- Métodos herdados e mágicos (`__construct`, `onLoad`, `beforeCall`,
  `setAccessPolicy`...) e actions estáticas deixam de ser despacháveis pela
  URL.
- `thumb.php` confinado à raiz do projeto, com limite de dimensão e lista de
  formatos.
- `project_demo/configs.php`: `getData($_SESSION['current-lang'])` passava um
  escalar cru; agora usa array (`['id' => ...]`), coerente com o modo
  estrito do Model.

### Segurança

- Todas as PRs de código (exceto a PR0, só testes) tiveram revisão de
  segurança registrada no GitHub antes do merge. Ver `UPGRADE.md`, seção
  "Limitações conhecidas", para o que fica para uma versão futura.

## [1.0.2] e anteriores

Histórico anterior a este fork: ver `git log` em `soares289/GOJIRA`.
