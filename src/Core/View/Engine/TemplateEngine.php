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
    protected Compiler $compiler;
    protected CacheManager $cache;
    protected SecurityManager $security;
    protected array $viewPaths = [];
    protected array $sharedData = [];
    protected array $includeStack = [];
    protected int $maxIncludeDepth = 20;
    protected $authResolver = null;
    protected $authorizationResolver = null;
    protected $csrfTokenResolver = null;

    public function __construct(
        array $viewPaths = [],
        ?Compiler $compiler = null,
        ?CacheManager $cache = null,
        ?SecurityManager $security = null,
        ?string $cachePath = null
    ) {
        $this->security = $security ?? new SecurityManager();
        $this->compiler = $compiler ?? new Compiler($this->security);
        $this->cache = $cache ?? new CacheManager(
            $cachePath ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'simple-blade-cache'
        );

        foreach ($viewPaths as $path) {
            $this->addViewPath($path);
        }
    }

    /**
     * Renderizar template
     */
    public function render(string $template, array $data = []): string
    {
        $templateFile = $this->resolveTemplatePath($template);
        $payload = array_merge($this->sharedData, $data);
        return $this->renderTemplateFile($templateFile, $payload);
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

    protected function renderTemplateFile(string $templateFile, array $data): string
    {
        $cacheKey = $this->buildCacheKey($templateFile);
        $compiledCode = null;

        if ($this->cache->isFresh($cacheKey, $templateFile)) {
            $compiledCode = $this->cache->get($cacheKey);
        }

        if ($compiledCode === null || $compiledCode === '') {
            $content = file_get_contents($templateFile);
            if ($content === false) {
                throw new FileNotFoundException(
                    "Unable to read template file: {$templateFile}",
                    $templateFile,
                    [$templateFile]
                );
            }

            $compiledCode = $this->compiler->compile($content, $templateFile);
            $this->cache->put($cacheKey, $compiledCode);
        }

        return $this->evaluateCompiledCode($compiledCode, $templateFile, $data);
    }

    protected function evaluateCompiledCode(string $compiledCode, string $templateFile, array $data): string
    {
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
        extract($__templateData, EXTR_SKIP);

        ob_start();
        try {
            eval('?>' . $compiledCode);
            return (string) ob_get_clean();
        } catch (\Throwable $throwable) {
            ob_end_clean();
            throw new RuntimeException(
                "Error while rendering template: {$throwable->getMessage()}",
                $templateFile,
                0,
                '',
                'render_error',
                $throwable
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
            unset($scope['__blade'], $scope['__templateData']);
            $merged = array_merge($scope, $with);
            return $this->renderTemplateFile($file, $merged);
        } finally {
            array_pop($this->includeStack);
        }
    }

    protected function renderCsrfField(): string
    {
        $token = '';
        if (is_callable($this->csrfTokenResolver)) {
            $token = (string) call_user_func($this->csrfTokenResolver);
        } elseif (isset($_SESSION['_token'])) {
            $token = (string) $_SESSION['_token'];
        }

        if ($token === '') {
            $token = bin2hex(random_bytes(16));
            if (isset($_SESSION) && is_array($_SESSION)) {
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

        return isset($_SESSION['user']) || (!empty($_SESSION['authenticated']) && $_SESSION['authenticated'] === true);
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
        $dotNormalized = str_replace('.', '/', $normalized);

        $candidates = [];
        foreach (array_unique([$normalized, $dotNormalized]) as $name) {
            if ($name === '') {
                continue;
            }

            if (str_ends_with($name, '.blade.php') || str_ends_with($name, '.php')) {
                $candidates[] = $name;
            } else {
                $candidates[] = $name . '.blade.php';
                $candidates[] = $name . '.php';
            }
        }

        return array_values(array_unique($candidates));
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
        $mtime = file_exists($templateFile) ? (string) filemtime($templateFile) : '0';
        return $templateFile . '|' . $mtime;
    }
}
