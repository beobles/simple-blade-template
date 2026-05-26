<?php

namespace Core\View\Exception;

use Throwable;

class RuntimeException extends ViewException
{
    protected string $variableName = '';
    protected string $errorType = 'runtime_error';
    protected ?string $suggestedFix = null;

    public function __construct(
        string $message = '',
        string $templateFile = '',
        int $templateLine = 0,
        string $variableName = '',
        string $errorType = 'runtime_error',
        ?Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, 0, $previous, $templateFile, $templateLine, $context);
        $this->variableName = $variableName;
        $this->errorType = $errorType;
        $this->errorCode = 'RUNTIME_ERROR';
    }

    public function setVariableName(string $name): self
    {
        $this->variableName = $name;
        return $this;
    }

    public function getVariableName(): string
    {
        return $this->variableName;
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

    public function setSuggestedFix(?string $fix): self
    {
        $this->suggestedFix = $fix;
        return $this;
    }

    public function getSuggestedFix(): ?string
    {
        return $this->suggestedFix;
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'variable_name' => $this->variableName,
            'error_type' => $this->errorType,
            'suggested_fix' => $this->suggestedFix,
        ]);
    }
}
