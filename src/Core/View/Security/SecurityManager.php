<?php

namespace Core\View\Security;

use Core\View\Contract\SecurityInterface;
use Core\View\Exception\SecurityException;

/**
 * Gerenciador de segurança para o Template Engine
 */
class SecurityManager implements SecurityInterface
{
    public const ESCAPE_HTML = 'html';
    public const ESCAPE_ATTR = 'attr';
    public const ESCAPE_URL = 'url';
    public const ESCAPE_CSS = 'css';
    public const ESCAPE_JS = 'js';
    public const ESCAPE_NONE = 'none';

    protected string $encoding = 'UTF-8';
    protected bool $strictMode = true;
    protected array $allowedTags = [];
    protected array $allowedProtocols = ['http', 'https', 'mailto', 'ftp', 'ftps'];

    public function __construct(string $encoding = 'UTF-8')
    {
        $this->encoding = $encoding;
    }

    /**
     * Escape HTML
     */
    public function escapeHtml($value): string
    {
        if (!is_string($value) && !is_numeric($value)) {
            $value = json_encode($value);
        }
        
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, $this->encoding);
    }

    /**
     * Escape atributos
     */
    public function escapeAttr($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, $this->encoding);
    }

    /**
     * Escape URL
     */
    public function escapeUrl($value): string
    {
        if (!is_string($value)) {
            return '';
        }

        if (!$this->isSafeUrl($value)) {
            return '';
        }

        return htmlspecialchars($value, ENT_QUOTES, $this->encoding);
    }

    /**
     * Escape CSS
     */
    public function escapeCss($value): string
    {
        $value = (string)$value;
        return preg_replace('/[^a-zA-Z0-9_\-\.\/%]/u', '', $value);
    }

    /**
     * Escape JavaScript
     */
    public function escapeJs($value): string
    {
        if (!is_string($value)) {
            $value = json_encode($value);
        }
        
        return json_encode((string)$value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    }

    /**
     * Sanitizar HTML
     */
    public function sanitizeHtml(string $html, array $allowedTags = []): string
    {
        if (empty($allowedTags)) {
            $allowedTags = $this->allowedTags ?: ['b', 'i', 'em', 'strong', 'a', 'p', 'br', 'ul', 'ol', 'li'];
        }

        $allowed = '<' . implode('><', $allowedTags) . '>';
        return strip_tags($html, $allowed);
    }

    /**
     * Verificar se URL é segura
     */
    public function isSafeUrl(string $url): bool
    {
        if (preg_match('/^([a-z][a-z0-9+\-\.]*?):/i', $url, $match)) {
            $protocol = strtolower($match[1]);
            if (!in_array($protocol, $this->allowedProtocols)) {
                return false;
            }
        } elseif (strpos($url, '/') === 0 || strpos($url, './') === 0 || strpos($url, '../') === 0) {
            return true;
        } else {
            return false;
        }

        return true;
    }

    /**
     * Validar valor
     */
    public function validate($value, string $type = 'string'): bool
    {
        switch ($type) {
            case 'email':
                return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
            case 'url':
                return filter_var($value, FILTER_VALIDATE_URL) !== false;
            case 'ip':
                return filter_var($value, FILTER_VALIDATE_IP) !== false;
            case 'int':
                return filter_var($value, FILTER_VALIDATE_INT) !== false;
            case 'float':
                return filter_var($value, FILTER_VALIDATE_FLOAT) !== false;
            case 'bool':
                return is_bool($value) || in_array($value, ['true', 'false', '1', '0', 1, 0]);
            case 'string':
                return is_string($value);
            case 'array':
                return is_array($value);
            default:
                return true;
        }
    }

    /**
     * Sanitizar valor
     */
    public function sanitize($value, string $type = 'string')
    {
        switch ($type) {
            case 'email':
                return filter_var($value, FILTER_SANITIZE_EMAIL);
            case 'url':
                return filter_var($value, FILTER_SANITIZE_URL);
            case 'int':
                return filter_var($value, FILTER_SANITIZE_NUMBER_INT);
            case 'float':
                return filter_var($value, FILTER_SANITIZE_NUMBER_FLOAT);
            case 'string':
                return filter_var($value, FILTER_SANITIZE_STRING);
            default:
                return $value;
        }
    }

    /**
     * Escape genérico
     */
    public function escape($value, string $type = self::ESCAPE_HTML): string
    {
        switch ($type) {
            case self::ESCAPE_HTML:
                return $this->escapeHtml($value);
            case self::ESCAPE_ATTR:
                return $this->escapeAttr($value);
            case self::ESCAPE_URL:
                return $this->escapeUrl($value);
            case self::ESCAPE_CSS:
                return $this->escapeCss($value);
            case self::ESCAPE_JS:
                return $this->escapeJs($value);
            case self::ESCAPE_NONE:
                return (string)$value;
            default:
                return $this->escapeHtml($value);
        }
    }

    /**
     * Definir tags permitidas
     */
    public function setAllowedTags(array $tags): self
    {
        $this->allowedTags = $tags;
        return $this;
    }

    /**
     * Obter tags permitidas
     */
    public function getAllowedTags(): array
    {
        return $this->allowedTags;
    }

    /**
     * Ativar modo estrito
     */
    public function setStrictMode(bool $strict): self
    {
        $this->strictMode = $strict;
        return $this;
    }

    /**
     * Verificar modo estrito
     */
    public function isStrictMode(): bool
    {
        return $this->strictMode;
    }
}
