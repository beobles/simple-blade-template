<?php

namespace Core\View\Engine;

use Core\View\Cache\CacheManager;
use Core\View\Compiler\Compiler;
use Core\View\Contract\EngineInterface;
use Core\View\Exception\FileNotFoundException;
use Core\View\Exception\IncludeException;
use Core\View\Exception\RuntimeException;
use Core\View\Security\SecurityManager;

/**
 * Engine principal de templates
 */
class TemplateEngine implements EngineInterface
{
    /**
     * Variáveis internas reservadas que não devem ser propagadas em includes.
     *
     * @var array<int, string>
     */
    protected const RESERVED_SCOPE_KEYS = ['__blade', '__templateData', '_engineContext'];

    protected const DEFAULT_CACHE_DIR_PREFIX = 'simple-blade-cache-';

    /**
     * Compilador responsável por transformar template em PHP.
     */
    protected Compiler $compiler;

    /**
     * Cache de templates compilados.
     */
    protected CacheManager $cache;

    /**
     * Camada de segurança para escaping/sanitização.
     */
    protected SecurityManager $security;

    /**
     * @var array<int, string>
     */
    protected array $viewPaths = [];

    /**
     * @var array<string, mixed>
     */
    protected array $sharedData = [];

    /**
     * Pilha de includes ativos para detectar recursão/ciclos.
     *
     * @var array<int, string>
     */
    protected array $includeStack = [];

    /**
     * Limite máximo de aninhamento de includes.
     */
    protected int $maxIncludeDepth = 20;

    /**
     * @var callable|null
     */
    protected $authResolver = null;

    /**
     * @var callable|null
     */
    protected $authorizationResolver = null;

    /**
     * @var callable|null
     */
    protected $csrfTokenResolver = null;

    /**
     * Extensões aceitas na busca automática de templates.
     *
     * @var array<int, string>
     */
    protected array $templateExtensions = EngineConfig::DEFAULT_TEMPLATE_EXTENSIONS;

    /**
     * Habilita rastreamento de contexto de renderização para desenvolvimento.
     */
    protected bool $debug = false;

    /**
     * Quando ativo, expõe o contexto atual como $__engineContext no template.
     */
    protected bool $exposeRenderContext = false;

    /**
     * Contexto consolidado da última renderização.
     *
     * @var array<string, mixed>
     */
    protected array $lastRenderContext = [];

    public function __construct(
        array $viewPaths = [],
        ?Compiler $compiler = null,
        ?CacheManager $cache = null,
        ?SecurityManager $security = null,
        ?string $cachePath = null,
        array $options = []
    ) {
        $this->security = $security ?? new SecurityManager();
        $this->compiler = $compiler ?? new Compiler($this->security);
        $cacheScope = json_encode([
            'cwd' => getcwd() ?: '',
            'view_paths' => $viewPaths,
        ]);
        if ($cacheScope === false) {
            $cacheScope = (string) (getcwd() ?: '') . '|' . implode('|', $viewPaths);
        }
        $defaultCachePath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . self::DEFAULT_CACHE_DIR_PREFIX
            . substr(hash('sha256', (string) $cacheScope), 0, 16);
        $this->cache = $cache ?? new CacheManager(
            $cachePath ?? $defaultCachePath
        );

        foreach ($viewPaths as $path) {
            $this->addViewPath($path);
        }

        $this->configure($options);
    }

    /**
     * Criar engine a partir de configuração centralizada.
     */
    public static function fromConfig(
        EngineConfig $config,
        ?Compiler $compiler = null,
        ?CacheManager $cache = null,
        ?SecurityManager $security = null
    ): self {
        $engine = new self(
            $config->getViewPaths(),
            $compiler,
            $cache,
            $security,
            $config->getCachePath(),
            [
                'cache_enabled' => $config->isCacheEnabled(),
                'debug' => $config->isDebug(),
                'expose_render_context' => $config->shouldExposeRenderContext(),
                'max_include_depth' => $config->getMaxIncludeDepth(),
                'template_extensions' => $config->getTemplateExtensions(),
            ]
        );

        return $engine;
    }

