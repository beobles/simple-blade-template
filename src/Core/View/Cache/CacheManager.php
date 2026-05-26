<?php

namespace Core\View\Cache;

use Core\View\Contract\CacheInterface;
use Core\View\Exception\CacheException;

/**
 * Gerenciador de cache de templates compilados
 */
class CacheManager implements CacheInterface
{
    protected string $cachePath;
    protected bool $enabled = true;
    protected string $prefix = 'view_';
    protected int $ttl = 0;

    public function __construct(string $cachePath, bool $enabled = true)
    {
        $this->cachePath = rtrim($cachePath, '/\\');
        $this->enabled = $enabled;

        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0755, true);
        }
    }

    /**
     * Obter do cache
     */
    public function get(string $key): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        try {
            $path = $this->getPath($key);
            
            if (!file_exists($path)) {
                return null;
            }

            if ($this->ttl > 0) {
                $mtime = filemtime($path);
                if (time() - $mtime > $this->ttl) {
                    $this->delete($key);
                    return null;
                }
            }

            return file_get_contents($path);
        } catch (\Exception $e) {
            throw new CacheException(
                "Error reading cache: " . $e->getMessage(),
                $key,
                'get',
                $e
            );
        }
    }

    /**
     * Armazenar no cache
     */
    public function put(string $key, string $content): bool
    {
        if (!$this->enabled) {
            return false;
        }

        try {
            $path = $this->getPath($key);
            $dir = dirname($path);

            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            return file_put_contents($path, $content, LOCK_EX) !== false;
        } catch (\Exception $e) {
            throw new CacheException(
                "Error writing cache: " . $e->getMessage(),
                $key,
                'put',
                $e
            );
        }
    }

    /**
     * Verificar se existe
     */
    public function has(string $key): bool
    {
        if (!$this->enabled) {
            return false;
        }

        return file_exists($this->getPath($key));
    }

    /**
     * Deletar
     */
    public function delete(string $key): bool
    {
        $path = $this->getPath($key);
        if (file_exists($path)) {
            return unlink($path);
        }
        return true;
    }

    /**
     * Verificar se cache é mais recente
     */
    public function isFresh(string $key, string $sourceFile): bool
    {
        if (!$this->enabled || !file_exists($sourceFile)) {
            return false;
        }

        $path = $this->getPath($key);
        if (!file_exists($path)) {
            return false;
        }

        return filemtime($path) > filemtime($sourceFile);
    }

    /**
     * Limpar tudo
     */
    public function flush(): bool
    {
        try {
            $files = glob($this->cachePath . '/' . $this->prefix . '*.php');
            
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }

            return true;
        } catch (\Exception $e) {
            throw new CacheException(
                "Error flushing cache: " . $e->getMessage(),
                '*',
                'flush',
                $e
            );
        }
    }

    /**
     * Obter tamanho do cache
     */
    public function getSize(): int
    {
        $size = 0;
        $files = glob($this->cachePath . '/' . $this->prefix . '*.php');
        
        foreach ($files as $file) {
            if (is_file($file)) {
                $size += filesize($file);
            }
        }

        return $size;
    }

    /**
     * Obter caminho do arquivo
     */
    public function getPath(string $key): string
    {
        $hash = md5($key);
        return $this->cachePath . '/' . $this->prefix . $hash . '.php';
    }

    /**
     * Ativar/desativar
     */
    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }

    /**
     * Verificar se está ativado
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Definir TTL
     */
    public function setTtl(int $seconds): self
    {
        $this->ttl = $seconds;
        return $this;
    }

    /**
     * Obter TTL
     */
    public function getTtl(): int
    {
        return $this->ttl;
    }
}
