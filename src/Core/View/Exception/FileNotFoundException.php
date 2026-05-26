<?php

namespace Core\View\Exception;

use Throwable;

class FileNotFoundException extends ViewException
{
    protected array $searchedPaths = [];
    protected string $attemptedName = '';

    public function __construct(
        string $message = '',
        string $templateFile = '',
        array $searchedPaths = [],
        ?Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, 0, $previous, $templateFile, 0, $context);
        $this->searchedPaths = $searchedPaths;
        $this->attemptedName = $templateFile;
        $this->errorCode = 'FILE_NOT_FOUND';
    }

    public function setSearchedPaths(array $paths): self
    {
        $this->searchedPaths = $paths;
        return $this;
    }

    public function getSearchedPaths(): array
    {
        return $this->searchedPaths;
    }

    public function getAttemptedName(): string
    {
        return $this->attemptedName;
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'searched_paths' => $this->searchedPaths,
            'attempted_name' => $this->attemptedName,
        ]);
    }

    public function getFormattedMessage(): string
    {
        $message = parent::getFormattedMessage();
        
        if (!empty($this->searchedPaths)) {
            $message .= "\n\nSearched Paths:\n";
            foreach ($this->searchedPaths as $path) {
                $message .= "  ✗ {$path}\n";
            }
        }
        
        return $message;
    }
}
