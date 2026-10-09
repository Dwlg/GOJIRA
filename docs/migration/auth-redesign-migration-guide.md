# Guia de Migração — Redesenho de Autenticação (Fases 0–5)

> Companheiro de leitura rápida da spec de design (compartilhada separadamente com o
> mantenedor). Este guia é sobre **como adotar** o que já foi implementado — não repete o
> raciocínio de design, contratos completos ou roadmap detalhado.

## TL;DR

Tudo que foi implementado nas Fases 0–5 é **aditivo e opt-in**, com uma exceção pontual
documentada (migração automática de hash de senha — item 2 abaixo). **Um projeto GOJIRA
existente que não muda nada continua funcionando exatamente como antes.** Você adota cada
pedaço quando quiser, na ordem que quiser.

Branches/PRs (no fork, empilhados):
`security/fix-critical-findings` → `feature/auth-phase1` → `feature/auth-phase2` →
`feature/auth-phase3` → `feature/auth-phase4` → `feature/auth-phase5`.

Exemplo de referência (lido, não executado automaticamente):
`project_demo/core/controller/authdemo.php`.

---

## 0. Pré-requisito transversal — Connection parametrizado

`Connection` ganhou `run($sql, $params)`, `runAll($sql, $params, $type)` e
`runGet($sql, $params, $type)` — prepared statements com bind, sem concatenar valores no
SQL. Use nos seus próprios models/queries sempre que a entrada vier do usuário:

```php
// Antes (concatenado - evite):
$row = $this->fetch( $this->query( "SELECT * FROM user WHERE login = '" . $login . "'" ) );

// Depois (parametrizado):
$row = $this->runGet( 'SELECT * FROM user WHERE login = ?', [ $login ] );
```

Nenhuma ação necessária para continuar usando os métodos antigos (`query`, `fetch`,
`execute`) — eles continuam existindo e funcionando como sempre.

---

## 1. Hashing moderno (automático, sem ação necessária)

Login passou a usar `Gojira_BcryptHasher` com fallback para o esquema antigo
(sha512+whirlpool). **No primeiro login válido de cada usuário, o hash é migrado para
bcrypt automaticamente** — o usuário não percebe nada.

Única coisa que muda de comportamento: **antes**, enviar um valor de senha com exatamente
128 caracteres hex era tratado como "isso já é um hash, compara direto" (brecha de
pass-the-hash). **Agora**, isso só acontece se você passar explicitamente o 4º parâmetro:

```php
// Login normal - senha em texto, sempre hasheada e comparada:
$code = $login->login( $usuario, $senha, 'ADM' );

// Login "eu já tenho o hash correto por outro meio" (ex.: integração por chave de
// acesso) - só use isso quando VOCÊ (código do servidor) já validou a identidade:
$code = $login->login( $usuario, $hashConhecido, 'ADM', true );
```

Se o seu projeto tinha algum fluxo que dependia do comportamento antigo (enviar o hash
como senha SEM o 4º parâmetro), ele vai parar de autenticar — ajuste para passar
`true` explicitamente.

