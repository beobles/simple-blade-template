<?php

namespace Core\View\Compiler;

use Core\View\Exception\SyntaxException;

/**
 * Lexer para tokenização de templates Blade
 */
class Lexer
{
    protected array $tokens = [];
    protected int $position = 0;
    protected string $content = '';
    protected int $line = 1;
    protected int $length = 0;

    // Token types
    public const TOKEN_TEXT = 'text';
    public const TOKEN_VARIABLE = 'variable';
    public const TOKEN_RAW_VARIABLE = 'raw_variable';
    public const TOKEN_COMMENT = 'comment';
    public const TOKEN_DIRECTIVE = 'directive';

    public function __construct(string $content)
    {
        $this->content = $content;
    }

    /**
     * Tokenizar conteúdo
     */
    public function tokenize(): array
    {
        $this->tokens = [];
        $this->position = 0;
        $this->line = 1;
        $this->length = strlen($this->content);

        while ($this->position < $this->length) {
            if ($this->match('{{--')) {
                $this->scanComment();
            } elseif ($this->match('{!!')) {
                $this->scanRawVariable();
            } elseif ($this->match('{{')) {
                $this->scanVariable();
            } elseif ($this->match('@@')) {
                $this->position += 2;
                $this->addToken(self::TOKEN_TEXT, '@');
            } elseif ($this->match('@')) {
                $this->scanDirective();
            } else {
                $this->scanText();
            }
        }

        return $this->tokens;
    }

    /**
     * Verificar correspondência
     */
    protected function match(string ...$patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (substr($this->content, $this->position, strlen($pattern)) === $pattern) {
                return true;
            }
        }

        return false;
    }

    /**
     * Escanear comentário
     */
    protected function scanComment(): void
    {
        $start = $this->position;
        $startLine = $this->line;
        $this->position += 4;

        $end = strpos($this->content, '--}}', $this->position);
        if ($end === false) {
            throw new SyntaxException(
                'Unclosed comment',
                '',
                $startLine,
                '{{--',
                'Close with --}}'
            );
        }

        $content = substr($this->content, $this->position, $end - $this->position);
        $this->updateLineCount($content);
        $this->position = $end + 4;

        $this->addToken(self::TOKEN_COMMENT, $content, $start, $startLine);
    }

    /**
     * Escanear variável crua
     */
    protected function scanRawVariable(): void
    {
        $start = $this->position;
        $startLine = $this->line;
        $this->position += 3;

        $end = strpos($this->content, '!!}', $this->position);
        if ($end === false) {
            throw new SyntaxException(
                'Unclosed raw variable',
                '',
                $startLine,
                '{!!',
                'Close with !!}'
            );
        }

        $content = substr($this->content, $this->position, $end - $this->position);
        $this->updateLineCount($content);
        $this->position = $end + 3;

        $this->addToken(self::TOKEN_RAW_VARIABLE, trim($content), $start, $startLine);
    }

    /**
     * Escanear variável
     */
    protected function scanVariable(): void
    {
        $start = $this->position;
        $startLine = $this->line;
        $this->position += 2;

        $end = strpos($this->content, '}}', $this->position);
        if ($end === false) {
            throw new SyntaxException(
                'Unclosed variable expression',
                '',
                $startLine,
                '{{ ...',
                'Close with }}'
            );
        }

        $content = substr($this->content, $this->position, $end - $this->position);
        $this->updateLineCount($content);
        $this->position = $end + 2;

        $this->addToken(self::TOKEN_VARIABLE, trim($content), $start, $startLine);
    }

    /**
     * Escanear diretiva
     */
    protected function scanDirective(): void
    {
        $start = $this->position;
        $startLine = $this->line;
        $this->position++;

        $match = [];
        if (!preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)/A', substr($this->content, $this->position), $match)) {
            $this->addToken(self::TOKEN_TEXT, '@', $start, $startLine);
            return;
        }

        $name = $match[1];
        $this->position += strlen($name);

        $args = '';
        if ($this->position < $this->length && $this->content[$this->position] === '(') {
            $argStart = $this->position;
            $args = $this->scanBalancedParentheses($name, $startLine);
            if ($args === '') {
                throw new SyntaxException(
                    "Unclosed arguments for directive @{$name}",
                    '',
                    $startLine,
                    substr($this->content, $argStart, min(50, $this->length - $argStart)),
                    "Close directive arguments with ')'"
                );
            }
        }

        $this->tokens[] = [
            'type' => self::TOKEN_DIRECTIVE,
            'name' => $name,
            'args' => $args,
            'position' => $start,
            'line' => $startLine,
        ];
    }

    /**
     * Escanear texto
     */
    protected function scanText(): void
    {
        $start = $this->position;
        $startLine = $this->line;

        while ($this->position < $this->length) {
            if ($this->match('{{--', '{!!', '{{') || $this->content[$this->position] === '@') {
                break;
            }
            if ($this->content[$this->position] === "\n") {
                $this->line++;
            }
            $this->position++;
        }

        if ($this->position > $start) {
            $this->addToken(
                self::TOKEN_TEXT,
                substr($this->content, $start, $this->position - $start),
                $start,
                $startLine
            );
        }
    }

    /**
     * Escanear argumentos balanceados de diretiva
     */
    protected function scanBalancedParentheses(string $directiveName, int $startLine): string
    {
        $start = $this->position;
        $depth = 0;
        $inString = false;
        $quote = '';
        $escaped = false;

        while ($this->position < $this->length) {
            $char = $this->content[$this->position];

            if ($char === "\n") {
                $this->line++;
            }

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === $quote) {
                    $inString = false;
                }
                $this->position++;
                continue;
            }

            if ($char === '"' || $char === "'") {
                $inString = true;
                $quote = $char;
                $this->position++;
                continue;
            }

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    $this->position++;
                    return substr($this->content, $start, $this->position - $start);
                }
            }

            $this->position++;
        }

        throw new SyntaxException(
            "Unbalanced directive arguments for @{$directiveName}",
            '',
            $startLine,
            substr($this->content, $start, min(80, $this->length - $start)),
            "Close directive arguments with ')' and balance parentheses/quotes"
        );
    }

    /**
     * Atualizar contagem de linhas
     */
    protected function updateLineCount(string $content): void
    {
        $this->line += substr_count($content, "\n");
    }

    /**
     * Adicionar token com mesclagem segura de texto
     */
    protected function addToken(string $type, string $value, int $position = 0, int $line = 0): void
    {
        if ($type === self::TOKEN_TEXT && $value === '') {
            return;
        }

        if (
            $type === self::TOKEN_TEXT &&
            !empty($this->tokens) &&
            $this->tokens[count($this->tokens) - 1]['type'] === self::TOKEN_TEXT
        ) {
            $this->tokens[count($this->tokens) - 1]['value'] .= $value;
            return;
        }

        $token = [
            'type' => $type,
            'position' => $position,
            'line' => $line,
        ];

        if ($type !== self::TOKEN_DIRECTIVE) {
            $token['value'] = $value;
        }

        $this->tokens[] = $token;
    }

    /**
     * Obter tokens
     */
    public function getTokens(): array
    {
        return $this->tokens;
    }
}
