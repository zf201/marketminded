<?php

namespace App\Services;

class StreamResult
{
    public function __construct(
        public readonly string $content,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly float $cost = 0,
        public readonly int $webSearchRequests = 0,
    ) {}
}
