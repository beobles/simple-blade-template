<?php

namespace Core\View\Contract;

/**
 * Interface para compiladores de templates
 */
interface CompilerInterface
{
    /**
     * Compilar conteúdo
     */
    public function compile(string $content, string $templateFile = ''): string;

    /**
     * Validar sintaxe
     */
    public function validate(string $content): bool;
}