    /**
     * Renderizar template
     */
    public function render(string $template, array $data = []): string
    {
        $templateFile = $this->resolveTemplatePath($template);
        $payload = array_merge($this->sharedData, $data);
        return $this->renderTemplateFile($templateFile, $payload, $template);
    }

    /**
     * Atribuir variáveis compartilhadas
     */
    public function assign($key, $value = null): self
    {
        if (is_array($key)) {
            $this->sharedData = array_merge($this->sharedData, $key);
            return $this;
        }

        $this->sharedData[(string) $key] = $value;
        return $this;
    }

    /**
     * Adicionar caminho de views
     */
    public function addViewPath(string $path): self
    {
        $resolved = realpath($path);
        if ($resolved === false || !is_dir($resolved)) {
            throw new FileNotFoundException(
                "View path does not exist: {$path}",
                $path,
                [$path]
            );
        }

        if (!in_array($resolved, $this->viewPaths, true)) {
            $this->viewPaths[] = $resolved;
        }

        return $this;
    }

    /**
     * Configurar engine por array para setup direto e enxuto.
     *
     * Chaves suportadas:
     * - template_extensions: array<int, string>
     * - debug: bool
     * - expose_render_context: bool
     * - max_include_depth: int
     * - cache_enabled: bool
     *
     * @param array<string, mixed> $options
     */
    public function configure(array $options): self
    {
        if (isset($options['template_extensions']) && is_array($options['template_extensions'])) {
            $this->setTemplateExtensions($options['template_extensions']);
        }

        if (array_key_exists('debug', $options)) {
            $this->setDebug((bool) $options['debug']);
        }

        if (array_key_exists('expose_render_context', $options)) {
            $this->setExposeRenderContext((bool) $options['expose_render_context']);
        }

        if (array_key_exists('max_include_depth', $options)) {
            $this->setMaxIncludeDepth((int) $options['max_include_depth']);
        }

        if (array_key_exists('cache_enabled', $options)) {
            $this->cache->setEnabled((bool) $options['cache_enabled']);
        }

        return $this;
    }

    /**
     * Definir extensões de template para resolução automática.
     *
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

    /**
     * Ativar/desativar modo debug.
     */
    public function setDebug(bool $enabled): self
    {
        $this->debug = $enabled;
        return $this;
    }

    /**
     * Verificar se o modo debug está ativo.
     */
    public function isDebug(): bool
    {
        return $this->debug;
    }

    /**
     * Controlar exposição do contexto em tempo de render para templates.
     */
    public function setExposeRenderContext(bool $enabled): self
    {
        $this->exposeRenderContext = $enabled;
        return $this;
    }

    /**
     * Definir limite de profundidade de includes.
     */
    public function setMaxIncludeDepth(int $depth): self
    {
        $this->maxIncludeDepth = $depth > 0 ? $depth : 1;
        return $this;
    }

    /**
     * Obter o contexto da última renderização para troubleshooting em dev.
     *
     * @return array<string, mixed>
     */
    public function getLastRenderContext(): array
    {
        return $this->lastRenderContext;
    }

    /**
     * Definir callback de autenticação
     */
    public function setAuthResolver(callable $resolver): self
    {
        $this->authResolver = $resolver;
        return $this;
    }

    /**
     * Definir callback de autorização
     */
    public function setAuthorizationResolver(callable $resolver): self
    {
        $this->authorizationResolver = $resolver;
        return $this;
    }

    /**
     * Definir callback de token CSRF
     */
    public function setCsrfTokenResolver(callable $resolver): self
    {
        $this->csrfTokenResolver = $resolver;
        return $this;
    }

