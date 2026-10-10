# Guia de Migração — Redesenho de Autenticação (Fases 0–5)

Este guia mostra **como adotar** a camada de autenticação nova do GOJIRA: o que muda
sozinho, o que é opcional e como ligar cada parte.

## TL;DR

Um projeto GOJIRA que não muda nada continua funcionando como antes. Quase tudo é
aditivo e opcional. As mudanças que valem sem configuração são correções de segurança
e estão listadas abaixo:

| Muda sem configuração | Efeito para quem não fez nada |
|---|---|
| Senha com cara de hash não autentica pelo formulário (Fase 1) | Só quem enviava o hash como senha. Use o 4º parâmetro de `login()` |
| Método `protected`/`private` de controller não é despachável pela URL (Fase 3) | Responde como método inexistente |
| Cookies de identidade com `HttpOnly`, `SameSite=Lax` e `Secure` em HTTPS (Fase 5) | Só no modo `setCookie(true)`, que é desligado por padrão |
| Modo cookie compara o id exato e avisa que está obsoleto (Fase 5) | Só no modo `setCookie(true)` |
| Id de sessão trocado no login e no logout (Fase 5) | Nenhum. Os dados da sessão são mantidos |
| `getPassword()` usa `random_int` (Fase 5) | Nenhum |

Exemplo de referência (lido, não executado automaticamente):
`project_demo/core/controller/authdemo.php`.

---

## 0. Connection parametrizado

`Connection` ganhou `run($sql, $params)`, `runAll($sql, $params, $type)` e
`runGet($sql, $params, $type)`: prepared statements com bind, sem concatenar valores no
SQL. Use nas suas consultas sempre que a entrada vier do usuário:

```php
// Antes (concatenado - evite):
$row = $this->fetch( $this->query( "SELECT * FROM user WHERE login = '" . $login . "'" ) );

// Depois (parametrizado):
$row = $this->runGet( 'SELECT * FROM user WHERE login = ?', [ $login ] );
```

Em erro, os três lançam `ConnectionException` com mensagem genérica; o detalhe vai para
o `error_log`. `query`, `fetch` e `execute` continuam existindo e funcionando como sempre.
`runAll` e `runGet` exigem a extensão `mysqlnd` (padrão nas instalações do PHP 8).

---

## 1. Hash de senha (`LGN_PASSWORD_REHASH`)

O login **verifica** os dois formatos: bcrypt e o legado (sha512+whirlpool). O formato
de **gravação** é decidido por uma única flag:

| `LGN_PASSWORD_REHASH` | `changePass()`, `hashPassword()` | Login com hash legado |
|---|---|---|
| `false` (padrão na 1.x) | grava no formato legado | autentica e não regrava nada |
| `true` | grava bcrypt (custo 12) | autentica e regrava em bcrypt |

```php
// Antes de carregar o framework (ex.: no configs.php):
define( 'LGN_PASSWORD_REHASH', true );

// Ou por instância:
$globals->login->setPasswordRehash( true );
```

- **Ligar é irreversível** para todo usuário que trocar a senha ou for migrado: o hash
  bcrypt não volta ao legado. Ligue quando todo sistema que lê a tabela de usuários
  aceitar bcrypt.
- A regravação no login só acontece se o hash no banco ainda for o que foi verificado;
  uma falha nela não impede o login e vai para o `error_log`.
- Um hasher injetado com `setHasher()` (ex.: argon2) tem precedência sobre a flag.
- No cadastro de usuário, use `$login->hashPassword( $senha )` em vez de `toPassword()`.
- Na 2.0 o bcrypt passa a ser o padrão.

**Limite do bcrypt: 72 bytes.** Só os primeiros 72 bytes da senha entram no hash; o
resto é ignorado sem erro (em UTF-8, um caractere acentuado ocupa 2 bytes). Para aceitar
senhas maiores, injete um hasher argon2, se o PHP tiver suporte
(`new Gojira_BcryptHasher( new Gojira_LegacyHasher(), PASSWORD_ARGON2ID )`), ou limite o
tamanho da senha no cadastro.

**Senha com cara de hash.** Antes, um valor de 128 caracteres hexadecimais enviado como
senha era comparado direto com o hash do banco. Agora isso só acontece com o 4º
parâmetro, que deve vir sempre do código do servidor, nunca da requisição:

```php
// Login normal - senha em texto, sempre verificada pelo hasher:
$code = $login->login( $usuario, $senha, 'ADM' );

// O servidor já tem o hash correto por outro meio (ex.: chave de acesso):
$code = $login->login( $usuario, $hashConhecido, 'ADM', true );
```

---

## 2. UserProvider plugável (opcional)

Por padrão, `Login` localiza usuários com `Gojira_TableUserProvider`, na convenção de
sempre (`user`/`user_type`), com todas as consultas parametrizadas.

