<?php

namespace Core\View\Compiler\Syntax;

use Core\View\Exception\SyntaxException;

/**
 * Transforma sintaxe declarativa estilo React/Next para tags intermediárias `view:`
 * e expressões internas do compilador.
 */
class ReactLikeSyntaxTransformer
{
    protected const PREFIX = 'view:';

    /**
     * @var array<string, string>
     */
    protected const COMPONENT_TO_DIRECTIVE = [
        'If' => 'if',
        'ElseIf' => 'elseif',
        'Else' => 'else',
        'Unless' => 'unless',
        'Isset' => 'isset',
        'Empty' => 'empty',
        'ForEach' => 'foreach',
        'ForElse' => 'forelse',
        'For' => 'for',
        'While' => 'while',
        'Switch' => 'switch',
        'Case' => 'case',
        'Default' => 'default',
        'Break' => 'break',
        'Continue' => 'continue',
        'Include' => 'include',
        'IncludeIf' => 'includeIf',
        'IncludeWhen' => 'includeWhen',
        'IncludeUnless' => 'includeUnless',
        'Json' => 'json',
        'Csrf' => 'csrf',
        'Auth' => 'auth',
        'Guest' => 'guest',
        'Can' => 'can',
        'Cannot' => 'cannot',
        'Php' => 'php',
    ];