    protected function renderTemplateFile(string $templateFile, array $data, string $requestedTemplate = ''): string
    {
        $cacheKey = $this->buildCacheKey($templateFile);
        $compiledPath = $this->cache->getPath($cacheKey);
        $ephemeralPath = null;
        $cacheHit = false;
        $compiledNow = false;

        if ($this->cache->isFresh($cacheKey, $templateFile) && is_file($compiledPath)) {
            $cacheHit = true;
        } else {
            $content = file_get_contents($templateFile);
            if ($content === false) {
                throw new FileNotFoundException(
                    "Unable to read template file: {$templateFile}",
                    $templateFile,
                    [$templateFile]
                );
            }

            $compiledCode = $this->compiler->compile($content, $templateFile);
            $compiledNow = true;
            if (!$this->cache->put($cacheKey, $compiledCode)) {
                $ephemeralPath = $this->writeEphemeralCompiledFile($compiledCode);
                $compiledPath = $ephemeralPath;
            }
        }

        $this->lastRenderContext = [
            'timestamp' => microtime(true),
            'template' => $requestedTemplate !== '' ? $requestedTemplate : $templateFile,
            'resolved_template_file' => $templateFile,
            'compiled_path' => $compiledPath,
            'cache_key' => $cacheKey,
            'cache_hit' => $cacheHit,
            'compiled_now' => $compiledNow,
            'include_depth' => count($this->includeStack),
            'data_keys' => array_keys($data),
        ];

        try {
            return $this->evaluateCompiledFile($compiledPath, $templateFile, $data);
        } finally {
            if ($ephemeralPath !== null && is_file($ephemeralPath)) {
                @unlink($ephemeralPath);
            }
        }
    }

    protected function evaluateCompiledFile(string $compiledPath, string $templateFile, array $data): string
    {
        if (!is_file($compiledPath)) {
            throw new RuntimeException(
                "Compiled template file not found: {$compiledPath}",
                $templateFile,
                0,
                $compiledPath,
                'compiled_not_found',
                null,
                $this->lastRenderContext
            );
        }

        $__blade = [
            'include' => function ($template, array $scope = [], array $with = [], bool $required = true): string {
                return $this->renderIncludedTemplate($template, $scope, $with, $required);
            },
            'csrf' => function (): string {
                return $this->renderCsrfField();
            },
            'auth' => function (): bool {
                return $this->resolveAuth();
            },
            'can' => function ($ability, $subject = null): bool {
                return $this->resolveCan($ability, $subject);
            },
        ];

        $__templateData = $data;
        if ($this->debug && $this->exposeRenderContext) {
            $__templateData['_engineContext'] = $this->lastRenderContext;
        }
        extract($__templateData, EXTR_SKIP);

        ob_start();
        try {
            include $compiledPath;
            return (string) ob_get_clean();
        } catch (\Throwable $throwable) {
            ob_end_clean();
            throw new RuntimeException(
                "Error while rendering template: {$throwable->getMessage()}",
                $templateFile,
                0,
                '',
                'render_error',
                $throwable,
                $this->lastRenderContext
            );
        }
    }

    protected function renderIncludedTemplate($template, array $scope, array $with, bool $required): string
    {
        if (!is_string($template) || trim($template) === '') {
            if ($required) {
                throw new IncludeException(
                    'Invalid include template name',
                    '',
                    0,
                    (string) $template,
                    $this->includeStack
                );
            }
            return '';
        }

        if (count($this->includeStack) >= $this->maxIncludeDepth) {
            throw new IncludeException(
                'Maximum include depth exceeded',
                '',
                0,
                $template,
                $this->includeStack
            );
        }

        try {
            $file = $this->resolveTemplatePath($template);
        } catch (FileNotFoundException $exception) {
            if (!$required) {
                return '';
            }
            throw new IncludeException(
                $exception->getMessage(),
                $exception->getTemplateFile(),
                0,
                $template,
                $this->includeStack,
                $exception
            );
        }

        if (in_array($file, $this->includeStack, true)) {
            $chain = $this->includeStack;
            $chain[] = $file;
            throw new IncludeException(
                'Circular include dependency detected',
                $file,
                0,
                $template,
                $chain
            );
        }

        $this->includeStack[] = $file;
        try {
            $scope = $this->removeReservedScopeVariables($scope);
            $merged = array_merge($scope, $with);
            return $this->renderTemplateFile($file, $merged, $template);
        } finally {
            array_pop($this->includeStack);
        }
    }

    protected function renderCsrfField(): string
    {
        $token = '';
        if (is_callable($this->csrfTokenResolver)) {
            $token = (string) call_user_func($this->csrfTokenResolver);
        } elseif (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['_token'])) {
            $token = (string) $_SESSION['_token'];
        }

