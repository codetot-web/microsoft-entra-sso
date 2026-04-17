<?php

namespace LiteSaml;

class ErrorBag
{
    private array $errors = [];

    public function addError(int $level, int $code, string $message, int $line = 0, int $column = 0): self
    {
        $error = new Error($level, $code, $message, $line, $column);

        $this->errors[] = $error;

        return $this;
    }

    public function hasErrors(): bool
    {
        return count($this->errors) > 0;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
