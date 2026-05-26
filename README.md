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
  - suporte a múltiplas extensões configuráveis (`.blade.php`, `.php`, `.tpl`, `.html`, etc.)
  - contexto de renderização para troubleshooting em desenvolvimento

## Uso básico (simples e direto)

```php
<?php

use Core\View\View;

$view = new View([
    'paths' => [__DIR__ . '/views'],
    'extensions' => ['blade.php', 'php', 'tpl', 'html', 'htm'],
    'debug' => true,
    'context' => true, // expõe $_engineContext no template em dev
]);

echo $view->render('pages.home', [
    'title' => 'Olá',
    'items' => [1, 2, 3],
]);

$view->share('appName', 'Simple Blade');
$lastContext = $view->context();
```

## Configuração avançada (controle total)

```php
<?php

use Core\View\Engine\EngineConfig;
use Core\View\Engine\TemplateEngine;

$config = EngineConfig::fromArray([
    'view_paths' => [__DIR__ . '/views'],
    'template_extensions' => ['blade.php', 'php', 'tpl', 'html'],
    'debug' => true,
    'expose_render_context' => true,
    'max_include_depth' => 30,
    'cache_enabled' => true,
]);

$engine = TemplateEngine::fromConfig($config);

echo $engine->render('pages.home');     // procura pages/home.blade.php, .php, .tpl, .html, .htm...
echo $engine->render('email/welcome.tpl'); // extensão explícita também funciona

// disponível no template como variável local quando debug + expose_render_context estão ativos:
// $_engineContext

$lastContext = $engine->getLastRenderContext();
```

## Observações

- O projeto não utiliza Composer.
- Para validação de sintaxe:

```bash
find src -name '*.php' -print0 | xargs -0 -n1 php -l
```