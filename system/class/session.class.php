<?php
/*
   Gojira_Session - Abertura da sessão do PHP pelo framework.

   Sem opção nenhuma, o bootstrap continua chamando session_start() em toda
   requisição, com os parâmetros de cookie do php.ini (comportamento da 1.0).

   Opções (constantes definidas antes de carregar o system/init.php):
   - GOJIRA_NO_SESSION_AUTOSTART = true: o init.php e o Login não abrem a
     sessão. Ela é aberta sob demanda: Gojira_Session::start() (o Login chama ao
     autenticar) ou Gojira_Session::resume(), que só abre quando o navegador já
     mandou o cookie de sessão. Uma requisição anônima não recebe Set-Cookie.
   - GOJIRA_SESSION_HARDENING = true: aplica os parâmetros abaixo também à
     abertura automática. No modo sob demanda eles sempre valem.

   Parâmetros aplicados quando a sessão é aberta por esta classe:
   - cookie HttpOnly;
   - SameSite=Lax (um valor já configurado no php.ini é mantido);
   - Secure quando Gojira_RequestScheme::isHttps() (HTTPS direto, proxy
     confiável em GOJIRA_TRUSTED_PROXIES ou GOJIRA_FORCE_SECURE_COOKIES);
   - session.use_strict_mode=1 e session.use_only_cookies=1: um id de sessão
     que o servidor não emitiu é recusado e trocado por um novo.

   Atenção: uma sessão aberta pelo projeto antes do system/init.php (por
   exemplo um session_start() no configs.php) já está ativa quando o framework
   chega aqui, e então nenhuma das opções acima vale. Deixe a abertura para o
   framework, como faz o project_demo.
*/

   require_once( __DIR__ . '/auth/requestscheme.class.php' );

   class Gojira_Session {

      //Abertura automática do bootstrap (system/init.php) e do construtor do Login.
      public static function autostart(): void {

         if( self::onDemand() ) return;

         if( defined( 'GOJIRA_SESSION_HARDENING' ) && GOJIRA_SESSION_HARDENING == true ){
            self::start();
            return;
         }

         //Comportamento da 1.0, sem nenhuma opção.
         @session_start();
      }

      //true quando GOJIRA_NO_SESSION_AUTOSTART está ligada.
      public static function onDemand(): bool {
         return defined( 'GOJIRA_NO_SESSION_AUTOSTART' ) && GOJIRA_NO_SESSION_AUTOSTART == true;
      }

      public static function isActive(): bool {
         return session_status() === PHP_SESSION_ACTIVE;
      }

      //Abre a sessão com os parâmetros de cookie endurecidos. Retorna false
      //quando não é possível (sessões desligadas ou saída já enviada).
      public static function start(): bool {

         if( self::isActive() ) return true;
         if( session_status() === PHP_SESSION_DISABLED ) return false;

         if( headers_sent( $file, $line ) ){
            error_log( '[GOJIRA] Gojira_Session: sessão não aberta porque já houve saída'
                     . ( $file ? ' (' . $file . ':' . $line . ')' : '' ) );
            return false;
         }

         self::applyCookieParams();

         return session_start();
      }

      //Reabre uma sessão existente: só abre quando o navegador mandou o cookie.
      public static function resume(): bool {

         if( self::isActive() ) return true;

         $name = session_name();
         if( !isset( $_COOKIE[ $name ] ) || !is_string( $_COOKIE[ $name ] ) || $_COOKIE[ $name ] === '' ) return false;

         return self::start();
      }

      //Usado pelo Login: no modo sob demanda, abre a sessão antes de gravar.
      //No modo automático não faz nada (a sessão já foi aberta no bootstrap).
      public static function ensureStarted(): bool {
         return self::onDemand() ? self::start() : self::isActive();
      }

      //Usado pelo Login: no modo sob demanda, reabre a sessão antes de ler.
      public static function resumeIfPresent(): bool {
         return self::onDemand() ? self::resume() : self::isActive();
      }

      //Parâmetros recomendados (OWASP): a sessão aceita só ids emitidos pelo
      //servidor e o cookie não fica acessível a JavaScript nem viaja em
      //requisição cruzada de outro site, salvo navegação de nível superior.
      public static function cookieParams(): array {

         $current = session_get_cookie_params();

         return [
            'lifetime' => (int)$current['lifetime'],
            'path'     => (string)$current['path'],
            'domain'   => (string)$current['domain'],
            'secure'   => !empty( $current['secure'] ) || Gojira_RequestScheme::isHttps(),
            'httponly' => true,
            'samesite' => !empty( $current['samesite'] ) ? (string)$current['samesite'] : 'Lax',
         ];
      }

      private static function applyCookieParams(): void {
         ini_set( 'session.use_strict_mode', '1' );
         ini_set( 'session.use_only_cookies', '1' );
         session_set_cookie_params( self::cookieParams() );
      }

   }
