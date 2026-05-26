<?php

namespace Core\View\Compiler;

use Core\View\Contract\CompilerInterface;
use Core\View\Compiler\Syntax\DeclarativeSyntaxTransformer;
use Core\View\Exception\CompilationException;
use Core\View\Exception\SyntaxException;
use Core\View\Security\SecurityManager;

/**
 * Compilador de templates da sintaxe declarativa.
 */
class Compiler implements CompilerInterface
{
    protected SecurityManager $security;
    protected DeclarativeSyntaxTransformer $syntaxTransformer;
    protected array $customDirectives = [];
    protected array $internalCallbacks = [];
    protected const HTML_BLOCK_PREFIX = 'view:';
    protected const HTML_BLOCK_CLOSINGS = [
        'if' => 'endif',
        'unless' => 'endunless',
        'isset' => 'endisset',
        'empty' => 'endempty',
        'foreach' => 'endforeach',
        'forelse' => 'endforelse',
        'for' => 'endfor',
        'while' => 'endwhile',
        'switch' => 'endswitch',
        'auth' => 'endauth',
        'guest' => 'endguest',
        'can' => 'endcan',
        'cannot' => 'endcannot',
    ];
    protected const HTML_BLOCK_SELF_ONLY = [
        'elseif',
        'else',
        'case',
        'default',
        'break',
        'continue',
        'include',
        'includeIf',
        'includeWhen',
        'includeUnless',
        'json',
        'csrf',
        'php',
    ];

    public function __construct(SecurityManager $security = null)
    {
        $this->security = $security ?? new SecurityManager();
        $this->syntaxTransformer = new DeclarativeSyntaxTransformer();
    }

    /**
     * Compilar conteúdo
     */
    public function compile(string $content, string $templateFile = ''): string
    {
        try {
            $this->assertNoLegacyBladeSyntax($content, $templateFile);
            $content = $this->syntaxTransformer->transform($content, $templateFile);
            $content = $this->preprocessHtmlBlocks($content, $templateFile);

            // Tokenização
            $lexer = new Lexer($content);
            $tokens = $lexer->tokenize();

            // Parsing
            $parser = $this->createConfiguredParser($tokens, $templateFile);
            
            return $parser->parse();
        } catch (SyntaxException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new CompilationException(
                "Error compiling template: " . $e->getMessage(),
                $templateFile,
                0,
                0,
                'compilation_error',
                $e
            );
        }
    }

