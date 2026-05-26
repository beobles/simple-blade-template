<?php

namespace Core\View\Exception;

use Throwable;

class CacheException extends ViewException
{
    protected string $cacheKey = '';
    protected string $cacheOperation = '';

    public function __construct(
        string $message = '',
        string $cacheKey = '',
        string $cacheOperation = 'unknown',
        ?Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, 0, $previous, '', 0, $context);
        $this->cacheKey = $cacheKey;
        $this->cacheOperation = $cacheOperation;
        $this->errorCode = 'CACHE_ERROR';
    }

    public function getCacheKey(): string
    {
        return $this->cacheKey;
    }

    public function getCacheOperation(): string
    {
        return $this->cacheOperation;
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'cache_key' => $this->cacheKey,
            'cache_operation' => $this->cacheOperation,
        ]);
    }
}
