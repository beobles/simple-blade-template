<?php

namespace Core\View;

use Core\View\Engine\TemplateEngine;

/**
 * Fachada de alto nível para uso diário da engine de templates.
 *
 * Objetivo:
 * - oferecer API curta e direta (`new View($options)`, `render(...)`)
 * - manter acesso ao poder total da TemplateEngine sem verbosidade inicial
 *
 * Exemplo rápido:
 * <code>
 * $view = new View([
 *     'paths' => [__DIR__ . '/views'],
 *     'extensions' => ['tpl', 'html', 'blade.php', 'php'],
 *     'debug' => true,
 *     'context' => true,
 * ]);
 *
 * echo $view->render('pages.home', ['title' => 'Olá']);
 * </code>
 */
class View
{
    /**
     * Engine principal utilizada internamente.
     */
    protected TemplateEngine $engine;

    /**
     * Criar fachada com configuração direta por array.
     *
     * Opções aceitas:
     * - paths: array<int, string>                Caminhos de views
     * - view_paths: array<int, string>           Alias de paths
     * - cache_path: string|null                  Diretório de cache
     * - cache: bool                              Alias de cache_enabled
     * - cache_enabled: bool                      Ativa/desativa cache
     * - debug: bool                              Modo debug
     * - context: bool                            Alias de expose_render_context
     * - expose_render_context: bool              Expor contexto no template
     * - max_include_depth: int                   Limite de includes aninhados
     * - extensions: array<int, string>           Alias de template_extensions
     * - template_extensions: array<int, string>  Extensões permitidas
     *
     * @param array<string, mixed> $options
     */
    public function __construct(array $options = [])
    {
        $normalized = $this->prepareOptions($options);
        $this->engine = new TemplateEngine(
            (array) ($normalized['view_paths'] ?? []),
            null,
            null,
            null,
            isset($normalized['cache_path']) ? (string) $normalized['cache_path'] : null,
            $normalized
        );
    }

    /**
     * Renderizar template.
     *
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        return $this->engine->render($template, $data);
    }

    /**
     * Compartilhar variáveis globais.
     *
     * Quando $key for array, $value é ignorado e o array é mesclado como lote.
     *
     * @param array<string, mixed>|string $key
     * @param mixed $value
     */
    public function assign($key, $value = null): self
    {
        $this->engine->assign($key, $value);
        return $this;
    }

    /**
     * Alias semântico para assign().
     *
     * Quando $key for array, $value é ignorado e o array é mesclado como lote.
     *
     * @param array<string, mixed>|string $key
     * @param mixed $value
     */
    public function share($key, $value = null): self
    {
        return $this->assign($key, $value);
    }

    /**
     * Adicionar caminho de views em runtime.
     */
    public function addPath(string $path): self
    {
        $this->engine->addViewPath($path);
        return $this;
    }

    /**
     * Definir callback de autenticação.
     */
    public function auth(callable $resolver): self
    {
        $this->engine->setAuthResolver($resolver);
        return $this;
    }

    /**
     * Definir callback de autorização.
     */
    public function can(callable $resolver): self
    {
        $this->engine->setAuthorizationResolver($resolver);
        return $this;
    }

    /**
     * Definir callback de token CSRF.
     */
    public function csrf(callable $resolver): self
    {
        $this->engine->setCsrfTokenResolver($resolver);
        return $this;
    }

    /**
     * Reconfigurar opções suportadas em runtime.
     *
     * @param array<string, mixed> $options
     */
    public function configure(array $options): self
    {
        $normalized = $this->prepareOptions($options);

        if (isset($normalized['view_paths']) && is_array($normalized['view_paths'])) {
            foreach ($normalized['view_paths'] as $path) {
                $this->engine->addViewPath((string) $path);
            }
        }

        $this->engine->configure($normalized);
        return $this;
    }

    /**
     * Obter contexto da última renderização.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->engine->getLastRenderContext();
    }

    /**
     * Expor engine para cenários avançados.
     */
    public function engine(): TemplateEngine
    {
        return $this->engine;
    }

    /**
     * Normalizar aliases e aplicar defaults para configuração direta no View.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected function prepareOptions(array $options): array
    {
        $normalized = array_merge([
            'view_paths' => [],
            'cache_enabled' => true,
            'debug' => false,
            'expose_render_context' => false,
            'max_include_depth' => 20,
            'template_extensions' => TemplateEngine::DEFAULT_TEMPLATE_EXTENSIONS,
        ], $options);

        if (isset($normalized['paths']) && !isset($normalized['view_paths'])) {
            $normalized['view_paths'] = (array) $normalized['paths'];
        }

        if (isset($normalized['extensions']) && !isset($normalized['template_extensions'])) {
            $normalized['template_extensions'] = (array) $normalized['extensions'];
        }

        if (array_key_exists('cache', $normalized) && !array_key_exists('cache_enabled', $normalized)) {
            $normalized['cache_enabled'] = (bool) $normalized['cache'];
        }

        if (array_key_exists('context', $normalized) && !array_key_exists('expose_render_context', $normalized)) {
            $normalized['expose_render_context'] = (bool) $normalized['context'];
        }

        $normalized['view_paths'] = (array) ($normalized['view_paths'] ?? []);
        $normalized['template_extensions'] = (array) ($normalized['template_extensions'] ?? []);
        $normalized['cache_enabled'] = (bool) ($normalized['cache_enabled'] ?? true);
        $normalized['debug'] = (bool) ($normalized['debug'] ?? false);
        $normalized['expose_render_context'] = (bool) ($normalized['expose_render_context'] ?? false);
        $normalized['max_include_depth'] = (int) ($normalized['max_include_depth'] ?? 20);
        if (array_key_exists('cache_path', $normalized) && $normalized['cache_path'] !== null) {
            $normalized['cache_path'] = (string) $normalized['cache_path'];
        }

        return $normalized;
    }
}