    /**
     * @var array<int, string>
     */
    protected const SELF_ONLY_DIRECTIVES = [
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

    /**
     * @var array<string, string>
     */
    protected const SPECIAL_OUTPUT_COMPONENTS = [
        'Echo' => 'escaped',
        'Raw' => 'raw',
    ];

    public function transform(string $content, string $templateFile = ''): string
    {
        $content = $this->transformComponentTags($content, $templateFile);
        return $this->transformTextExpressions($content);
    }
    protected function transformComponentTags(string $content, string $templateFile): string
    {
        $pattern = '/<\s*(\/?)\s*([A-Z][A-Za-z0-9]*)\b([^>]*)>/';
        $searchOffset = 0;

        return (string) preg_replace_callback(
            $pattern,
            function (array $match) use ($content, $templateFile, &$searchOffset): string {
                $fullMatch = $match[0] ?? '';
                $isClosingTag = ($match[1] ?? '') === '/';
                $component = $match[2] ?? '';
                $rawAttributes = $match[3] ?? '';

                $offset = strpos($content, $fullMatch, $searchOffset);
                if ($offset !== false) {
                    $searchOffset = $offset + strlen($fullMatch);
                }
                $line = $offset === false ? 1 : (substr_count(substr($content, 0, $offset), "\n") + 1);

                if (isset(self::SPECIAL_OUTPUT_COMPONENTS[$component])) {
                    if ($isClosingTag) {
                        throw new SyntaxException(
                            "Closing tag </{$component}> is not supported",
                            $templateFile,
                            $line,
                            $fullMatch,
                            "Use <{$component} expression={...} />"
                        );
                    }

                    $attributes = $this->parseAttributes($rawAttributes);
                    $expression = $this->pickFirstAttribute($attributes, ['expression', 'value', 'of']);
                    if ($expression === null || trim($expression) === '') {
                        throw new SyntaxException(
                            "Missing expression for <{$component}>",
                            $templateFile,
                            $line,
                            $fullMatch,
                            "Use <{$component} expression={\$value} />"
                        );
                    }

                    if (self::SPECIAL_OUTPUT_COMPONENTS[$component] === 'raw') {
                        return '{!! ' . trim($expression) . ' !!}';
                    }
                    return '{{ ' . trim($expression) . ' }}';
                }

                if (!isset(self::COMPONENT_TO_DIRECTIVE[$component])) {
                    return $fullMatch;
                }

                $directive = self::COMPONENT_TO_DIRECTIVE[$component];
                if ($isClosingTag) {
                    return '</' . self::PREFIX . $directive . '>';
                }

                $attributes = $this->parseAttributes($rawAttributes);
                $isSelfClosing = str_ends_with(trim($rawAttributes), '/');
                $expression = $this->extractDirectiveExpression($directive, $attributes);
                $suffix = $isSelfClosing ? ' />' : '>';

                if (in_array($directive, self::SELF_ONLY_DIRECTIVES, true) && !$isSelfClosing) {
                    throw new SyntaxException(
                        "Component <{$component}> must be self-closing",
                        $templateFile,
                        $line,
                        $fullMatch,
                        "Use <{$component} ... />"
                    );
                }

                if ($expression !== null && trim($expression) !== '') {
                    return '<' . self::PREFIX . $directive . ' expression=' . $this->quoteAttribute(trim($expression)) . $suffix;
                }

                return '<' . self::PREFIX . $directive . $suffix;
            },
            $content
        );
    }

    protected function transformTextExpressions(string $content): string
    {
        $segments = preg_split('/(<[^>]+>)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($segments)) {
            return $content;
        }

        foreach ($segments as $index => $segment) {
            if ($segment === '' || str_starts_with($segment, '<')) {
                continue;
            }

            $segments[$index] = (string) preg_replace_callback(
                '/\{([^{}]+)\}/',
                function (array $match): string {
                    $expression = trim($match[1] ?? '');
                    if ($expression === '' || !$this->isLikelyPhpExpression($expression)) {
                        return $match[0];
                    }
                    return '{{ ' . $expression . ' }}';
                },
                $segment
            );
        }

        return implode('', $segments);
    }

    /**
     * @return array<string, string>
     */
    protected function parseAttributes(string $rawAttributes): array
    {
        $attributes = [];
        preg_match_all(
            '/([a-zA-Z_][a-zA-Z0-9_-]*)\s*=\s*(\{([^}]*)\}|"([^"]*)"|\'([^\']*)\')/',
            $rawAttributes,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $key = $match[1] ?? '';
            $value = $match[3] ?? ($match[4] ?? ($match[5] ?? ''));
            if ($key !== '') {
                $attributes[$key] = trim((string) $value);
            }
        }

        return $attributes;
    }

    protected function extractDirectiveExpression(string $directive, array $attributes): ?string
    {
        if (in_array($directive, ['if', 'elseif', 'unless', 'isset', 'empty', 'while', 'break', 'continue'], true)) {
            return $this->pickFirstAttribute($attributes, ['condition', 'when', 'test', 'expression', 'args']);
        }

        if (in_array($directive, ['foreach', 'forelse', 'for'], true)) {
            return $this->pickFirstAttribute($attributes, ['each', 'of', 'expression', 'args']);
        }

        if (in_array($directive, ['switch', 'case'], true)) {
            return $this->pickFirstAttribute($attributes, ['on', 'value', 'expression', 'args']);
        }

        if (in_array($directive, ['include', 'includeIf'], true)) {
            $direct = $this->pickFirstAttribute($attributes, ['expression', 'args']);
            if ($direct !== null) {
                return $direct;
            }
            $template = $this->pickFirstAttribute($attributes, ['template', 'view']);
            if ($template === null) {
                return null;
            }
            $data = $this->pickFirstAttribute($attributes, ['data', 'with']);
            return $data !== null ? "{$template}, {$data}" : $template;
        }

        if (in_array($directive, ['includeWhen', 'includeUnless'], true)) {
            $direct = $this->pickFirstAttribute($attributes, ['expression', 'args']);
            if ($direct !== null) {
                return $direct;
            }
            $condition = $this->pickFirstAttribute($attributes, ['when', 'condition']);
            $template = $this->pickFirstAttribute($attributes, ['template', 'view']);
            if ($condition === null || $template === null) {
                return null;
            }
            $data = $this->pickFirstAttribute($attributes, ['data', 'with']);
            return $data !== null ? "{$condition}, {$template}, {$data}" : "{$condition}, {$template}";
        }

        if ($directive === 'json') {
            $direct = $this->pickFirstAttribute($attributes, ['expression', 'args']);
            if ($direct !== null) {
                return $direct;
            }
            $value = $this->pickFirstAttribute($attributes, ['value', 'data']);
            if ($value === null) {
                return null;
            }
            $flags = $this->pickFirstAttribute($attributes, ['flags']);
            $depth = $this->pickFirstAttribute($attributes, ['depth']);
            if ($flags === null && $depth === null) {
                return $value;
            }
            if ($depth === null) {
                return "{$value}, {$flags}";
            }
            return "{$value}, {$flags}, {$depth}";
        }

        if (in_array($directive, ['can', 'cannot'], true)) {
            $direct = $this->pickFirstAttribute($attributes, ['expression', 'args']);
            if ($direct !== null) {
                return $direct;
            }
            $ability = $this->pickFirstAttribute($attributes, ['ability']);
            if ($ability === null) {
                return null;
            }
            $subject = $this->pickFirstAttribute($attributes, ['subject', 'on']);
            return $subject !== null ? "{$ability}, {$subject}" : $ability;
        }

        if ($directive === 'php') {
            return $this->pickFirstAttribute($attributes, ['expression', 'statement', 'args']);
        }

        return $this->pickFirstAttribute($attributes, ['expression', 'args']);
    }

    protected function pickFirstAttribute(array $attributes, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $attributes) && trim($attributes[$key]) !== '') {
                return trim($attributes[$key]);
            }
        }
        return null;
    }

    protected function quoteAttribute(string $value): string
    {
        return "'" . str_replace(['\\', '\''], ['\\\\', '\\\''], $value) . "'";
    }

    protected function isLikelyPhpExpression(string $expression): bool
    {
        return preg_match('/\$[a-zA-Z_\x80-\xff]/', $expression) === 1
            || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*\s*\(/', $expression) === 1
            || str_contains($expression, '->')
            || str_contains($expression, '::')
            || str_contains($expression, '[');
    }
}
