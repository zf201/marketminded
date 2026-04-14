<?php

namespace App\Services\Writer;

final readonly class AgentResult
{
    /**
     * @param array<string, mixed>|null $cardPayload
     */
    private function __construct(
        public string $status,
        public ?Brief $brief,
        public ?array $cardPayload,
        public ?string $summary,
        public ?string $errorMessage,
    ) {}

    /** @param array<string, mixed> $cardPayload */
    public static function ok(Brief $brief, array $cardPayload, string $summary): self
    {
        return new self('ok', $brief, $cardPayload, $summary, null);
    }

    public static function error(string $message): self
    {
        return new self('error', null, null, null, $message);
    }

    public function isOk(): bool
    {
        return $this->status === 'ok';
    }
}
