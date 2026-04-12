<?php

namespace App\Services;

class ToolEvent
{
    public function __construct(
        public readonly string $name,
        public readonly array $arguments,
        public readonly ?string $result,
        public readonly string $status,
    ) {}
}
