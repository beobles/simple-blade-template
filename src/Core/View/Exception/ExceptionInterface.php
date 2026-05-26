<?php

namespace Core\View\Exception;

use Throwable;

/**
 * Interface para exceções do Template Engine
 * Define contrato para todas as exceções
 */
interface ExceptionInterface extends Throwable
{
    /**
     * Obter código de erro
     */
    public function getErrorCode(): string;

    /**
     * Obter contexto completo
     */
    public function getFullContext(): array;

    /**
     * Converter para array
     */
    public function toArray(): array;

    /**
     * Obter mensagem formatada
     */
    public function getFormattedMessage(): string;
}
