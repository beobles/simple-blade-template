<?php

namespace Core\View\Contract;

/**
 * Interface para engines de template
 */
interface EngineInterface
{
    /**
     * Renderizar template
     */
    public function render(string $template, array $data = []): string;

    /**
     * Atribuir variáveis
     */
    public function assign($key, $value = null): self;

    /**
     * Adicionar caminho de views
     */
    public function addViewPath(string $path): self;
}