Quer forçar a migração de todos os usuários de uma vez (sem esperar o próximo login)?
Não há um comando pronto para isso (esbarraria na política de "o framework não mexe no
seu banco sozinho") — escreva um script simples usando `Gojira_LegacyHasher` para
verificar e `Gojira_BcryptHasher::hash()` para regravar.

---

## 2. UserProvider plugável (opcional)

Por padrão, `Login` localiza usuários com `Gojira_TableUserProvider` (mesma convenção
`user`/`user_type` de sempre). Para usar outra fonte (outra tabela, outro serviço, cache):

```php
class MeuUserProvider implements Gojira_UserProvider {
   public function findByLogin( string $login, array $types = [] ): ?Gojira_Authenticatable { /* ... */ }
   public function findById( $id, array $types = [] ): ?Gojira_Authenticatable { /* ... */ }
   public function updatePasswordHash( $id, string $newHash ): bool { /* ... */ }
}

$globals->login->setUserProvider( new MeuUserProvider(/* ... */) );
```

A partir daí, `login()`, `isLogged()`, etc. continuam funcionando normalmente, só que
consultando o seu provider.

---

## 3. Guard (fachada opcional)

`Login` ganhou `guard($tipo)`, devolvendo um `Gojira_SessionGuard` — API mais simples
(`attempt/user/check/logout`) que delega para o mesmo `Login`/mesma sessão por baixo (ou
seja, dá pra misturar chamadas antigas de `$login->isLogged('ADM')` com
`$guard->check()` sem conflito):

```php
$guard = $globals->login->guard('ADM');

$user = $guard->attempt( $usuario, $senha );   // Gojira_Authenticatable, não array cru
$guard->check();                                // bool
$guard->user();                                 // Gojira_Authenticatable|null
$guard->logout();
```

Totalmente opcional — continue usando `$login->login()/isLogged()/getLogged()` direto se
preferir.

---

## 4. Controle de acesso deny-by-default (opcional, mas leia com atenção)

Por padrão, **nada muda**: sem registrar uma `AccessPolicy`, o único gate é o
`beforeCall()` de cada controller, exatamente como sempre foi (allow-by-default).

Para ativar deny-by-default (ação só roda se explicitamente permitida):

```php
// Em algum bootstrap do projeto (ex.: configs.php, depois do init.php):
$mapaCentral = [
   'home' => [ 'public' => ['index', 'splash'] ],
   'page' => [ 'public' => true ],  // controller inteiro público
];

$policy = new Gojira_MapAccessPolicy( $mapaCentral, $globals->login );
Controller::setAccessPolicy( $policy );
```

Ou declare direto no controller (tem prioridade sobre o mapa central para aquela classe —
ver `project_demo/core/controller/authdemo.php`):

```php
class Admin_Controller extends AppController {
   public static $access = [ 'public' => [], 'requires' => ['ADM', 'MTR'] ];
}
```

**⚠️ Antes de registrar a policy em um projeto existente**, audite quais controllers
precisam ficar públicos (home, páginas institucionais, endpoints de API pública, etc.) —
uma vez registrada, **qualquer ação não declarada pública e sem papel exigido satisfeito
passa a ser negada** (404-like, reaproveitando o `unauthorized()`/erro 403 que já existe
no framework).

**Efeito colateral incluído nesta mesma mudança (achado F05 da auditoria, incondicional,
não depende de registrar policy nenhuma):** métodos `protected`/`private` dos seus
controllers deixaram de ser executáveis via URL. Se algum teste ou integração dependia
disso (não deveria), ele vai passar a cair em "método não encontrado".

---

## 5. Remember-me seguro (opcional)

Selector + validator — o banco nunca guarda o segredo em claro, só o hash dele; o token
rotaciona a cada uso.

```php
// Uma vez, para criar a tabela (o framework nunca cria sozinho):
$store = new Gojira_TableRememberTokenStore( $globals->connection );
$store->createTableIfMissing();   // CREATE TABLE IF NOT EXISTS auth_remember_token (...)

// No bootstrap do projeto:
$guard = $globals->login->guard( 'ADM', $store );

// No login, se o usuário marcou "lembrar de mim":
$guard->attempt( $usuario, $senha, [], true );   // 4º parâmetro = remember

// Em toda requisição onde ainda não há sessão aberta, antes de decidir "visitante":
if( ! $guard->check() ){
   $guard->attemptFromRememberCookie();
}
```

Ver `Gojira_TableRememberTokenStore::schemaSql()` para o DDL completo, e
`revokeAllForUser($id)` para "sair de todos os dispositivos".

---

## 6. Hardening de cookies e sessão (automático, sem ação necessária)

- Cookies de identidade (modo `setCookie(true)` do `Login`) e de remember-me agora saem
  com `HttpOnly`, `SameSite=Lax` e `Secure` (quando a requisição é HTTPS).
- `session_regenerate_id(true)` é chamado automaticamente após login e após logout
  (mitiga session fixation).

**Único efeito colateral possível:** se algum JavaScript do seu frontend lia os cookies de
identidade diretamente via `document.cookie` (ex.: `admLogin`, `admCod`), isso para de
funcionar — `HttpOnly` existe exatamente para impedir leitura por JS (é a correção
correta; se você precisa desses dados no frontend, exponha-os por um endpoint próprio, não
pelo cookie de sessão).

---

## Checklist de hardening (§8 da spec) — o que está coberto

| Item | Status |
|---|---|
| Comparações em tempo constante (`hash_equals`) | ✅ Fase 4 |
| Aleatoriedade forte (`random_bytes`) para selector/validator | ✅ Fase 4 |
| Cookies `HttpOnly`/`Secure`/`SameSite=Lax` | ✅ Fase 4 (remember-me) + Fase 5 (identidade) |
| `session_regenerate_id` no login e logout | ✅ Fase 5 |
| Privilégio sempre resolvido no servidor (nunca do cookie) | ✅ Fase 2–4 |
| Rotação + revogação de remember-me; "sair de todos os dispositivos" | ✅ Fase 4 |
| CSRF em ações de escrita | ⬜ Fora de escopo — recomendado para trabalho futuro |
| Rate-limiting / lockout no login | ⬜ Fora de escopo — recomendado para trabalho futuro |
| `access_key`/tokens de integração sempre hasheados no banco | ⬜ Fora de escopo — recomendado para trabalho futuro |

---

## O que NÃO foi tocado (decisões explícitas, não esquecimento)

- **`typecod` vs `type`** em `Login::userExists()` — assimetria pré-existente entre os dois
  ramos do método; parametrizamos contra SQLi mas preservamos o comportamento (é decisão de
  negócio do projeto, não do framework).
- **Modelo multi-silo de sessão** (vários papéis logados ao mesmo tempo) — preservado
  integralmente; `Guard` é uma fachada por papel, não um substituto do modelo.
- **SQLi sistêmica no restante da camada Model** (fora do caminho de login) — a fundação
  parametrizada (Fase 0) está pronta para isso, mas migrar cada `Model::getData/delete/...`
  é um trabalho à parte, não incluído aqui.

## Cobertura de testes

78/78 testes de integração passando contra MySQL real (não mockado), cobrindo as 5 fases,
rodados a cada fase como regressão completa das anteriores. Ver as mensagens de commit de
cada fase para o detalhamento por cenário.
