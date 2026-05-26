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
        $length = strlen($this->content);

        while ($this->position < $length) {
            if ($this->match('{{--')) {
                $this->scanComment();
            } elseif ($this->match('{!!')) {
                $this->scanRawVariable();
            } elseif ($this->match('{{')) {
                $this->scanVariable();
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
        $remaining = substr($this->content, $this->position);
        
        foreach ($patterns as $pattern) {
            if (strpos($remaining, $pattern) === 0) {
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

        $this->tokens[] = [
            'type' => self::TOKEN_COMMENT,
            'value' => $content,
            'position' => $start,
            'line' => $startLine,
        ];
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
        $this->position = $end + 3;

        $this->tokens[] = [
            'type' => self::TOKEN_RAW_VARIABLE,
            'value' => trim($content),
            'position' => $start,
            'line' => $startLine,
        ];
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
        $this->position = $end + 2;

        $this->tokens[] = [
            'type' => self::TOKEN_VARIABLE,
            'value' => trim($content),
            'position' => $start,
            'line' => $startLine,
        ];
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
            $this->position--;
            $this->scanText();
            return;
        }

        $name = $match[1];
        $this->position += strlen($name);

        $args = '';
        if ($this->position < strlen($this->content) && $this->content[$this->position] === '(') {
            $depth = 0;
            $argStart = $this->position;
            
            while ($this->position < strlen($this->content)) {
                $char = $this->content[$this->position];
                
                if ($char === '(') $depth++;
                elseif ($char === ')') $depth--;
                
                $this->position++;
                
                if ($depth === 0) break;
            }

            $args = substr($this->content, $argStart, $this->position - $argStart);
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
        
        while ($this->position < strlen($this->content)) {
            if ($this->match('{{--', '{!!', '{{', '@')) {
                break;
            }
            if ($this->content[$this->position] === "\n") {
                $this->line++;
            }
            $this->position++;
        }

        if ($this->position > $start) {
            $text = substr($this->content, $start, $this->position - $start);
            
            if (!empty($this->tokens) && $this->tokens[count($this->tokens) - 1]['type'] === self::TOKEN_TEXT) {
                $this->tokens[count($this->tokens) - 1]['value'] .= $text;
            } else {
                $this->tokens[] = [
                    'type' => self::TOKEN_TEXT,
                    'value' => $text,
                    'position' => $start,
                    'line' => $startLine,
                ];
            }
        }
    }

    /**
     * Atualizar contagem de linhas
     */
    protected function updateLineCount(string $content): void
    {
        $this->line += substr_count($content, "\n");
    }

    /**
     * Obter tokens
     */
    public function getTokens(): array
    {
        return $this->tokens;
    }
}
