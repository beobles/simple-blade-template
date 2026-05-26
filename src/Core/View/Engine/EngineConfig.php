<?php

namespace Core\View\Engine;

/**
 * Objeto de configuração central para a TemplateEngine.
 *
 * Esta classe concentra os principais controles operacionais da engine
 * (paths, cache, extensões e comportamento em desenvolvimento), facilitando
 * uma inicialização explícita e previsível em diferentes ambientes.
 */
class EngineConfig
{
    /**
     * Extensões padrão aceitas pela engine.
     *
     * @var array<int, string>
     */
    public const DEFAULT_TEMPLATE_EXTENSIONS = ['blade.php', 'php', 'tpl', 'html', 'htm'];

    /**
     * @var array<int, string>
     */
    protected array $viewPaths = [];

    protected ?string $cachePath = null;
    protected bool $cacheEnabled = true;
    protected bool $debug = false;
    protected bool $exposeRenderContext = false;
    protected int $maxIncludeDepth = 20;

    /**
     * @var array<int, string>
     */
    protected array $templateExtensions = self::DEFAULT_TEMPLATE_EXTENSIONS;

    /**
     * @param array<int, string> $viewPaths
     */
    public function __construct(array $viewPaths = [])
    {
        $this->viewPaths = $viewPaths;
    }

    /**
     * Criar configuração a partir de array simples.
     *
     * Chaves suportadas:
     * - view_paths: array<int, string>
     * - cache_path: string|null
     * - cache_enabled: bool
     * - debug: bool
     * - expose_render_context: bool
     * - max_include_depth: int
     * - template_extensions: array<int, string>
     *
     * @param array<string, mixed> $options
     */
    public static function fromArray(array $options): self
    {
        $config = new self((array) ($options['view_paths'] ?? []));
        $config->setCachePath(isset($options['cache_path']) ? (string) $options['cache_path'] : null);
        $config->setCacheEnabled((bool) ($options['cache_enabled'] ?? true));
        $config->setDebug((bool) ($options['debug'] ?? false));
        $config->setExposeRenderContext((bool) ($options['expose_render_context'] ?? false));
        $config->setMaxIncludeDepth((int) ($options['max_include_depth'] ?? 20));

        if (isset($options['template_extensions']) && is_array($options['template_extensions'])) {
            $config->setTemplateExtensions($options['template_extensions']);
        }

        return $config;
    }

    /**
     * @param array<int, string> $paths
     */
    public function setViewPaths(array $paths): self
    {
        $this->viewPaths = $paths;
        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function getViewPaths(): array
    {
        return $this->viewPaths;
    }

    public function setCachePath(?string $path): self
    {
        $this->cachePath = $path;
        return $this;
    }

    public function getCachePath(): ?string
    {
        return $this->cachePath;
    }

    public function setCacheEnabled(bool $enabled): self
    {
        $this->cacheEnabled = $enabled;
        return $this;
    }

    public function isCacheEnabled(): bool
    {
        return $this->cacheEnabled;
    }

    public function setDebug(bool $debug): self
    {
        $this->debug = $debug;
        return $this;
    }

    public function isDebug(): bool
    {
        return $this->debug;
    }

    public function setExposeRenderContext(bool $enabled): self
    {
        $this->exposeRenderContext = $enabled;
        return $this;
    }

    public function shouldExposeRenderContext(): bool
    {
        return $this->exposeRenderContext;
    }

    public function setMaxIncludeDepth(int $depth): self
    {
        $this->maxIncludeDepth = $depth > 0 ? $depth : 1;
        return $this;
    }

    public function getMaxIncludeDepth(): int
    {
        return $this->maxIncludeDepth;
    }

    /**
     * @param array<int, string> $extensions
     */
    public function setTemplateExtensions(array $extensions): self
    {
        $normalized = [];
        foreach ($extensions as $extension) {
            $value = ltrim(strtolower(trim((string) $extension)), '.');
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        if (!empty($normalized)) {
            $this->templateExtensions = array_values(array_unique($normalized));
        }

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function getTemplateExtensions(): array
    {
        return $this->templateExtensions;
    }
}
