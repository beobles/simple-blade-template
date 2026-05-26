<?php

namespace Core\View\Compiler;

use Core\View\Exception\SyntaxException;

/**
 * Parser para processar tokens e gerar código PHP
 */
class Parser
{
    protected array $tokens = [];
    protected int $position = 0;
    protected array $structureStack = [];
    protected array $customDirectives = [];
    protected string $templateFile = '';

    public function __construct(array $tokens = [], string $templateFile = '')
    {
        $this->tokens = $tokens;
        $this->templateFile = $templateFile;
    }

    /**
     * Parsear tokens
     */
    public function parse(): string
    {
        $compiled = "<?php\n";

        foreach ($this->tokens as $token) {
            switch ($token['type']) {
                case Lexer::TOKEN_TEXT:
                    $compiled .= "echo " . var_export($token['value'], true) . ";\n";
                    break;

                case Lexer::TOKEN_VARIABLE:
                    $compiled .= $this->parseVariable($token);
                    break;

                case Lexer::TOKEN_RAW_VARIABLE:
                    $compiled .= "echo " . $token['value'] . ";\n";
                    break;

                case Lexer::TOKEN_COMMENT:
                    // Comentários são ignorados
                    break;

                case Lexer::TOKEN_DIRECTIVE:
                    $compiled .= $this->parseDirective($token);
                    break;
            }
        }

        $compiled .= "\n?>";
        return $compiled;
    }

    /**
     * Parsear variável
     */
    protected function parseVariable(array $token): string
    {
        return "echo htmlspecialchars(" . $token['value'] . ", ENT_QUOTES, 'UTF-8');\n";
    }

    /**
     * Parsear diretiva
     */
    protected function parseDirective(array $token): string
    {
        $name = $token['name'];
        $args = $token['args'];
        $line = $token['line'] ?? 0;

        if (isset($this->customDirectives[$name])) {
            return call_user_func($this->customDirectives[$name], $args, $this);
        }

        switch ($name) {
            case 'if':
                $this->structureStack[] = 'if';
                return "<?php if (" . $this->extractCondition($args) . "): ?>\n";

            case 'elseif':
                return "<?php elseif (" . $this->extractCondition($args) . "): ?>\n";

            case 'else':
                return "<?php else: ?>\n";

            case 'endif':
                if (empty($this->structureStack) || end($this->structureStack) !== 'if') {
                    throw new SyntaxException('Unmatched @endif', $this->templateFile, $line);
                }
                array_pop($this->structureStack);
                return "<?php endif; ?>\n";

            case 'foreach':
                $this->structureStack[] = 'foreach';
                return $this->parseForEach($args);

            case 'endforeach':
                if (empty($this->structureStack) || end($this->structureStack) !== 'foreach') {
                    throw new SyntaxException('Unmatched @endforeach', $this->templateFile, $line);
                }
                array_pop($this->structureStack);
                return "<?php endforeach; ?>\n";

            case 'for':
                $this->structureStack[] = 'for';
                return "<?php for (" . $this->extractCondition($args) . "): ?>\n";

            case 'endfor':
                if (empty($this->structureStack) || end($this->structureStack) !== 'for') {
                    throw new SyntaxException('Unmatched @endfor', $this->templateFile, $line);
                }
                array_pop($this->structureStack);
                return "<?php endfor; ?>\n";

            case 'php':
                $this->structureStack[] = 'php';
                return "<?php\n";

            case 'endphp':
                if (empty($this->structureStack) || end($this->structureStack) !== 'php') {
                    throw new SyntaxException('Unmatched @endphp', $this->templateFile, $line);
                }
                array_pop($this->structureStack);
                return "?>\n";

            default:
                throw new SyntaxException(
                    "Unknown directive: @{$name}",
                    $this->templateFile,
                    $line,
                    "@{$name}{$args}",
                    "Check the directive name and syntax"
                );
        }
    }

    /**
     * Parsear foreach
     */
    protected function parseForEach(string $args): string
    {
        $args = trim($args, '()');
        return "<?php foreach (" . $args . "): ?>\n";
    }

    /**
     * Extrair condição
     */
    protected function extractCondition(string $args): string
    {
        return trim($args, '()');
    }

    /**
     * Registrar diretiva customizada
     */
    public function addDirective(string $name, callable $callback): self
    {
        $this->customDirectives[$name] = $callback;
        return $this;
    }
}
