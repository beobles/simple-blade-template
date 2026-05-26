<?php

namespace Core\View\Contract;

/**
 * Interface para gerenciadores de cache
 */
interface CacheInterface
{
    /**
     * Obter do cache
     */
    public function get(string $key): ?string;

    /**
     * Armazenar no cache
     */
    public function put(string $key, string $content): bool;

    /**
     * Verificar se existe
     */
    public function has(string $key): bool;

    /**
     * Deletar
     */
    public function delete(string $key): bool;

    /**
     * Limpar tudo
     */
    public function flush(): bool;
}
