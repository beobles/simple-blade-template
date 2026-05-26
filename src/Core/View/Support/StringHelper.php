<?php

namespace Core\View\Support;

/**
 * Helper para manipulação de strings em templates
 */
class StringHelper
{
    /**
     * Limitar caracteres
     */
    public static function limit(string $value, int $limit = 100, string $end = '...'): string
    {
        if (strlen($value) <= $limit) {
            return $value;
        }
        return substr($value, 0, $limit) . $end;
    }

    /**
     * Limitar palavras
     */
    public static function limitWords(string $value, int $words = 100, string $end = '...'): string
    {
        $wordArray = explode(' ', $value);
        if (count($wordArray) <= $words) {
            return $value;
        }
        return implode(' ', array_slice($wordArray, 0, $words)) . $end;
    }

    /**
     * Capitalizar primeira letra
     */
    public static function capitalize(string $value): string
    {
        return ucfirst($value);
    }

    /**
     * Maiúsculas
     */
    public static function upper(string $value): string
    {
        return strtoupper($value);
    }

    /**
     * Minúsculas
     */
    public static function lower(string $value): string
    {
        return strtolower($value);
    }

    /**
     * Slug
     */
    public static function slug(string $value, string $separator = '-'): string
    {
        $value = preg_replace('/[^a-z0-9]+/i', $separator, $value);
        return trim($value, $separator);
    }

    /**
     * CamelCase
     */
    public static function camelCase(string $value): string
    {
        $value = preg_replace_callback('/-+(.)/i', function ($match) {
            return strtoupper($match[1]);
        }, $value);
        return lcfirst($value);
    }

    /**
     * PascalCase
     */
    public static function pascalCase(string $value): string
    {
        return ucfirst(self::camelCase($value));
    }

    /**
     * snake_case
     */
    public static function snakeCase(string $value): string
    {
        return preg_replace_callback('/[A-Z]/', function ($match) {
            return '_' . strtolower($match[0]);
        }, lcfirst($value));
    }

    /**
     * Repetir
     */
    public static function repeat(string $value, int $times): string
    {
        return str_repeat($value, $times);
    }

    /**
     * Reverter
     */
    public static function reverse(string $value): string
    {
        return strrev($value);
    }
}
