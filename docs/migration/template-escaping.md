# Escape de saída nos templates

Guia das opções de saída do Smarty e dos helpers de HTML. Nenhuma delas muda o comportamento de quem não as liga.

## Opções

| Opção | Padrão | Efeito |
|---|---|---|
| `GOJIRA_ESCAPE_HTML` | desligada | `setEscapeHtml(true)` no Smarty: toda variável impressa com `{$x}` sai escapada para HTML |
| `GOJIRA_TEMPLATE_GLOBALS` (array ou `'a,b'`) ou `Engine::setTemplateGlobals([...])` | não definida | O template só recebe as chaves listadas de `$globals` |

Defina as constantes antes de incluir o `system/init.php`:

```php
define('GOJIRA_ESCAPE_HTML', true);
define('GOJIRA_TEMPLATE_GLOBALS', ['user', 'environment', 'tools']);
```

## `$globals` no template

- `{$globals}` é sempre uma cópia rasa de `$globals` sem `db`, `database`, `conn`, `connection` e `smarty`. A configuração do banco (com a senha) não chega ao template em nenhum modo.
- Sem allowlist (modo antigo, obsoleto), as demais chaves continuam em `{$globals}` e como variáveis soltas (`{$user}`, `{$tools}`), e é emitido `E_USER_DEPRECATED` uma vez por requisição ao renderizar um template.
- Com allowlist, só as chaves listadas entram, em `{$globals}` e como variáveis soltas.
- `{$env}` (`$globals->environment`) continua disponível nos dois modos. Ele contém caminhos absolutos do servidor (`absPath`, `systemPath` e outros): não o imprima em páginas públicas.

## O que `GOJIRA_ESCAPE_HTML` não cobre

O escape automático vale para texto e atributos HTML entre aspas. Ele **não** protege:

- saída de plugins, funções e modificadores que geram HTML;
- `{$x nofilter}`;
- o HTML devolvido pelos helpers (`pagination()`, `columnHeader()`, `embedVideo()` e outros), que precisa de `nofilter` quando a opção está ligada; o `echo` dos helpers (`getSeo()`, `load()`, `columnHeader()`) não passa pelo Smarty;
- valores dentro de `<script>` ou de atributos de evento (`on*`): use `{$x|escape:'javascript'}`;
- valores dentro de `<style>` ou `style="..."`: não interpole dado do usuário em CSS;
- o esquema de uma URL em `href`/`src`: o escape HTML não impede um esquema perigoso. Para um parâmetro de URL, use `{$x|escape:'url'}`; para uma URL inteira vinda do usuário, valide o esquema (o helper `$tools->escapeUrl()` só aceita `http:`, `https:`, `mailto:` e URL sem esquema, e troca o resto por `#`).

## Helpers

- `Tool_Html::escapeAttr($v)` (ou `$tools->escapeAttr($v)`): `htmlspecialchars` com `ENT_QUOTES`, para texto e atributo HTML entre aspas. Só serve para contexto HTML.
- `Tool_Html::escapeUrl($v)` (ou `$tools->escapeUrl($v)`): recusa esquemas fora da lista (vira `#`) e escapa aspas e sinais de tag, mantendo o `&` da query string.
- `pagination()` aplica `urlencode` aos valores de `$_GET` e ignora valor que não é texto.
- `getSeo()`, `columnHeader()`, `load()`, `embedVideo()` e `issu_get_embed()` escapam atributos e o `<title>`. Valores simples geram a mesma saída de antes; `&` em texto passa a sair como `&amp;`.

## Login em modo cookie (obsoleto)

Com `Login::setCookie(true)`, `getLogged()` devolve o valor do cookie escapado para HTML, porque o cookie vem do navegador e não é assinado. Não grave esse valor de volta no banco nem o escape outra vez. O modo sessão não mudou. Prefira o modo sessão com o remember-me do `Gojira_SessionGuard`.
