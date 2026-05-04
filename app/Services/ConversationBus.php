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
        } else {
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