- **Sem tabela de tipos:** se a tabela de tipos não existir, a coluna `type` do usuário
  deve guardar o próprio código do tipo (ex.: `'ADM'`). O login compara o tipo pedido
  direto com essa coluna.
- **Esquema:** a coluna `status` (0 pendente, 1 inativo, 2 ativo) ou `active` (-1, 0, 1)
  é detectada sozinha, assim como a coluna `name`. Só o status ativo entra.

Para usar outra fonte de usuários (outra tabela, serviço externo, cache):

```php
class MeuUserProvider implements Gojira_UserProvider {
   public function findByLogin( string $login, array $types = [] ): ?Gojira_Authenticatable { /* ... */ }
   public function findById( $id, array $types = [] ): ?Gojira_Authenticatable { /* ... */ }

   //Com $expectedOldHash, grave só se o hash atual ainda for esse e devolva false se nada mudou.
   public function updatePasswordHash( $id, string $newHash, ?string $expectedOldHash = null ): bool { /* ... */ }
}

$globals->login->setUserProvider( new MeuUserProvider(/* ... */) );
```

A partir daí `login()`, o Guard e o remember-me consultam o seu provider. As mensagens
"pendente" e "inativo" só são distinguidas com o provider padrão; num provider próprio,
usuário inativo recebe a mensagem de conta desativada.

**Erros de login.** `login()` lança `Gojira_AuthException` (subclasse de `Exception`, com
os mesmos códigos de antes: `0x9001` usuário, `0x9002` inativo, `0x9003` senha, `0x9004`
pendente) e `getAuthCode()` com um código estável em texto (`AUTH_INVALID_USER`,
`AUTH_INVALID_PASSWORD`, `AUTH_INACTIVE`, `AUTH_PENDING`). Para não revelar quais
logins existem, mostre ao visitante a mesma mensagem para `0x9001` e `0x9003`. O tempo
de resposta já ajuda: um login inexistente gasta o custo de gerar um hash no formato de
gravação atual, parecido com o de verificar uma senha errada.

---

## 3. Guard (fachada opcional)

`Login::guard($tipo)` devolve um `Gojira_SessionGuard`, uma API mais curta que usa o mesmo
`Login` e a mesma sessão. Dá para misturar `$login->isLogged('ADM')` com `$guard->check()`:

```php
$guard = $globals->login->guard('ADM');

$user = $guard->attempt( $usuario, $senha );   // Gojira_Authenticatable
$guard->check();                                // bool
$guard->user();                                 // Gojira_Authenticatable|null
$guard->logout();
```

Cada guard representa um tipo de usuário. Vários tipos podem estar logados na mesma
sessão, e o logout de um não afeta o outro.

---

## 4. Controle de acesso deny-by-default (opcional)

Sem registrar uma `AccessPolicy`, nada muda: o único controle é o `beforeCall()` de cada
controller, como sempre.

Para ativar o deny-by-default (uma ação só roda se for pública ou se o papel exigido
estiver logado):

```php
// No bootstrap do projeto, depois do init.php:
$policy = new Gojira_MapAccessPolicy( [
   'home'  => [ 'public' => ['index', 'splash'] ],
   'page'  => [ 'public' => true ],               // controller inteiro público
   'admin' => [ 'requires' => ['ADM', 'MTR'] ],   // exige um desses papéis
], $globals->login );

Controller::setAccessPolicy( $policy );
```

Ou declare no próprio controller (substitui o mapa central para aquela classe):

```php
class Admin_Controller extends AppController {
   public static $access = [ 'public' => [], 'requires' => ['ADM', 'MTR'] ];
}
```

Uma ação negada vai para o `unauthorized()` do controller, se existir, ou lança
`ControllerException` `0x1012` (403). A policy é avaliada antes do `beforeCall()`, que
continua rodando para as ações liberadas.

**Antes de registrar a policy num projeto existente**, liste quais controllers e ações
precisam ficar públicos (home, páginas institucionais, endpoints de API pública):
depois do registro, toda ação não declarada é negada.

**Mudança sem configuração:** métodos `protected` e `private` dos controllers deixaram
de ser executáveis pela URL e respondem como método inexistente (`not_found()` ou
`0x1011`), com ou sem policy.

---

## 5. Remember-me seguro (opcional)

"Lembrar de mim" no padrão selector + validator: o banco guarda só o hash SHA-256 do
validator, o token é trocado a cada uso e um validator errado revoga o token.

```php
// Uma vez, para criar a tabela (o framework nunca cria sozinho;
// o DDL está em Gojira_TableRememberTokenStore::schemaSql()):
$store = new Gojira_TableRememberTokenStore( $globals->connection );
$store->createTableIfMissing();

// No bootstrap do projeto:
$guard = $globals->login->guard( 'ADM', $store );   // cookie 'remember_adm', 30 dias

// No login, se o usuário marcou "lembrar de mim":
$guard->attempt( $usuario, $senha, [], true );

// Em toda requisição sem sessão aberta, antes de tratar como visitante:
if( ! $guard->check() ){
   $guard->attemptFromRememberCookie();
}
```

