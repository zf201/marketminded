<?php

namespace App\Services;

class ChatResult
{
    public function __construct(
        public readonly mixed $data,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly float $cost = 0,
        public readonly int $iterations = 0,
        public readonly array $messages = [],
        // Future: surface in AI log for cost breakdown.
        public readonly int $reasoningTokens = 0,
        public readonly int $cacheReadTokens = 0,
        public readonly int $cacheWriteTokens = 0,
    ) {}

    public function usage(): array
    {
        return [
            'input_tokens'       => $this->inputTokens,
            'output_tokens'      => $this->outputTokens,
            'cost'               => $this->cost,
            'iterations'         => $this->iterations,
            'reasoning_tokens'   => $this->reasoningTokens,
            'cache_read_tokens'  => $this->cacheReadTokens,
            'cache_write_tokens' => $this->cacheWriteTokens,
        ];
    }
}