    /**
     * Validar sintaxe
     */
    public function validate(string $content): bool
    {
        try {
            $this->assertNoLegacyBladeSyntax($content, '');
            $content = $this->syntaxTransformer->transform($content, '');
            $content = $this->preprocessHtmlBlocks($content, '');
            $lexer = new Lexer($content);
            $tokens = $lexer->tokenize();
            $parser = $this->createConfiguredParser($tokens, '');
            $parser->parse();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Criar parser com todas as diretivas/callbacks registradas no compilador.
     */
    protected function createConfiguredParser(array $tokens, string $templateFile): Parser
    {
        $parser = new Parser($tokens, $templateFile);

        foreach ($this->customDirectives as $name => $callback) {
            $parser->addDirective($name, $callback);
        }

        foreach ($this->internalCallbacks as $name => $callback) {
            $parser->setInternalCallback($name, $callback);
        }

        return $parser;
    }

    /**
     * Registrar diretiva customizada
     */
    public function addDirective(string $name, callable $callback): self
    {
        $this->customDirectives[$name] = $callback;
        return $this;
    }

    /**
     * Registrar callback interno da engine
     */
    public function setInternalCallback(string $name, callable $callback): self
    {
        $this->internalCallbacks[$name] = $callback;
        return $this;
    }

    /**
     * Obter security manager
     */
    public function getSecurity(): SecurityManager
    {
        return $this->security;
    }

    protected function preprocessHtmlBlocks(string $content, string $templateFile): string
    {
        $pattern = '/<\s*(\/?)\s*' . preg_quote(self::HTML_BLOCK_PREFIX, '/') . '([a-zA-Z_][a-zA-Z0-9_]*)\b([^>]*)>/';
        $searchOffset = 0;

        return (string) preg_replace_callback(
            $pattern,
            function (array $match) use ($content, $templateFile, &$searchOffset): string {
                $fullMatch = $match[0] ?? '';
                $isClosingTag = ($match[1] ?? '') === '/';
                $name = $match[2] ?? '';
                $rawAttributes = $match[3] ?? '';

                $offset = strpos($content, $fullMatch, $searchOffset);
                if ($offset !== false) {
                    $searchOffset = $offset + strlen($fullMatch);
                }
                $line = $offset === false ? 1 : (substr_count(substr($content, 0, $offset), "\n") + 1);

                if ($isClosingTag) {
                    if (isset(self::HTML_BLOCK_CLOSINGS[$name])) {
                        return '@' . self::HTML_BLOCK_CLOSINGS[$name];
                    }

                    throw new SyntaxException(
                        "Directive @{$name} does not support block closing tag",
                        $templateFile,
                        $line,
                        $fullMatch,
                        "Use <" . self::HTML_BLOCK_PREFIX . "{$name} ... />"
                    );
                }

                $trimmedAttributes = trim($rawAttributes);
                $isSelfClosing = $trimmedAttributes !== '' && substr($trimmedAttributes, -1) === '/';

                if ($isSelfClosing) {
                    $trimmedAttributes = rtrim(substr($trimmedAttributes, 0, -1));
                }

                if (in_array($name, self::HTML_BLOCK_SELF_ONLY, true) && !$isSelfClosing) {
                    throw new SyntaxException(
                        "Directive @{$name} must use self-closing HTML block syntax",
                        $templateFile,
                        $line,
                        $fullMatch,
                        "Use <" . self::HTML_BLOCK_PREFIX . "{$name} ... />"
                    );
                }

                $expression = $this->extractHtmlBlockExpression($name, $trimmedAttributes);
                if ($expression !== null && trim($expression) !== '') {
                    return '@' . $name . '(' . trim($expression) . ')';
                }

                return '@' . $name;
            },
            $content
        );
    }

    protected function extractHtmlBlockExpression(string $directiveName, string $rawAttributes): ?string
    {
        if (trim($rawAttributes) === '') {
            return null;
        }

        $attributes = [];
        preg_match_all('/([a-zA-Z_][a-zA-Z0-9_-]*)\s*=\s*("([^"]*)"|\'([^\']*)\')/', $rawAttributes, $matches, PREG_SET_ORDER);
        foreach ($matches as $attributeMatch) {
            $key = $attributeMatch[1] ?? '';
            $value = $attributeMatch[3] ?? ($attributeMatch[4] ?? '');
            $attributes[$key] = $value;
        }

        $pick = static function (array $source, array $keys): ?string {
            foreach ($keys as $key) {
                if (array_key_exists($key, $source) && trim((string) $source[$key]) !== '') {
                    return (string) $source[$key];
                }
            }
            return null;
        };

        if (in_array($directiveName, ['if', 'elseif', 'unless', 'isset', 'empty', 'while', 'break', 'continue'], true)) {
            return $pick($attributes, ['condition', 'test', 'expression', 'args']);
        }

        if (in_array($directiveName, ['foreach', 'forelse', 'for'], true)) {
            return $pick($attributes, ['each', 'expression', 'args']);
        }

        if (in_array($directiveName, ['switch', 'case', 'includeWhen', 'includeUnless'], true)) {
            return $pick($attributes, ['expression', 'condition', 'args']);
        }

        if (in_array($directiveName, ['include', 'includeIf', 'json', 'php', 'can', 'cannot'], true)) {
            return $pick($attributes, ['expression', 'args']);
        }

        return $pick($attributes, ['expression', 'args']);
    }

    protected function assertNoLegacyBladeSyntax(string $content, string $templateFile): void
    {
        if (preg_match('/<\s*\/?\s*blade:/i', $content) === 1) {
            throw new SyntaxException(
                'Legacy <blade:...> syntax is no longer supported',
                $templateFile,
                0,
                '<blade:...>',
                'Use declarative components such as <If>, <ForEach>, <Include> and expressions in component attributes ({...}) or output ({{ ... }})'
            );
        }

        if (
            preg_match(
                '/(?:(?<=^)|(?<=[\s>]))@(?!@)(if|elseif|else|endif|unless|endunless|isset|endisset|empty|endempty|foreach|endforeach|forelse|endforelse|for|endfor|while|endwhile|switch|case|default|endswitch|break|continue|php|endphp|include|includeIf|includeWhen|includeUnless|json|csrf|auth|endauth|guest|endguest|can|endcan|cannot|endcannot)(?=\s|\(|$)/mi',
                $content
            ) === 1
        ) {
            throw new SyntaxException(
                'Legacy @directive syntax is no longer supported',
                $templateFile,
                0,
                '@directive',
                'Use declarative components such as <If>, <ElseIf />, <ForEach>, <Echo /> and <Raw />'
            );
        }
    }
}
