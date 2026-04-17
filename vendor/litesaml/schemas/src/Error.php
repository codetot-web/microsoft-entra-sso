<?php

namespace LiteSaml;

class Error
{
    public function __construct(
        public int $level,
        public int $code,
        public string $message,
        public int $line,
        public int $column,
    ) {
    }
}
