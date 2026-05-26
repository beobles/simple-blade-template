# Simple Blade Template Engine

Motor de templates em PHP, sem Composer e sem dependências externas.

## Filosofia da API

Toda configuração é feita diretamente no `View()`.

```php
use Core\View\View;

$view = new View([...opções...]);
echo $view->render('pages.home', [...dados...]);
```

Sem objetos extras de configuração.

---

## O que este sistema entrega

- Sintaxe estilo Blade com lexer + parser + compiler próprios.
- Renderização segura com resolução restrita de paths.
- Cache de templates compilados com permissões restritas.
- Controle de includes com proteção contra ciclo e profundidade máxima.
- Callbacks de autenticação, autorização e CSRF.
- API curta para uso diário e limpa para manutenção.

---

## Instanciação rápida

```php
<?php

use Core\View\View;

$view = new View([
    'paths' => [__DIR__ . '/views'],
]);

echo $view->render('pages.home', [
    'title' => 'Olá',
]);
```

---

## Configuração completa no `new View([...])`

```php
<?php

use Core\View\View;

$view = new View([
    'paths' => [__DIR__ . '/views'],            // alias de view_paths
    'cache_path' => __DIR__ . '/storage/cache', // opcional
    'cache' => true,                            // alias de cache_enabled
    'debug' => true,
    'context' => true,                          // alias de expose_render_context
    'max_include_depth' => 30,
    'extensions' => ['blade.php', 'php', 'tpl', 'html', 'htm'], // alias de template_extensions
]);
```

### Opções disponíveis

| Opção | Tipo | Padrão | Descrição |
|---|---|---|---|
| `paths` / `view_paths` | `array<int,string>` | `[]` | Lista de diretórios permitidos para templates |
| `cache_path` | `string|null` | `null` | Caminho do cache compilado |
| `cache` / `cache_enabled` | `bool` | `true` | Ativa/desativa cache de compilação |
| `debug` | `bool` | `false` | Ativa modo debug da engine |
| `context` / `expose_render_context` | `bool` | `false` | Injeta `_engineContext` no template |
| `max_include_depth` | `int` | `20` | Limite de includes aninhados |
| `extensions` / `template_extensions` | `array<int,string>` | `['blade.php','php','tpl','html','htm']` | Extensões buscadas quando não há extensão explícita |

---

## Métodos principais do `View`

### `render(string $template, array $data = []): string`

Renderiza o template solicitado.

```php
echo $view->render('pages.home', ['title' => 'Dashboard']);
echo $view->render('emails/welcome.tpl', ['name' => 'Ana']); // extensão explícita
```

### `assign(array|string $key, mixed $value = null): self`
### `share(array|string $key, mixed $value = null): self`

Define variáveis globais compartilhadas.

```php
$view->share('appName', 'Portal');
$view->assign([
    'company' => 'Acme',
    'year' => 2026,
]);
```

### `addPath(string $path): self`

Adiciona novo diretório de views em runtime.

### `configure(array $options): self`

Reaplica opções no objeto já instanciado.

```php
$view->configure([
    'debug' => true,
    'context' => true,
]);
```

### `auth(callable $resolver): self`
### `can(callable $resolver): self`
### `csrf(callable $resolver): self`

Conecta integrações de autenticação/autorização/token.

```php
$view
    ->auth(fn () => isset($_SESSION['user']))
    ->can(fn (string $ability, $subject = null) => $ability === 'view-dashboard')
    ->csrf(fn () => $_SESSION['_token'] ?? '');
```

### `context(): array`

Retorna contexto da última renderização.

### `engine(): TemplateEngine`

Exposição da engine interna para cenários realmente avançados.

---

## Diretivas suportadas

## Nova sintaxe alternativa: blocos HTML (`<blade:...>`)

Além da sintaxe `@...`, agora você pode escrever templates com blocos HTML namespaced:

- Prefixo reservado: `blade:`
- Tags de bloco com fechamento explícito (ex.: `<blade:if ...>...</blade:if>`)
- Tags de controle pontual em modo self-closing (ex.: `<blade:else />`, `<blade:include ... />`)
- A sintaxe antiga continua funcionando normalmente (retrocompatível)

### Exemplos rápidos

```html
<blade:if condition="$user !== null">
  <h1>{{ $user['name'] }}</h1>
<blade:else />
  <h1>Visitante</h1>
</blade:if>

<blade:foreach each="$items as $item">
  <li>{{ $item['title'] }}</li>
</blade:foreach>

<blade:include expression="'partials.menu', ['current' => $current]" />
```

### Mapeamento principal

| Bloco HTML | Diretiva equivalente |
|---|---|
| `<blade:if condition="...">` | `@if(...)` |
| `<blade:elseif condition="..." />` | `@elseif(...)` |
| `<blade:else />` | `@else` |
| `</blade:if>` | `@endif` |
| `<blade:foreach each="...">` | `@foreach(...)` |
| `</blade:foreach>` | `@endforeach` |
| `<blade:include expression="..." />` | `@include(...)` |
| `<blade:includeWhen expression="..." />` | `@includeWhen(...)` |
| `<blade:auth>` | `@auth` |
| `</blade:auth>` | `@endauth` |

### Saída e comentário
- `{{ ... }}` (escapado)
- `{!! ... !!}` (raw)
- `{{-- ... --}}` (comentário)
- `@@` (escape de `@`)

### Condicionais
- `@if`, `@elseif`, `@else`, `@endif`
- `@unless`, `@endunless`
- `@isset`, `@endisset`
- `@empty`, `@endempty`

### Loops
- `@foreach`, `@endforeach`
- `@forelse`, `@empty`, `@endforelse`
- `@for`, `@endfor`
- `@while`, `@endwhile`
- `@break`, `@continue`

### Switch
- `@switch`, `@case`, `@default`, `@endswitch`

### Includes
- `@include`
- `@includeIf`
- `@includeWhen`
- `@includeUnless`

### Helpers
- `@json`
- `@csrf`
- `@auth`, `@endauth`
- `@guest`, `@endguest`
- `@can`, `@endcan`
- `@cannot`, `@endcannot`

---

## Segurança e confiabilidade

- Resolução de template limitada aos paths permitidos.
- Bloqueio de dependência circular em includes.
- Limite de profundidade de includes configurável.
- Escape de saída por padrão em `{{ }}`.
- Cache compilado com permissões `0700`.

---

## Limitações intencionais

- `@php ... @endphp` em bloco não é suportado.
- Use apenas `@php(expressão)`.

---

## Validação de sintaxe

```bash
find src -name '*.php' -print0 | xargs -0 -n1 php -l
```

---

## Observação

Este projeto não utiliza Composer.