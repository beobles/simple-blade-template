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

    /**
     * @var array<string, array<int, string>>
     */
    protected const COMPONENT_ALLOWED_ATTRIBUTES = [
        'If' => ['condition', 'when', 'test', 'expression', 'args'],
        'ElseIf' => ['condition', 'when', 'test', 'expression', 'args'],
        'Else' => [],
        'Unless' => ['condition', 'when', 'test', 'expression', 'args'],
        'Isset' => ['condition', 'when', 'test', 'expression', 'args'],
        'Empty' => ['condition', 'when', 'test', 'expression', 'args'],
        'ForEach' => ['each', 'of', 'expression', 'args'],
        'ForElse' => ['each', 'of', 'expression', 'args'],
        'For' => ['each', 'of', 'expression', 'args'],
        'While' => ['condition', 'when', 'test', 'expression', 'args'],
        'Switch' => ['on', 'value', 'expression', 'args'],
        'Case' => ['on', 'value', 'expression', 'args'],
        'Default' => [],
        'Break' => ['condition', 'when', 'test', 'expression', 'args'],
        'Continue' => ['condition', 'when', 'test', 'expression', 'args'],
        'Include' => ['expression', 'args', 'template', 'view', 'data', 'with'],
        'IncludeIf' => ['expression', 'args', 'template', 'view', 'data', 'with'],
        'IncludeWhen' => ['expression', 'args', 'when', 'condition', 'template', 'view', 'data', 'with'],
        'IncludeUnless' => ['expression', 'args', 'when', 'condition', 'template', 'view', 'data', 'with'],
        'Json' => ['expression', 'args', 'value', 'data', 'flags', 'depth'],
        'Csrf' => [],
        'Auth' => [],
        'Guest' => [],
        'Can' => ['expression', 'args', 'ability', 'subject', 'on'],
        'Cannot' => ['expression', 'args', 'ability', 'subject', 'on'],
        'Php' => ['expression', 'statement', 'args'],
        'Echo' => ['expression', 'value', 'of'],
        'Raw' => ['expression', 'value', 'of'],
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

                    $attributesMeta = $this->parseAttributes($rawAttributes, $component, $templateFile, $line);
                    $this->assertAllowedAndExplicitExpressionAttributes(
                        $component,
                        $attributesMeta,
                        $templateFile,
                        $line
                    );
                    $attributes = $this->flattenAttributeValues($attributesMeta);
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

                $attributesMeta = $this->parseAttributes($rawAttributes, $component, $templateFile, $line);
                $this->assertAllowedAndExplicitExpressionAttributes(
                    $component,
                    $attributesMeta,
                    $templateFile,
                    $line
                );
                $attributes = $this->flattenAttributeValues($attributesMeta);
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

            $segments[$index] = $this->transformTextSegmentExpressions($segment);
        }

        return implode('', $segments);
    }

    /**
     * @return array<string, array{value: string, syntax: string}>
     */
    protected function parseAttributes(string $rawAttributes, string $component, string $templateFile, int $line): array
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
                $snippet = trim(substr($rawAttributes, $index, 40));
                throw new SyntaxException(
                    "Invalid attribute syntax in <{$component}>",
                    $templateFile,
                    $line,
                    $snippet,
                    'Use explicit attributes in the form key={...}'
                );
            }

            $key = $nameMatch[1];
            $index += strlen($key);

            while ($index < $length && ctype_space($rawAttributes[$index])) {
                $index++;
            }

            if ($index >= $length || $rawAttributes[$index] !== '=') {
                throw new SyntaxException(
                    "Attribute '{$key}' in <{$component}> must define a value",
                    $templateFile,
                    $line,
                    $key,
                    "Use {$key}={...}"
                );
            }

            $index++;
            while ($index < $length && ctype_space($rawAttributes[$index])) {
                $index++;
            }

            if ($index >= $length) {
                throw new SyntaxException(
                    "Missing value for attribute '{$key}' in <{$component}>",
                    $templateFile,
                    $line,
                    $key . '=',
                    "Use {$key}={...}"
                );
            }

            $first = $rawAttributes[$index];
            $value = '';
            $syntax = 'unquoted';

            if ($first === '{') {
                $syntax = 'braced';
                $valueStart = $index + 1;
                $depth = 1;
                $index++;
                $quote = null;
                $escaped = false;

                while ($index < $length) {
                    $char = $rawAttributes[$index];

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
                        $depth++;
                    } elseif ($char === '}') {
                        $depth--;
                        if ($depth === 0) {
                            break;
                        }
                    }

                    $index++;
                }

                if ($index >= $length || $depth !== 0) {
                    throw new SyntaxException(
                        "Unclosed expression attribute '{$key}' in <{$component}>",
                        $templateFile,
                        $line,
                        "{$key}={...",
                        "Close expression attribute with '}'"
                    );
                }

                $value = substr($rawAttributes, $valueStart, $index - $valueStart);
                $index++;
            } elseif ($first === '"' || $first === "'") {
                $quote = $first;
                $syntax = 'quoted';
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

                if ($index >= $length) {
                    throw new SyntaxException(
                        "Unclosed quoted attribute '{$key}' in <{$component}>",
                        $templateFile,
                        $line,
                        "{$key}={$quote}...",
                        "Close attribute value with {$quote}"
                    );
                }

                $value = substr($rawAttributes, $valueStart, $index - $valueStart);
                $index++;
            } else {
                $valueStart = $index;
                while ($index < $length && !ctype_space($rawAttributes[$index]) && $rawAttributes[$index] !== '/' && $rawAttributes[$index] !== '>') {
                    $index++;
                }
                $value = substr($rawAttributes, $valueStart, $index - $valueStart);
            }

            $attributes[$key] = [
                'value' => trim((string) $value),
                'syntax' => $syntax,
            ];
        }

        return $attributes;
    }

    protected function transformTextSegmentExpressions(string $segment): string
    {
        $length = strlen($segment);
        $index = 0;
        $result = '';

        while ($index < $length) {
            if ($segment[$index] !== '{') {
                $result .= $segment[$index];
                $index++;
                continue;
            }

            $start = $index;
            $index++;
            $depth = 1;
            $quote = null;
            $escaped = false;

            while ($index < $length) {
                $char = $segment[$index];

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
                    $depth++;
                } elseif ($char === '}') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }

                $index++;
            }

            if ($index >= $length || $depth !== 0) {
                $result .= substr($segment, $start);
                break;
            }

            $expression = trim(substr($segment, $start + 1, $index - $start - 1));
            if ($expression !== '' && $this->isLikelyPhpExpression($expression)) {
                $result .= '{{ ' . $expression . ' }}';
            } else {
                $result .= substr($segment, $start, $index - $start + 1);
            }

            $index++;
        }

        return $result;
    }

    /**
     * @param array<string, array{value: string, syntax: string}> $attributes
     * @return array<string, string>
     */
    protected function flattenAttributeValues(array $attributes): array
    {
        $result = [];
        foreach ($attributes as $key => $meta) {
            $result[$key] = $meta['value'];
        }
        return $result;
    }

    /**
     * @param array<string, array{value: string, syntax: string}> $attributes
     */
    protected function assertAllowedAndExplicitExpressionAttributes(
        string $component,
        array $attributes,
        string $templateFile,
        int $line
    ): void {
        $allowed = self::COMPONENT_ALLOWED_ATTRIBUTES[$component] ?? [];
        $allowedLookup = array_flip($allowed);

        foreach ($attributes as $key => $meta) {
            if (!isset($allowedLookup[$key])) {
                throw new SyntaxException(
                    "Unknown attribute '{$key}' in <{$component}>",
                    $templateFile,
                    $line,
                    "{$key}={$meta['value']}",
                    'Use only supported component attributes'
                );
            }

            if ($meta['syntax'] !== 'braced') {
                throw new SyntaxException(
                    "Attribute '{$key}' in <{$component}> must use expression syntax",
                    $templateFile,
                    $line,
                    "{$key}={$meta['value']}",
                    "Use {$key}={...} (for strings use {$key}={'text'})"
                );
            }

            if (str_contains($meta['value'], '{{') || str_contains($meta['value'], '}}')) {
                throw new SyntaxException(
                    "Mustache syntax is not allowed in component attribute '{$key}'",
                    $templateFile,
                    $line,
                    "{$key}={{...}}",
                    "Use {$key}={...}"
                );
            }

            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)+$/', $meta['value']) === 1) {
                throw new SyntaxException(
                    "Dot notation is not valid PHP expression in attribute '{$key}'",
                    $templateFile,
                    $line,
                    "{$key}={" . $meta['value'] . "}",
                    "Use {$key}={\$object->field} or {$key}={\$array['field']}"
                );
            }
        }
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
            if ($flags === null) {
                return "{$value}, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, {$depth}";
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