- O cookie sai com `HttpOnly`, `SameSite=Lax` e `Secure` em HTTPS.
- `logout()` revoga o token da sessão atual e apaga o cookie.
- Conta desativada, token expirado ou token de outro tipo de usuário não reabrem sessão.
- Ao trocar a senha, chame `$store->revokeAllForUser( $id )` para encerrar o
  remember-me em todos os dispositivos.
- Rode `$store->purgeExpired()` de tempos em tempos (ex.: num cron) para limpar tokens
  vencidos.
- Nome do cookie e validade são configuráveis:
  `$login->guard( 'ADM', $store, 'lembrar', 7 * 86400 )`.

---

## 6. Cookies de identidade e sessão

- **Modo cookie obsoleto.** `Login::setCookie(true)` grava a identidade em cookies sem
  assinatura: quem conhece o id de um usuário ativo consegue montá-los. O modo continua
  desligado por padrão; ligar emite `E_USER_DEPRECATED` uma vez por requisição. Para
  manter o usuário logado entre visitas, use o remember-me (seção 5).
- **Quem ainda usa o modo cookie:** os cookies saem com `HttpOnly`, `SameSite=Lax` e
  `Secure` em HTTPS, e o id do cookie precisa ser exatamente o id do banco (um valor que
  o MySQL converteria para o mesmo número, como `1x` ou `01`, não autentica). Se algum
  JavaScript lia esses cookies por `document.cookie` (ex.: `admlogin`), ele para de
  funcionar: exponha os dados por um endpoint próprio.
- **Troca do id de sessão.** O login e o logout chamam `session_regenerate_id(true)`,
  mantendo os dados da sessão, quando a sessão está ativa e nada foi enviado ao
  navegador ainda. Depois de qualquer saída o PHP não consegue enviar o cookie novo, e o
  id não é trocado.

---

## Checklist de hardening

| Item | Status |
|---|---|
| Comparações em tempo constante (`hash_equals`) | Coberto (Fases 1 e 4) |
| Aleatoriedade forte (`random_bytes`, `random_int`) | Coberto (Fases 4 e 5) |
| Cookies `HttpOnly`/`Secure`/`SameSite=Lax` | Coberto (remember-me e identidade) |
| `session_regenerate_id` no login e no logout | Coberto (Fase 5) |
| Papel sempre resolvido no servidor, nunca lido do cookie | Coberto (Fases 2 a 4) |
| Rotação e revogação de remember-me; sair de todos os dispositivos | Coberto (Fase 4) |
| Tempo de resposta parecido para login inexistente e senha errada | Coberto (Fase 2) |
| CSRF em ações de escrita | Fora do escopo; fica para o projeto |
| Limite de tentativas de login | Fora do escopo; fica para o projeto |
| Tokens de integração com hash no banco | Fora do escopo; fica para o projeto |

---

## O que não foi tocado

- **`typecod` x `type`** em `Login::userExists()`: a assimetria entre os dois ramos do
  método já existia. As consultas foram parametrizadas, e o comportamento foi mantido.
- **Sessão com vários tipos logados** ao mesmo tempo: preservada. O Guard é uma fachada
  por tipo, não um substituto do modelo.
- **Model fora do login:** `getData`, `delete` e os demais métodos do Model são tratados
  em mudanças próprias, não aqui.

## Testes

A suíte do repositório roda na CI (GitHub Actions) em PHP 8.0 a 8.4, com a parte de
integração contra MySQL 8.0 e MariaDB 10.6 reais. As fases 2 a 5 têm 124 testes
próprios (contando cada caso de data provider):

| Fase | Arquivos | Testes |
|---|---|---|
| 2 — UserProvider, Guard, Login como adapter | `tests/Integration/Auth/TableUserProviderTest.php`, `LoginAdapterTest.php`, `SessionGuardTest.php` | 43 |
| 3 — AccessPolicy e despacho só de métodos públicos | `tests/Unit/Auth/MapAccessPolicyTest.php`, `tests/Unit/Dispatch/ControllerCallTest.php` | 20 |
| 4 — remember-me | `tests/Integration/Auth/RememberMeTest.php` | 33 |
| 5 — cookies, sessão e senha temporária | `tests/Integration/Auth/LoginHardeningTest.php`, `SessionRegenerationTest.php` | 28 |

Com a fase 5, a suíte inteira tem 408 testes. Para rodar localmente (o host só precisa
de Docker):

```sh
docker compose -f tests/docker-compose.yml run --rm php84 composer install -d tools/phpunit
docker compose -f tests/docker-compose.yml run --rm php84 tools/phpunit/vendor/bin/phpunit
```
