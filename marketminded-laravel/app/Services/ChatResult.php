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
    ) {}

    public function usage(): array
    {
        return [
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cost' => $this->cost,
            'iterations' => $this->iterations,
        ];
    }
}
