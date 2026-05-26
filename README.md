# Simple View Template Engine (React-like Syntax)

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

- Sintaxe estilo React/Next-like com transformação segura para pipeline interno.
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
    'extensions' => ['blade.php', 'php', 'tpl', 'html', 'htm', 'jsx', 'tsx'], // alias de template_extensions
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
| `extensions` / `template_extensions` | `array<int,string>` | `['blade.php','php','tpl','html','htm','jsx','tsx']` | Extensões buscadas quando não há extensão explícita |

> `jsx` e `tsx` aqui representam templates server-side com sintaxe React-like da engine (transformada internamente em tags `view:`), não JSX/TSX de frontend.

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

## Sintaxe principal (React/Next-like)

A sintaxe recomendada agora é baseada em componentes declarativos:

- Blocos condicionais e loops em formato JSX-like (`<If>`, `<ForEach>`, etc.).
- Expressões em texto usando `{ ... }` quando forem expressões PHP.
- Componentes de saída explícita (`<Echo />`, `<Raw />`).
- Sem suporte à sintaxe legada `@...` e `<blade:...>`.

### Exemplo rápido

```html
<If condition={$user !== null}>
  <h1>{ $user['name'] }</h1>
<Else />
  <h1>Visitante</h1>
</If>

<ForEach each={$items as $item}>
  <li>{ $item['title'] }</li>
</ForEach>

<Include template={'partials.menu'} data={['current' => $current]} />
```

### Componentes suportados

| Componente | Papel |
|---|---|
| `<If condition={...}>` | Condicional |
| `<ElseIf condition={...} />` | Condicional complementar |
| `<Else />` | Fallback condicional |
| `</If>` | Fechamento do bloco condicional |
| `<ForEach each={...}>` | Loop foreach |
| `</ForEach>` | Fechamento do loop |
| `<ForElse each={...}>` | Loop com fallback de vazio |
| `<Empty />` | Bloco de vazio no `ForElse` |
| `</ForElse>` | Fechamento do `ForElse` |
| `<Include template={...} data={...} />` | Include de template |
| `<IncludeWhen when={...} template={...} data={...} />` | Include condicional |
| `<Auth>` / `</Auth>` | Bloco para usuário autenticado |
| `<Guest>` / `</Guest>` | Bloco para visitante |
| `<Can ability={...} subject={...}>` | Bloco de autorização positiva |
| `<Cannot ability={...} subject={...}>` | Bloco de autorização negativa |
| `<Echo expression={...} />` | Saída escapada |
| `<Raw expression={...} />` | Saída raw |

### Saída e comentário
- `{{ ... }}` (escapado)
- `{!! ... !!}` (raw)
- `{{-- ... --}}` (comentário)
- Não use `@...` (modo removido).

---

## Segurança e confiabilidade

- Resolução de template limitada aos paths permitidos.
- Bloqueio de dependência circular em includes.
- Limite de profundidade de includes configurável.
- Escape de saída por padrão em `{{ }}`.
- Cache compilado com permissões `0700`.

---

## Limitações intencionais

- Sintaxe legada `@directive` e `<blade:...>` foi removida.

---

## Validação de sintaxe

```bash
find src -name '*.php' -print0 | xargs -0 -n1 php -l
```

---

## Observação

Este projeto não utiliza Composer.