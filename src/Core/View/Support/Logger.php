<?php

namespace Core\View\Support;

use Core\View\Exception\ViewException;
use Throwable;

/**
 * Logger para o Template Engine
 */
class Logger
{
    /**
     * Níveis de log
     */
    public const DEBUG = 'debug';
    public const INFO = 'info';
    public const WARNING = 'warning';
    public const ERROR = 'error';
    public const CRITICAL = 'critical';

    /**
     * Arquivo de log
     */
    protected string $logFile;

    /**
     * Nível mínimo de log
     */
    protected string $minLevel = self::DEBUG;

    /**
     * Formato de log
     */
    protected string $format = '[{timestamp}] [{level}] {message}';

    /**
     * Se deve logar em stdout
     */
    protected bool $logToStdout = false;

    public function __construct(string $logFile = 'logs/template.log', bool $logToStdout = false)
    {
        $this->logFile = $logFile;
        $this->logToStdout = $logToStdout;
        $this->ensureLogDirectory();
    }

    /**
     * Garantir que diretório de logs existe
     */
    protected function ensureLogDirectory(): void
    {
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    /**
     * Log debug
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log(self::DEBUG, $message, $context);
    }

    /**
     * Log info
     */
    public function info(string $message, array $context = []): void
    {
        $this->log(self::INFO, $message, $context);
    }

    /**
     * Log warning
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log(self::WARNING, $message, $context);
    }

    /**
     * Log error
     */
    public function error(string $message, array $context = []): void
    {
        $this->log(self::ERROR, $message, $context);
    }

    /**
     * Log critical
     */
    public function critical(string $message, array $context = []): void
    {
        $this->log(self::CRITICAL, $message, $context);
    }

    /**
     * Log exceção
     */
    public function exception(Throwable $exception): void
    {
        $message = sprintf(
            "%s: %s in %s:%d",
            get_class($exception),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine()
        );

        $context = [];
        if ($exception instanceof ViewException) {
            $context = [
                'request_id' => $exception->getRequestId(),
                'error_code' => $exception->getErrorCode(),
                'template_file' => $exception->getTemplateFile(),
                'template_line' => $exception->getTemplateLine(),
            ];
        }

        $context['trace'] = $exception->getTraceAsString();

        $this->log(self::CRITICAL, $message, $context);
    }

    /**
     * Log genérico
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        $logMessage = strtr($this->format, [
            '{timestamp}' => date('Y-m-d H:i:s'),
            '{level}' => strtoupper($level),
            '{message}' => $message,
        ]);

        if (!empty($context)) {
            $logMessage .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
        }

        $this->writeLog($logMessage);

        if ($this->logToStdout) {
            echo $logMessage . "\n";
        }
    }

    /**
     * Escrever log em arquivo
     */
    protected function writeLog(string $message): void
    {
        file_put_contents($this->logFile, $message . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Definir nível mínimo
     */
    public function setMinLevel(string $level): self
    {
        $this->minLevel = $level;
        return $this;
    }

    /**
     * Obter conteúdo de logs
     */
    public function getContents(int $lines = 100): string
    {
        if (!file_exists($this->logFile)) {
            return '';
        }

        $file = new \SplFileObject($this->logFile, 'r');
        $file->seek(PHP_INT_MAX);
        $lastLine = $file->key();
        $file->rewind();

        if ($lastLine < $lines) {
            return file_get_contents($this->logFile);
        }

        $startLine = max(0, $lastLine - $lines);
        $content = '';
        foreach (new \LimitIterator($file, $startLine) as $line) {
            $content .= $line;
        }

        return $content;
    }

    /**
     * Limpar logs
     */
    public function clear(): bool
    {
        if (file_exists($this->logFile)) {
            return unlink($this->logFile);
        }
        return true;
    }
}
