<?php

namespace Core\View\Contract;

/**
 * Interface para gerenciadores de segurança
 */
interface SecurityInterface
{
    /**
     * Escape de valor
     */
    public function escape($value, string $type = 'html'): string;

    /**
     * Validar valor
     */
    public function validate($value, string $type = 'string'): bool;

    /**
     * Sanitizar valor
     */
    public function sanitize($value, string $type = 'string');
}
