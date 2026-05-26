<?php

namespace Core\View\Exception;

use Throwable;

class SyntaxException extends CompilationException
{
    protected string $invalidSyntax = '';
    protected string $suggestion = '';
    protected array $expectedTokens = [];

    public function __construct(
        string $message = '',
        string $templateFile = '',
        int $templateLine = 0,
        string $invalidSyntax = '',
        string $suggestion = '',
        ?Throwable $previous = null,
        array $context = [],
        array $expectedTokens = []
    ) {
        parent::__construct(
            $message,
            $templateFile,
            $templateLine,
            $templateLine,
            'syntax_error',
            $previous,
            $context
        );
        $this->invalidSyntax = $invalidSyntax;
        $this->suggestion = $suggestion;
        $this->expectedTokens = $expectedTokens;
        $this->errorCode = 'SYNTAX_ERROR';
    }

    public function setInvalidSyntax(string $syntax): self
    {
        $this->invalidSyntax = $syntax;
        return $this;
    }

    public function getInvalidSyntax(): string
    {
        return $this->invalidSyntax;
    }

    public function setSuggestion(string $suggestion): self
    {
        $this->suggestion = $suggestion;
        return $this;
    }

    public function getSuggestion(): string
    {
        return $this->suggestion;
    }

    public function setExpectedTokens(array $tokens): self
    {
        $this->expectedTokens = $tokens;
        return $this;
    }

    public function getExpectedTokens(): array
    {
        return $this->expectedTokens;
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'invalid_syntax' => $this->invalidSyntax,
            'suggestion' => $this->suggestion,
            'expected_tokens' => $this->expectedTokens,
        ]);
    }

    public function getFormattedMessage(): string
    {
        $message = parent::getFormattedMessage();
        
        if ($this->invalidSyntax) {
            $message .= "\n\nInvalid Syntax:\n  {$this->invalidSyntax}";
        }
        
        if ($this->suggestion) {
            $message .= "\n\nSuggestion:\n  {$this->suggestion}";
        }
        
        if (!empty($this->expectedTokens)) {
            $message .= "\n\nExpected: " . implode(', ', $this->expectedTokens);
        }
        
        return $message;
    }
}
