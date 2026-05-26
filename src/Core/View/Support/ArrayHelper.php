<?php

namespace Core\View\Support;

/**
 * Helper para manipulação de arrays em templates
 */
class ArrayHelper
{
    /**
     * Obter valor com caminho
     */
    public static function get(array $array, string $path, $default = null)
    {
        $keys = explode('.', $path);
        $value = $array;

        foreach ($keys as $key) {
            if (!is_array($value) || !isset($value[$key])) {
                return $default;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * Verificar se tem chave
     */
    public static function has(array $array, string $key): bool
    {
        return isset($array[$key]) || array_key_exists($key, $array);
    }

    /**
     * Contar elementos
     */
    public static function count(array $array): int
    {
        return count($array);
    }

    /**
     * Primeiro elemento
     */
    public static function first(array $array)
    {
        return reset($array);
    }

    /**
     * Último elemento
     */
    public static function last(array $array)
    {
        return end($array);
    }

    /**
     * Filtrar
     */
    public static function filter(array $array, callable $callback): array
    {
        return array_filter($array, $callback);
    }

    /**
     * Mapear
     */
    public static function map(array $array, callable $callback): array
    {
        return array_map($callback, $array);
    }

    /**
     * Unir arrays
     */
    public static function merge(array ...$arrays): array
    {
        return array_merge(...$arrays);
    }

    /**
     * Ordenar
     */
    public static function sort(array $array, int $flags = SORT_REGULAR): array
    {
        sort($array, $flags);
        return $array;
    }

    /**
     * Ordernar reverso
     */
    public static function rsort(array $array, int $flags = SORT_REGULAR): array
    {
        rsort($array, $flags);
        return $array;
    }

    /**
     * Verificar se está vazio
     */
    public static function isEmpty(array $array): bool
    {
        return empty($array);
    }
}