        if ($token === '') {
            $token = bin2hex(random_bytes(32));
            if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION) && is_array($_SESSION)) {
                $_SESSION['_token'] = $token;
            }
        }

        return '<input type="hidden" name="_token" value="' . $this->security->escapeAttr($token) . '">';
    }

    protected function resolveAuth(): bool
    {
        if (is_callable($this->authResolver)) {
            return (bool) call_user_func($this->authResolver);
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        return isset($_SESSION['user']) || (($_SESSION['authenticated'] ?? false) === true);
    }

    protected function resolveCan($ability, $subject = null): bool
    {
        if (is_callable($this->authorizationResolver)) {
            return (bool) call_user_func($this->authorizationResolver, $ability, $subject);
        }

        return false;
    }

    protected function resolveTemplatePath(string $template): string
    {
        if (empty($this->viewPaths)) {
            throw new FileNotFoundException('No view paths configured', $template, []);
        }

        if (str_contains($template, "\0")) {
            throw new FileNotFoundException('Invalid template name', $template, []);
        }

        $searched = [];
        foreach ($this->viewPaths as $viewPath) {
            foreach ($this->buildTemplateCandidates($template) as $candidate) {
                $fullPath = $viewPath . DIRECTORY_SEPARATOR . $candidate;
                $searched[] = $fullPath;
                if (!is_file($fullPath)) {
                    continue;
                }

                $resolved = realpath($fullPath);
                if ($resolved === false) {
                    continue;
                }

                if (!$this->isPathInsideViewPaths($resolved)) {
                    continue;
                }

                return $resolved;
            }
        }

        throw new FileNotFoundException(
            "Template not found: {$template}",
            $template,
            $searched
        );
    }

    protected function buildTemplateCandidates(string $template): array
    {
        $normalized = trim(str_replace('\\', '/', $template), '/');
        if ($normalized === '') {
            return [];
        }

        $dotNormalized = str_replace('.', '/', $normalized);

        $baseCandidates = array_values(array_unique([$normalized, $dotNormalized]));
        $candidates = [];

        foreach ($baseCandidates as $name) {
            if ($name === '') {
                continue;
            }

            if ($this->hasExplicitExtension($name)) {
                $candidates[] = $name;
                continue;
            }

            foreach ($this->templateExtensions as $extension) {
                $normalizedExtension = ltrim($extension, '.');
                if ($normalizedExtension === '') {
                    continue;
                }
                $candidates[] = $name . '.' . $normalizedExtension;
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Verificar se o nome informado já contém extensão explícita de arquivo.
     */
    protected function hasExplicitExtension(string $name): bool
    {
        $basename = basename($name);

        if ($basename === '' || str_ends_with($basename, '.')) {
            return false;
        }

        return str_contains($basename, '.');
    }

    protected function isPathInsideViewPaths(string $path): bool
    {
        foreach ($this->viewPaths as $basePath) {
            if ($path === $basePath || str_starts_with($path, $basePath . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    protected function buildCacheKey(string $templateFile): string
    {
        $mtime = '0';
        if (file_exists($templateFile)) {
            $time = filemtime($templateFile);
            $mtime = $time !== false ? (string) $time : '0';
        }
        return $templateFile . '|' . $mtime;
    }

    protected function writeEphemeralCompiledFile(string $compiledCode): string
    {
        $tmpDir = sys_get_temp_dir();
        $tmpPath = tempnam($tmpDir, 'template_compiled_');
        if ($tmpPath === false || file_put_contents($tmpPath, $compiledCode, LOCK_EX) === false) {
            throw new RuntimeException("Failed to create temporary compiled template file in {$tmpDir}");
        }

        return $tmpPath;
    }

    /**
     * Remover variáveis internas da engine para evitar vazamento entre escopos.
     *
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    protected function removeReservedScopeVariables(array $scope): array
    {
        foreach (self::RESERVED_SCOPE_KEYS as $key) {
            unset($scope[$key]);
        }

        return $scope;
    }
}
