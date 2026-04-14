<?php

namespace App\Services\Writer;

final class Brief
{
    /** @param array<string, mixed> $data */
    private function __construct(private array $data) {}

    /** @param array<string, mixed> $data */
    public static function fromJson(array $data): self
    {
        return new self($data);
    }

    /** @return array<string, mixed> */
    public function toJson(): array
    {
        return $this->data;
    }

    /** @return array<string, mixed>|null */
    public function topic(): ?array
    {
        return $this->data['topic'] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function research(): ?array
    {
        return $this->data['research'] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function outline(): ?array
    {
        return $this->data['outline'] ?? null;
    }

    public function contentPieceId(): ?int
    {
        $id = $this->data['content_piece_id'] ?? null;
        return $id === null ? null : (int) $id;
    }

    public function hasTopic(): bool
    {
        return $this->topic() !== null;
    }

    public function hasResearch(): bool
    {
        return $this->research() !== null;
    }

    public function hasOutline(): bool
    {
        return $this->outline() !== null;
    }

    public function hasContentPiece(): bool
    {
        return $this->contentPieceId() !== null;
    }

    /** @param array<string, mixed> $topic */
    public function withTopic(array $topic): self
    {
        return $this->with('topic', $topic);
    }

    /** @param array<string, mixed> $research */
    public function withResearch(array $research): self
    {
        return $this->with('research', $research);
    }

    /** @param array<string, mixed> $outline */
    public function withOutline(array $outline): self
    {
        return $this->with('outline', $outline);
    }

    public function withContentPieceId(int $id): self
    {
        return $this->with('content_piece_id', $id);
    }

    public function statusSummary(): string
    {
        $lines = [];

        if ($this->hasTopic()) {
            $lines[] = 'topic: ✓ ' . ($this->topic()['title'] ?? '');
        } else {
            $lines[] = 'topic: ✗';
        }

        if ($this->hasResearch()) {
            $claims = count($this->research()['claims'] ?? []);
            $sources = count($this->research()['sources'] ?? []);
            $lines[] = "research: ✓ ({$claims} claims, {$sources} sources)";
        } else {
            $lines[] = 'research: ✗';
        }

        if ($this->hasOutline()) {
            $sections = count($this->outline()['sections'] ?? []);
            $words = $this->outline()['target_length_words'] ?? '?';
            $lines[] = "outline: ✓ ({$sections} sections, ~{$words} words)";
        } else {
            $lines[] = 'outline: ✗';
        }

        if ($this->hasContentPiece()) {
            $lines[] = 'content_piece: ✓ (id=' . $this->contentPieceId() . ')';
        } else {
            $lines[] = 'content_piece: ✗';
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed>|int $value */
    private function with(string $key, array|int $value): self
    {
        $copy = $this->data;
        $copy[$key] = $value;
        return new self($copy);
    }
}
