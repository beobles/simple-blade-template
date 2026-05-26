# Simple Blade Template Engine

Motor de template em PHP, sem Composer e sem dependências externas.

## Recursos

- Lexer robusto com tratamento de:
  - variáveis `{{ }}` e `{!! !!}`
  - comentários `{{-- --}}`
  - escapes `@@`
  - diretivas com argumentos complexos (parênteses e aspas balanceados)
- Parser com validação estrutural e mensagens de erro de sintaxe detalhadas
- Diretivas principais:
  - Condição: `@if`, `@elseif`, `@else`, `@endif`, `@unless`, `@endunless`
  - Estado: `@isset`, `@endisset`, `@empty`, `@endempty`
  - Loops: `@foreach`, `@endforeach`, `@forelse`, `@empty`, `@endforelse`, `@for`, `@endfor`, `@while`, `@endwhile`
  - Switch: `@switch`, `@case`, `@default`, `@endswitch`, `@break`, `@continue`
  - Include: `@include`, `@includeIf`, `@includeWhen`, `@includeUnless`
  - Helpers: `@json`, `@csrf`, `@auth`, `@endauth`, `@guest`, `@endguest`, `@can`, `@endcan`, `@cannot`, `@endcannot`
- Engine de renderização com:
  - resolução segura de templates por paths permitidos
  - cache de templates compilados
  - proteção contra include circular e profundidade excessiva
  - suporte a callbacks de autenticação/autorização/CSRF

## Uso básico

```php
<?php

use Core\View\Engine\TemplateEngine;

$engine = new TemplateEngine([
    __DIR__ . '/views',
]);

echo $engine->render('pages.home', [
    'title' => 'Olá',
    'items' => [1, 2, 3],
]);
```

## Observações

- O projeto não utiliza Composer.
- Para validação de sintaxe:

```bash
find src -name '*.php' -print0 | xargs -0 -n1 php -l
```