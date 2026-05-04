<?php

use App\Services\ConversationBus;
use App\Services\TurnStoppedException;
use Illuminate\Support\Facades\Cache;

test('ConversationBus accumulates text and coalesces consecutive text_chunks', function () {
    $bus = new class(99) extends ConversationBus {
        protected function doBroadcast(string $type, array $payload): void {}
    };

    $bus->publish('text_chunk', ['content' => 'Hello ']);
    $bus->publish('text_chunk', ['content' => 'world']);

    expect($bus->text())->toBe('Hello world');
    // Consecutive text_chunks coalesce into one event for compact persistence.
    expect($bus->events())->toHaveCount(1);
    expect($bus->events()[0]['type'])->toBe('text_chunk');
    expect($bus->events()[0]['payload']['content'])->toBe('Hello world');
});

test('ConversationBus stores non-text events', function () {
    $bus = new class(99) extends ConversationBus {
        protected function doBroadcast(string $type, array $payload): void {}
    };

    $bus->publish('subagent_started', ['agent' => 'research_topic', 'title' => 'Research', 'color' => 'purple']);
    $bus->publish('subagent_completed', ['agent' => 'research_topic', 'card' => ['kind' => 'research']]);

    expect($bus->events())->toHaveCount(2);
    expect($bus->events()[0]['type'])->toBe('subagent_started');
});

test('ConversationBus throws TurnStoppedException when stop flag set', function () {
    Cache::put('conv-stop:42', true, 60);

    $broadcastFired = false;
    $bus = new class(42) extends ConversationBus {
        public bool $broadcastFired = false;
        protected function doBroadcast(string $type, array $payload): void
        {
            $this->broadcastFired = true;
        }
    };

    expect(fn () => $bus->publish('text_chunk', ['content' => 'x']))->toThrow(TurnStoppedException::class);
    expect($bus->broadcastFired)->toBeFalse();
    expect(Cache::get('conv-stop:42'))->toBeNull();
});
