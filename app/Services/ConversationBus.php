<?php

namespace App\Services;

use App\Events\ConversationEvent;
use Illuminate\Support\Facades\Cache;

class ConversationBus
{
    private array $events = [];
    private string $text = '';

    public function __construct(private int $conversationId) {}

    public function publish(string $type, array $payload = []): void
    {
        if (Cache::pull("conv-stop:{$this->conversationId}")) {
            throw new TurnStoppedException();
        }

        $this->doBroadcast($type, $payload);

        if ($type === 'text_chunk') {
            $this->text .= $payload['content'];
            // Persist text alongside other events so order can be reconstructed
            // for history. Coalesce consecutive text_chunks into one event so
            // we don't blow up the events array.
            $lastIdx = count($this->events) - 1;
            if ($lastIdx >= 0 && ($this->events[$lastIdx]['type'] ?? '') === 'text_chunk') {
                $this->events[$lastIdx]['payload']['content'] .= $payload['content'];
            } else {
                $this->events[] = compact('type', 'payload');
            }
        } elseif ($type !== 'reasoning_chunk' && $type !== 'subagent_reasoning_chunk') {
            // Reasoning chunks are transient: they fire per token and only matter
            // for the live UI. Persisting them would balloon message metadata
            // and overflow the Livewire snapshot.
            $this->events[] = compact('type', 'payload');
        }
    }

    protected function doBroadcast(string $type, array $payload): void
    {
        broadcast(new ConversationEvent($this->conversationId, $type, $payload));
    }

    public function events(): array
    {
        return $this->events;
    }

    public function text(): string
    {
        return $this->text;
    }
}
