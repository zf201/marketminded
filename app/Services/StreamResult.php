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
        // Future: surface in AI log for cost breakdown.
        public readonly int $reasoningTokens = 0,
        public readonly int $cacheReadTokens = 0,
        public readonly int $cacheWriteTokens = 0,
        public readonly string $reasoningContent = '',
    ) {}
}
