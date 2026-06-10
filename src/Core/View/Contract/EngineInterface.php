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

    /**
     * Definir extensões de template aceitas na resolução automática.
     *
     * @param array<int, string> $extensions
     */
    public function setTemplateExtensions(array $extensions): self;

    /**
     * Obter extensões de template usadas na resolução automática.
     *
     * @return array<int, string>
     */
    public function getTemplateExtensions(): array;

    /**
     * Ativar/desativar modo debug da engine.
     */
    public function setDebug(bool $enabled): self;

    /**
     * Verificar se a engine está em modo debug.
     */
    public function isDebug(): bool;

    /**
     * Obter contexto da última renderização.
     *
     * @return array<string, mixed>
     */
    public function getLastRenderContext(): array;
}
