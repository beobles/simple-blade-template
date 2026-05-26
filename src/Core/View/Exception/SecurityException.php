<?php

namespace Core\View\Exception;

use Throwable;

class SecurityException extends ViewException
{
    protected string $securityReason = '';
    protected array $violatedRules = [];

    public function __construct(
        string $message = '',
        string $templateFile = '',
        int $templateLine = 0,
        string $securityReason = '',
        array $violatedRules = [],
        ?Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, 0, $previous, $templateFile, $templateLine, $context);
        $this->securityReason = $securityReason;
        $this->violatedRules = $violatedRules;
        $this->errorCode = 'SECURITY_ERROR';
    }

    public function getSecurityReason(): string
    {
        return $this->securityReason;
    }

    public function getViolatedRules(): array
    {
        return $this->violatedRules;
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'security_reason' => $this->securityReason,
            'violated_rules' => $this->violatedRules,
        ]);
    }

    public function getFormattedMessage(): string
    {
        $message = parent::getFormattedMessage();
        
        if ($this->securityReason) {
            $message .= "\n\nSecurity Reason: {$this->securityReason}";
        }
        
        if (!empty($this->violatedRules)) {
            $message .= "\n\nViolated Rules:";
            foreach ($this->violatedRules as $rule) {
                $message .= "\n  ✗ {$rule}";
            }
        }
        
        return $message;
    }
}
