<?php
/*
   Gojira_AccessPolicy - Controle de acesso deny-by-default, com camada de
   públicos. Ver docs/specs/auth-redesign-spec.md (§3.5, Fase 3).

   Integração em Controller::Call é OPT-IN: só entra em ação quando o projeto
   registra uma policy via Controller::setAccessPolicy(). Sem policy registrada,
   o comportamento do framework não muda em nada.
*/

   require_once( __DIR__ . '/authenticatable.interface.php' );

   interface Gojira_AccessPolicy {

      //Rota sem autenticação (ex.: home/index, page/*). Implementação padrão: false.
      public function isPublic( string $class, string $method ): bool;

      //Decisão final de autorização. Deny-by-default: só true se explicitamente permitido.
      public function authorize( string $class, string $method, ?Gojira_Authenticatable $user ): bool;

   }
