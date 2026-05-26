<?php

namespace Core\View\Exception;

use Throwable;

class CompilationException extends ViewException
{
    protected int $compilationLine = 0;
    protected string $errorType = 'compilation_error';

    public function __construct(
        string $message = '',
        string $templateFile = '',
        int $templateLine = 0,
        int $compilationLine = 0,
        string $errorType = 'compilation_error',
        ?Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, 0, $previous, $templateFile, $templateLine, $context);
        $this->compilationLine = $compilationLine;
        $this->errorType = $errorType;
        $this->errorCode = 'COMPILATION_ERROR';
    }

    public function setCompilationLine(int $line): self
    {
        $this->compilationLine = $line;
        return $this;
    }

    public function getCompilationLine(): int
    {
        return $this->compilationLine;
    }

    public function setErrorType(string $type): self
    {
        $this->errorType = $type;
        return $this;
    }

    public function getErrorType(): string
    {
        return $this->errorType;
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'compilation_line' => $this->compilationLine,
            'error_type' => $this->errorType,
        ]);
    }
}
