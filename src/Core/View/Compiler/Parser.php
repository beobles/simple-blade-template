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
    protected array $internalCallbacks = [];
    protected string $templateFile = '';
    protected int $uniqueCounter = 0;

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
        $this->position = 0;
        $this->structureStack = [];

        foreach ($this->tokens as $token) {
            $this->position++;

            switch ($token['type']) {
                case Lexer::TOKEN_TEXT:
                    if ($this->shouldSkipTextToken($token['value'] ?? '')) {
                        break;
                    }
                    $compiled .= "echo " . var_export($token['value'], true) . ";\n";
                    break;

                case Lexer::TOKEN_VARIABLE:
                    $compiled .= $this->parseVariable($token);
                    break;

                case Lexer::TOKEN_RAW_VARIABLE:
                    $compiled .= $this->parseRawVariable($token);
                    break;

                case Lexer::TOKEN_COMMENT:
                    break;

                case Lexer::TOKEN_DIRECTIVE:
                    $compiled .= $this->parseDirective($token);
                    break;
            }
        }

        if (!empty($this->structureStack)) {
            $open = end($this->structureStack);
            throw new SyntaxException(
                "Unclosed directive block: @{$open['directive']}",
                $this->templateFile,
                $open['line'],
                "@{$open['directive']}",
                "Close the block with @{$open['closing']}"
            );
        }

        return $compiled . "\n?>";
    }

    /**
     * Parsear variável escapada
     */
    protected function parseVariable(array $token): string
    {
        $line = $token['line'] ?? 0;
        $expression = $this->requireExpression($token['value'] ?? '', $line, 'variable');
        return "echo htmlspecialchars((string)($expression), " . $this->getHtmlEscapeFlagsExpression() . ", 'UTF-8');\n";
    }

    /**
     * Parsear variável crua
     */
    protected function parseRawVariable(array $token): string
    {
        $line = $token['line'] ?? 0;
        $expression = $this->requireExpression($token['value'] ?? '', $line, 'raw variable');
        return "echo ($expression);\n";
    }

    /**
     * Parsear diretiva
     */
    protected function parseDirective(array $token): string
    {
        $name = $token['name'];
        $args = $token['args'] ?? '';
        $line = $token['line'] ?? 0;

        if (isset($this->customDirectives[$name])) {
            return (string) call_user_func($this->customDirectives[$name], $args, $this);
        }

        switch ($name) {
            case 'if':
                $this->pushStructure('if', 'endif', $line);
                return "if (" . $this->requireExpression($this->extractCondition($args), $line, '@if') . ") {\n";

            case 'elseif':
                $this->assertTopStructure(['if'], 'elseif', $line);
                return "} elseif (" . $this->requireExpression($this->extractCondition($args), $line, '@elseif') . ") {\n";

            case 'else':
                $this->assertTopStructure(['if', 'unless', 'isset', 'empty', 'auth', 'guest', 'can', 'cannot'], 'else', $line);
                return "} else {\n";

            case 'endif':
                $this->popStructure(['if'], 'endif', $line);
                return "}\n";

            case 'unless':
                $this->pushStructure('unless', 'endunless', $line);
                return "if (!(" . $this->requireExpression($this->extractCondition($args), $line, '@unless') . ")) {\n";

            case 'endunless':
                $this->popStructure(['unless'], 'endunless', $line);
                return "}\n";

            case 'isset':
                $this->pushStructure('isset', 'endisset', $line);
                return "if (isset(" . $this->requireExpression($this->extractCondition($args), $line, '@isset') . ")) {\n";

            case 'endisset':
                $this->popStructure(['isset'], 'endisset', $line);
                return "}\n";

            case 'empty':
                if ($this->isTopStructure('forelse')) {
                    $state = &$this->getTopStructure();
                    if (!empty($state['meta']['empty_used'])) {
                        throw new SyntaxException('Duplicate @empty for @forelse block', $this->templateFile, $line);
                    }
                    $state['meta']['empty_used'] = true;
                    $marker = $state['meta']['marker'];
                    return "}\nif ({$marker}) {\n";
                }

                $this->pushStructure('empty', 'endempty', $line);
                return "if (empty(" . $this->requireExpression($this->extractCondition($args), $line, '@empty') . ")) {\n";

            case 'endempty':
                $this->popStructure(['empty'], 'endempty', $line);
                return "}\n";

            case 'foreach':
                $this->pushStructure('foreach', 'endforeach', $line);
                return "foreach (" . $this->requireExpression($this->extractCondition($args), $line, '@foreach') . ") {\n";

            case 'endforeach':
                $this->popStructure(['foreach'], 'endforeach', $line);
                return "}\n";

            case 'forelse':
                $marker = '$bladeForelseEmpty' . (++$this->uniqueCounter);
                $this->pushStructure('forelse', 'endforelse', $line, [
                    'marker' => $marker,
                    'empty_used' => false,
                ]);
                return "{$marker} = true; foreach (" . $this->requireExpression($this->extractCondition($args), $line, '@forelse') . ") { {$marker} = false;\n";

            case 'endforelse':
                $state = $this->popStructure(['forelse'], 'endforelse', $line);
                $marker = $state['meta']['marker'];
                if (!empty($state['meta']['empty_used'])) {
                    return "}\nunset({$marker});\n";
                }
                return "}\nunset({$marker});\n";

            case 'for':
                $this->pushStructure('for', 'endfor', $line);
                return "for (" . $this->requireExpression($this->extractCondition($args), $line, '@for') . ") {\n";

            case 'endfor':
                $this->popStructure(['for'], 'endfor', $line);
                return "}\n";

            case 'while':
                $this->pushStructure('while', 'endwhile', $line);
                return "while (" . $this->requireExpression($this->extractCondition($args), $line, '@while') . ") {\n";

            case 'endwhile':
                $this->popStructure(['while'], 'endwhile', $line);
                return "}\n";

            case 'switch':
                $this->pushStructure('switch', 'endswitch', $line, ['has_case' => false]);
                return "switch (" . $this->requireExpression($this->extractCondition($args), $line, '@switch') . ") {\n";

            case 'case':
                $this->assertTopStructure(['switch'], 'case', $line);
                $this->markCurrentSwitchHasCase();
                return "case " . $this->requireExpression($this->extractCondition($args), $line, '@case') . ":\n";

            case 'default':
                $this->assertTopStructure(['switch'], 'default', $line);
                $this->markCurrentSwitchHasCase();
                return "default:\n";

            case 'endswitch':
                $this->popStructure(['switch'], 'endswitch', $line);
                return "}\n";

            case 'break':
                $breakExpr = trim($this->extractCondition($args));
                return $breakExpr !== ''
                    ? "if ({$breakExpr}) { break; }\n"
                    : "break;\n";

            case 'continue':
                $continueExpr = trim($this->extractCondition($args));
                return $continueExpr !== ''
                    ? "if ({$continueExpr}) { continue; }\n"
                    : "continue;\n";

            case 'php':
                $phpContent = trim($this->extractCondition($args));
                if ($phpContent !== '') {
                    return "{$phpContent};\n";
                }
                throw new SyntaxException(
                    'Block-style @php ... @endphp is not supported',
                    $this->templateFile,
                    $line,
                    '@php',
                    'Use @php(expression) for one-line PHP statements'
                );

            case 'endphp':
                throw new SyntaxException(
                    'Unmatched @endphp',
                    $this->templateFile,
                    $line,
                    '@endphp',
                    'Use @php(expression) instead of block mode'
                );

            case 'include':
                return $this->compileIncludeDirective($args, $line, true);

            case 'includeIf':
                return $this->compileIncludeDirective($args, $line, false);

            case 'includeWhen':
                return $this->compileIncludeWhenDirective($args, $line, true);

            case 'includeUnless':
                return $this->compileIncludeWhenDirective($args, $line, false);

            case 'json':
                return $this->compileJsonDirective($args, $line);

            case 'csrf':
                return "echo " . $this->callInternal('csrf') . "();\n";

            case 'auth':
                $this->pushStructure('auth', 'endauth', $line);
                return "if (" . $this->callInternal('auth') . "()) {\n";

            case 'endauth':
                $this->popStructure(['auth'], 'endauth', $line);
                return "}\n";

            case 'guest':
                $this->pushStructure('guest', 'endguest', $line);
                return "if (!" . $this->callInternal('auth') . "()) {\n";

            case 'endguest':
                $this->popStructure(['guest'], 'endguest', $line);
                return "}\n";

            case 'can':
                $this->pushStructure('can', 'endcan', $line);
                return "if (" . $this->compileCanExpression($args, $line, true) . ") {\n";

            case 'endcan':
                $this->popStructure(['can'], 'endcan', $line);
                return "}\n";

            case 'cannot':
                $this->pushStructure('cannot', 'endcannot', $line);
                return "if (" . $this->compileCanExpression($args, $line, false) . ") {\n";

            case 'endcannot':
                $this->popStructure(['cannot'], 'endcannot', $line);
                return "}\n";

            default:
                throw new SyntaxException(
                    "Unknown directive: @{$name}",
                    $this->templateFile,
                    $line,
                    "@{$name}{$args}",
                    'Check the directive name and syntax'
                );
        }
    }

    protected function compileCanExpression(string $args, int $line, bool $allow): string
    {
        $parts = $this->splitArguments($args);
        $ability = $this->requireExpression($parts[0] ?? '', $line, '@can');
        $subject = isset($parts[1]) ? trim($parts[1]) : 'null';
        $base = $this->callInternal('can') . "({$ability}, {$subject})";
        return $allow ? $base : "!(" . $base . ")";
    }

    protected function compileJsonDirective(string $args, int $line): string
    {
        $parts = $this->splitArguments($args);
        $value = $this->requireExpression($parts[0] ?? '', $line, '@json');
        $flags = trim($parts[1] ?? 'JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES');
        $depth = trim($parts[2] ?? '512');
        return "echo json_encode({$value}, {$flags}, {$depth});\n";
    }

    protected function compileIncludeDirective(string $args, int $line, bool $required): string
    {
        $parts = $this->splitArguments($args);
        $view = $this->requireExpression($parts[0] ?? '', $line, '@include');
        $data = trim($parts[1] ?? '[]');
        $requiredValue = $required ? 'true' : 'false';
        return "echo " . $this->callInternal('include') . "({$view}, get_defined_vars(), {$data}, {$requiredValue});\n";
    }

    protected function compileIncludeWhenDirective(string $args, int $line, bool $when): string
    {
        $parts = $this->splitArguments($args);
        $condition = $this->requireExpression($parts[0] ?? '', $line, '@includeWhen');
        $view = $this->requireExpression($parts[1] ?? '', $line, '@includeWhen');
        $data = trim($parts[2] ?? '[]');
        $requiredValue = 'true';
        $conditionExpr = $when ? $condition : '!(' . $condition . ')';
        return "if ({$conditionExpr}) { echo " . $this->callInternal('include') . "({$view}, get_defined_vars(), {$data}, {$requiredValue}); }\n";
    }

    protected function callInternal(string $name): string
    {
        return "\$__blade['{$name}']";
    }

    protected function extractCondition(string $args): string
    {
        $args = trim($args);
        if ($args === '') {
            return '';
        }

        if ($args[0] === '(' && substr($args, -1) === ')') {
            return trim(substr($args, 1, -1));
        }

        return $args;
    }

    protected function requireExpression(string $expression, int $line, string $context): string
    {
        $expression = trim($expression);
        if ($expression === '') {
            throw new SyntaxException(
                "Missing expression in {$context}",
                $this->templateFile,
                $line
            );
        }

        if (str_contains($expression, '<?') || str_contains($expression, '?>')) {
            throw new SyntaxException(
                "Invalid PHP tags inside {$context}",
                $this->templateFile,
                $line,
                $expression,
                'Remove nested PHP tags from directive expression'
            );
        }

        return $expression;
    }

    protected function splitArguments(string $args): array
    {
        $condition = trim($this->extractCondition($args));
        if ($condition === '') {
            return [];
        }

        $arguments = [];
        $buffer = '';
        $depth = 0;
        $quote = null;
        $escaped = false;
        $length = strlen($condition);

        for ($index = 0; $index < $length; $index++) {
            $char = $condition[$index];

            if ($quote !== null) {
                $buffer .= $char;
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                $buffer .= $char;
                continue;
            }

            if ($char === '(' || $char === '[' || $char === '{') {
                $depth++;
                $buffer .= $char;
                continue;
            }

            if ($char === ')' || $char === ']' || $char === '}') {
                $depth = max(0, $depth - 1);
                $buffer .= $char;
                continue;
            }

            if ($char === ',' && $depth === 0) {
                $arguments[] = trim($buffer);
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        if (trim($buffer) !== '') {
            $arguments[] = trim($buffer);
        }

        return $arguments;
    }

    protected function getHtmlEscapeFlagsExpression(): string
    {
        return (string) (ENT_QUOTES | ENT_SUBSTITUTE | (defined('ENT_HTML5') ? ENT_HTML5 : 0));
    }

    protected function pushStructure(string $type, string $closing, int $line, array $meta = []): void
    {
        $this->structureStack[] = [
            'type' => $type,
            'directive' => $type,
            'closing' => $closing,
            'line' => $line,
            'meta' => $meta,
        ];
    }

    protected function popStructure(array $allowedTypes, string $closingDirective, int $line): array
    {
        if (empty($this->structureStack)) {
            throw new SyntaxException("Unmatched @{$closingDirective}", $this->templateFile, $line);
        }

        $top = end($this->structureStack);
        if (!in_array($top['type'], $allowedTypes, true)) {
            throw new SyntaxException(
                "Unexpected @{$closingDirective} for @{$top['directive']} block",
                $this->templateFile,
                $line
            );
        }

        return array_pop($this->structureStack);
    }

    protected function assertTopStructure(array $allowedTypes, string $directive, int $line): void
    {
        if (empty($this->structureStack)) {
            throw new SyntaxException("Unmatched @{$directive}", $this->templateFile, $line);
        }

        $top = end($this->structureStack);
        if (!in_array($top['type'], $allowedTypes, true)) {
            throw new SyntaxException(
                "Directive @{$directive} is not valid inside @{$top['directive']} block",
                $this->templateFile,
                $line
            );
        }
    }

    protected function isTopStructure(string $type): bool
    {
        if (empty($this->structureStack)) {
            return false;
        }
        return end($this->structureStack)['type'] === $type;
    }

    protected function &getTopStructure(): array
    {
        $index = count($this->structureStack) - 1;
        return $this->structureStack[$index];
    }

    protected function shouldSkipTextToken(string $text): bool
    {
        if (!empty($this->structureStack)) {
            $top = end($this->structureStack);
            if ($top['type'] === 'switch' && empty($top['meta']['has_case']) && trim($text) === '') {
                return true;
            }
        }

        return false;
    }

    protected function markCurrentSwitchHasCase(): void
    {
        if (empty($this->structureStack)) {
            return;
        }

        $index = count($this->structureStack) - 1;
        if ($this->structureStack[$index]['type'] === 'switch') {
            $this->structureStack[$index]['meta']['has_case'] = true;
        }
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
     * Registrar callbacks internos da engine
     */
    public function setInternalCallback(string $name, callable $callback): self
    {
        $this->internalCallbacks[$name] = $callback;
        return $this;
    }
}
