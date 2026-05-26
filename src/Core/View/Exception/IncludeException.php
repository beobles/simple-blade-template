<?php

namespace Core\View\Exception;

use Throwable;

class IncludeException extends ViewException
{
    protected string $includedTemplate = '';
    protected array $includeChain = [];
    protected bool $isCircularDependency = false;

    public function __construct(
        string $message = '',
        string $templateFile = '',
        int $templateLine = 0,
        string $includedTemplate = '',
        array $includeChain = [],
        ?Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, 0, $previous, $templateFile, $templateLine, $context);
        $this->includedTemplate = $includedTemplate;
        $this->includeChain = $includeChain;
        $this->isCircularDependency = $this->detectCircularDependency();
        $this->errorCode = 'INCLUDE_ERROR';
    }

    protected function detectCircularDependency(): bool
    {
        if (empty($this->includeChain)) {
            return false;
        }
        
        $unique = array_unique($this->includeChain);
        return count($unique) < count($this->includeChain);
    }

    public function setIncludedTemplate(string $template): self
    {
        $this->includedTemplate = $template;
        return $this;
    }

    public function getIncludedTemplate(): string
    {
        return $this->includedTemplate;
    }

    public function setIncludeChain(array $chain): self
    {
        $this->includeChain = $chain;
        $this->isCircularDependency = $this->detectCircularDependency();
        return $this;
    }

    public function getIncludeChain(): array
    {
        return $this->includeChain;
    }

    public function isCircularDependency(): bool
    {
        return $this->isCircularDependency;
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'included_template' => $this->includedTemplate,
            'include_chain' => $this->includeChain,
            'is_circular_dependency' => $this->isCircularDependency,
        ]);
    }

    public function getFormattedMessage(): string
    {
        $message = parent::getFormattedMessage();
        
        if ($this->includedTemplate) {
            $message .= "\n\nIncluded Template: {$this->includedTemplate}";
        }
        
        if (!empty($this->includeChain)) {
            $message .= "\n\nInclude Chain:";
            foreach ($this->includeChain as $index => $template) {
                $prefix = ($index === count($this->includeChain) - 1) ? "└──" : "├──";
                $message .= "\n" . str_repeat("  ", $index) . "{$prefix} {$template}";
            }
        }
        
        if ($this->isCircularDependency) {
            $message .= "\n\n⚠️  CIRCULAR DEPENDENCY DETECTED!";
        }
        
        return $message;
    }
}
