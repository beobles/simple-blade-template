<?php

namespace Core\View\Exception;

use Exception;
use Throwable;

/**
 * Exceção base para o Template Engine
 * Captura contexto completo, stack trace e informações detalhadas
 */
class ViewException extends Exception implements ExceptionInterface
{
    /**
     * Arquivo do template onde ocorreu o erro
     */
    protected string $templateFile = '';

    /**
     * Número da linha no template
     */
    protected int $templateLine = 0;

    /**
     * Conteúdo ao redor da linha onde ocorreu o erro
     */
    protected string $contextContent = '';

    /**
     * Dados passados ao template
     */
    protected array $templateData = [];

    /**
     * Código do template compilado
     */
    protected string $compiledCode = '';

    /**
     * Contexto adicional da aplicação
     */
    protected array $context = [];

    /**
     * Código de erro único
     */
    protected string $errorCode = 'VIEW_ERROR';

    /**
     * Timestamp de quando o erro ocorreu
     */
    protected float $timestamp = 0.0;

    /**
     * Request ID para tracking
     */
    protected string $requestId = '';

    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        string $templateFile = '',
        int $templateLine = 0,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous);

        $this->templateFile = $templateFile;
        $this->templateLine = $templateLine;
        $this->context = $context;
        $this->timestamp = microtime(true);
        $this->requestId = $this->generateRequestId();
    }

    /**
     * Gerar ID único para rastreamento
     */
    protected function generateRequestId(): string
    {
        return bin2hex(random_bytes(8));
    }

    public function setTemplateFile(string $file): self
    {
        $this->templateFile = $file;
        return $this;
    }

    public function getTemplateFile(): string
    {
        return $this->templateFile;
    }

    public function setTemplateLine(int $line): self
    {
        $this->templateLine = $line;
        return $this;
    }

    public function getTemplateLine(): int
    {
        return $this->templateLine;
    }

    public function setContextContent(string $content): self
    {
        $this->contextContent = $content;
        return $this;
    }

    public function getContextContent(): string
    {
        return $this->contextContent;
    }

    public function setTemplateData(array $data): self
    {
        $this->templateData = $this->sanitizeData($data);
        return $this;
    }

    public function getTemplateData(): array
    {
        return $this->templateData;
    }

    public function setCompiledCode(string $code): self
    {
        $this->compiledCode = $code;
        return $this;
    }

    public function getCompiledCode(): string
    {
        return $this->compiledCode;
    }

    public function addContext(string $key, $value): self
    {
        $this->context[$key] = $value;
        return $this;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function setErrorCode(string $code): self
    {
        $this->errorCode = $code;
        return $this;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getRequestId(): string
    {
        return $this->requestId;
    }

    public function getTimestamp(): float
    {
        return $this->timestamp;
    }

    /**
     * Sanitizar dados sensíveis (não expor senhas, tokens, etc)
     */
    protected function sanitizeData(array $data): array
    {
        $sensitiveKeys = [
            'password', 'passwd', 'pwd',
            'token', 'access_token', 'refresh_token',
            'secret', 'api_key', 'api_secret',
            'private_key', 'csrf_token',
            'auth', 'authorization',
            'credit_card', 'card_number', 'cvv',
            'ssn', 'social_security',
        ];
        
        foreach ($data as $key => $value) {
            $lowerKey = strtolower($key);
            $isSensitive = false;
            
            foreach ($sensitiveKeys as $sensitiveKey) {
                if (str_contains($lowerKey, $sensitiveKey)) {
                    $isSensitive = true;
                    break;
                }
            }
            
            if ($isSensitive) {
                $data[$key] = '***REDACTED***';
            } elseif (is_array($value)) {
                $data[$key] = $this->sanitizeData($value);
            }
        }
        
        return $data;
    }

    public function getFormattedTrace(): string
    {
        return $this->getTraceAsString();
    }

    public function toArray(): array
    {
        return [
            'error_code' => $this->errorCode,
            'type' => static::class,
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'template_file' => $this->templateFile,
            'template_line' => $this->templateLine,
            'context_content' => $this->contextContent,
            'template_data' => $this->templateData,
            'compiled_code' => $this->compiledCode,
            'context' => $this->context,
            'trace' => $this->getTrace(),
            'trace_string' => $this->getFormattedTrace(),
            'request_id' => $this->requestId,
            'timestamp' => $this->timestamp,
            'previous' => $this->getPrevious() ? [
                'type' => get_class($this->getPrevious()),
                'message' => $this->getPrevious()->getMessage(),
                'file' => $this->getPrevious()->getFile(),
                'line' => $this->getPrevious()->getLine(),
            ] : null,
        ];
    }

    public function getFullContext(): array
    {
        return array_merge($this->toArray(), [
            'timestamp_formatted' => date('Y-m-d H:i:s', (int)$this->timestamp),
            'timezone' => date_default_timezone_get(),
        ]);
    }

    public function getFormattedMessage(): string
    {
        $lines = [];
        $lines[] = "═══════════════════════════════════════════════════════════";
        $lines[] = "[{$this->errorCode}] {$this->message}";
        $lines[] = "───────────────────────────────────────────────────────────";
        $lines[] = "Request ID: {$this->requestId}";
        $lines[] = "Time: " . date('Y-m-d H:i:s', (int)$this->timestamp);
        $lines[] = "";
        
        if ($this->templateFile) {
            $lines[] = "Template File: {$this->templateFile}";
        }
        
        if ($this->templateLine > 0) {
            $lines[] = "Template Line: {$this->templateLine}";
        }
        
        $lines[] = "Source File: {$this->file}";
        $lines[] = "Source Line: {$this->line}";
        $lines[] = "";
        
        if ($this->contextContent) {
            $lines[] = "Context:";
            $lines[] = $this->contextContent;
            $lines[] = "";
        }
        
        if (!empty($this->context)) {
            $lines[] = "Additional Context:";
            foreach ($this->context as $key => $value) {
                $lines[] = "  {$key}: " . json_encode($value);
            }
            $lines[] = "";
        }
        
        $lines[] = "Stack Trace:";
        $lines[] = $this->getFormattedTrace();
        $lines[] = "═══════════════════════════════════════════════════════════";
        
        return implode("\n", $lines);
    }

    public function getSummary(): string
    {
        return sprintf(
            "[%s] %s: %s in %s:%d (Template: %s:%d)",
            $this->errorCode,
            static::class,
            $this->message,
            basename($this->file),
            $this->line,
            $this->templateFile ? basename($this->templateFile) : 'unknown',
            $this->templateLine
        );
    }
}
