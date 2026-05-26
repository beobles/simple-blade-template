<?php

namespace Core\View\Compiler;

use Core\View\Contract\CompilerInterface;
use Core\View\Exception\CompilationException;
use Core\View\Exception\SyntaxException;
use Core\View\Security\SecurityManager;

/**
 * Compilador de templates Blade
 */
class Compiler implements CompilerInterface
{
    protected SecurityManager $security;
    protected array $customDirectives = [];
    protected array $internalCallbacks = [];

    public function __construct(SecurityManager $security = null)
    {
        $this->security = $security ?? new SecurityManager();
    }

    /**
     * Compilar conteúdo
     */
    public function compile(string $content, string $templateFile = ''): string
    {
        try {
            // Tokenização
            $lexer = new Lexer($content);
            $tokens = $lexer->tokenize();

            // Parsing
            $parser = new Parser($tokens, $templateFile);
            foreach ($this->customDirectives as $name => $callback) {
                $parser->addDirective($name, $callback);
            }
            foreach ($this->internalCallbacks as $name => $callback) {
                $parser->setInternalCallback($name, $callback);
            }
            
            return $parser->parse();
        } catch (SyntaxException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new CompilationException(
                "Error compiling template: " . $e->getMessage(),
                $templateFile,
                0,
                0,
                'compilation_error',
                $e
            );
        }
    }

    /**
     * Validar sintaxe
     */
    public function validate(string $content): bool
    {
        try {
            $lexer = new Lexer($content);
            $tokens = $lexer->tokenize();
            $parser = new Parser($tokens);
            $parser->parse();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Registrar diretiva customizada
     */
    public function addDirective(string $name, callable $callback): self
    {
        $this->customDirectives[$name] = $callback;
        return $this;
    }

    /**
     * Registrar callback interno da engine
     */
    public function setInternalCallback(string $name, callable $callback): self
    {
        $this->internalCallbacks[$name] = $callback;
        return $this;
    }

    /**
     * Obter security manager
     */
    public function getSecurity(): SecurityManager
    {
        return $this->security;
    }
}
