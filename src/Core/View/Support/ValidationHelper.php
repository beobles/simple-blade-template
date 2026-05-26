<?php

namespace Core\View\Support;

/**
 * Helper para validação de dados em templates
 */
class ValidationHelper
{
    /**
     * Validar email
     */
    public static function email($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Validar URL
     */
    public static function url($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Validar IP
     */
    public static function ip($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Validar inteiro
     */
    public static function integer($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false;
    }

    /**
     * Validar float
     */
    public static function float($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_FLOAT) !== false;
    }

    /**
     * Validar booleano
     */
    public static function boolean($value): bool
    {
        return is_bool($value) || in_array($value, ['true', 'false', '1', '0', 1, 0]);
    }

    /**
     * Validar comprimento mínimo
     */
    public static function minLength(string $value, int $min): bool
    {
        return strlen($value) >= $min;
    }

    /**
     * Validar comprimento máximo
     */
    public static function maxLength(string $value, int $max): bool
    {
        return strlen($value) <= $max;
    }

    /**
     * Validar entre valores
     */
    public static function between($value, $min, $max): bool
    {
        return $value >= $min && $value <= $max;
    }

    /**
     * Validar padrão regex
     */
    public static function pattern(string $value, string $pattern): bool
    {
        return preg_match($pattern, $value) === 1;
    }
}
