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
        $result = '';
        $cursor = 0;
        $length = strlen($content);
        $prefixPattern = '/\G<\s*(\/?)\s*' . preg_quote(self::HTML_BLOCK_PREFIX, '/') . '([a-zA-Z_][a-zA-Z0-9_]*)\b/A';

        while ($cursor < $length) {
            $start = strpos($content, '<', $cursor);
            if ($start === false) {
                $result .= substr($content, $cursor);
                break;
            }

            $result .= substr($content, $cursor, $start - $cursor);
            if (!preg_match($prefixPattern, $content, $match, 0, $start)) {
                $result .= '<';
                $cursor = $start + 1;
                continue;
            }

            $tagHeadLength = strlen($match[0] ?? '');
            $tagEnd = $this->findTagEnd($content, $start + $tagHeadLength);
            if ($tagEnd === null) {
                $result .= substr($content, $start);
                break;
            }

            $fullMatch = substr($content, $start, ($tagEnd - $start) + 1);
            $isClosingTag = ($match[1] ?? '') === '/';
            $name = $match[2] ?? '';
            $rawAttributes = substr($content, $start + $tagHeadLength, $tagEnd - ($start + $tagHeadLength));
            $line = substr_count(substr($content, 0, $start), "\n") + 1;

            if ($isClosingTag) {
                if (isset(self::HTML_BLOCK_CLOSINGS[$name])) {
                    $result .= '@' . self::HTML_BLOCK_CLOSINGS[$name];
                    $cursor = $tagEnd + 1;
                    continue;
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
                $result .= '@' . $name . '(' . trim($expression) . ')';
            } else {
                $result .= '@' . $name;
            }

            $cursor = $tagEnd + 1;
        }

        return $result;
    }

    protected function extractHtmlBlockExpression(string $directiveName, string $rawAttributes): ?string
    {
        if (trim($rawAttributes) === '') {
            return null;
        }

        $attributes = $this->parseHtmlBlockAttributes($rawAttributes);

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

    /**
     * @return array<string, string>
     */
    protected function parseHtmlBlockAttributes(string $rawAttributes): array
    {
        $attributes = [];
        $length = strlen($rawAttributes);
        $index = 0;

        while ($index < $length) {
            while ($index < $length && ctype_space($rawAttributes[$index])) {
                $index++;
            }

            if ($index >= $length || $rawAttributes[$index] === '/') {
                break;
            }

            if (!preg_match('/\G([a-zA-Z_][a-zA-Z0-9_-]*)/A', $rawAttributes, $nameMatch, 0, $index)) {
                $index++;
                continue;
            }

            $key = $nameMatch[1] ?? '';
            $index += strlen($key);

            while ($index < $length && ctype_space($rawAttributes[$index])) {
                $index++;
            }

            if ($index >= $length || $rawAttributes[$index] !== '=') {
                continue;
            }

            $index++;
            while ($index < $length && ctype_space($rawAttributes[$index])) {
                $index++;
            }

            if ($index >= $length) {
                break;
            }

            $first = $rawAttributes[$index];
            if ($first !== '"' && $first !== "'") {
                continue;
            }

            $quote = $first;
            $index++;
            $valueStart = $index;
            $escaped = false;

            while ($index < $length) {
                $char = $rawAttributes[$index];
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === $quote) {
                    break;
                }
                $index++;
            }

            $value = substr($rawAttributes, $valueStart, max(0, $index - $valueStart));
            $attributes[$key] = stripcslashes($value);

            if ($index < $length && $rawAttributes[$index] === $quote) {
                $index++;
            }
        }

        return $attributes;
    }

    protected function findTagEnd(string $content, int $index): ?int
    {
        $length = strlen($content);
        $quote = null;
        $escaped = false;
        $braceDepth = 0;

        while ($index < $length) {
            $char = $content[$index];

            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === $quote) {
                    $quote = null;
                }
                $index++;
                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                $index++;
                continue;
            }

            if ($char === '{') {
                $braceDepth++;
                $index++;
                continue;
            }

            if ($char === '}') {
                if ($braceDepth > 0) {
                    $braceDepth--;
                }
                $index++;
                continue;
            }

            if ($char === '>' && $braceDepth === 0) {
                return $index;
            }

            $index++;
        }

        return null;
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
