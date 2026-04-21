<?php

use App\Services\OpenRouterClient;
use App\Services\StreamResult;
use App\Services\ToolEvent;
use App\Services\UrlFetcher;
use Illuminate\Support\Facades\Http;

test('streamChatWithTools handles fetch_url tool call', function () {
    // First request: model calls fetch_url (non-streaming response)
    $toolCallResponse = [
        'choices' => [[
            'message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => [
                        'name' => 'fetch_url',
                        'arguments' => json_encode(['url' => 'https://example.com']),
                    ],
                ]],
            ],
        ]],
        'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 10],
    ];

    // Second request: model responds with text after getting tool result
    $textStream = implode("\n", [
        'data: ' . json_encode(['choices' => [['delta' => ['content' => 'I read the page.']]], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 5, 'cost' => 0.001]]),
        '',
        'data: [DONE]',
        '',
    ]);

    Http::fake([
        'openrouter.ai/*' => Http::sequence()
            ->push($toolCallResponse, 200, ['Content-Type' => 'application/json'])
            ->push($textStream, 200, ['Content-Type' => 'text/event-stream']),
        'example.com' => Http::response('<html><head><title>Example</title></head><body>Content here</body></html>'),
    ]);

    $client = new OpenRouterClient('sk-test', 'test-model', new UrlFetcher);

    $events = [];
    $chunks = [];
    $result = null;

    foreach ($client->streamChatWithTools('System prompt', [['role' => 'user', 'content' => 'Read example.com']], [
        ['type' => 'function', 'function' => ['name' => 'fetch_url', 'parameters' => ['type' => 'object', 'properties' => ['url' => ['type' => 'string']]]]],
    ]) as $item) {
        if ($item instanceof ToolEvent) {
            $events[] = $item;
        } elseif ($item instanceof StreamResult) {
            $result = $item;
        } else {
            $chunks[] = $item;
        }
    }

    expect($events)->toHaveCount(2);
    expect($events[0]->name)->toBe('fetch_url');
    expect($events[0]->status)->toBe('started');
    expect($events[1]->status)->toBe('completed');
    expect($chunks)->toBe(['I read the page.']);
    expect($result)->toBeInstanceOf(StreamResult::class);
    expect($result->content)->toBe('I read the page.');
});

test('streamChatWithTools passes through text-only stream', function () {
    $sseBody = implode("\n", [
        'data: ' . json_encode(['choices' => [['delta' => ['content' => 'Hello']]]]),
        '',
        'data: ' . json_encode(['choices' => [['delta' => ['content' => ' world']]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2, 'cost' => 0.0005]]),
        '',
        'data: [DONE]',
        '',
    ]);

    Http::fake([
        'openrouter.ai/*' => Http::response($sseBody, 200, ['Content-Type' => 'text/event-stream']),
    ]);

    $client = new OpenRouterClient('sk-test', 'test-model', new UrlFetcher);

    $chunks = [];
    $result = null;

    foreach ($client->streamChatWithTools('System', [['role' => 'user', 'content' => 'Hi']]) as $item) {
        if ($item instanceof StreamResult) {
            $result = $item;
        } elseif (is_string($item)) {
            $chunks[] = $item;
        }
    }

    expect($chunks)->toBe(['Hello', ' world']);
    expect($result->content)->toBe('Hello world');
});

test('streamChatWithTools uses custom tool executor', function () {
    $toolCallResponse = [
        'choices' => [[
            'message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => [
                        'name' => 'update_brand_intelligence',
                        'arguments' => json_encode(['setup' => ['homepage_url' => 'https://test.com']]),
                    ],
                ]],
            ],
        ]],
        'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 10],
    ];

    $textStream = implode("\n", [
        'data: ' . json_encode(['choices' => [['delta' => ['content' => 'Done.']]], 'usage' => ['prompt_tokens' => 60, 'completion_tokens' => 1]]),
        '',
        'data: [DONE]',
        '',
    ]);

    Http::fake([
        'openrouter.ai/*' => Http::sequence()
            ->push($toolCallResponse, 200, ['Content-Type' => 'application/json'])
            ->push($textStream, 200, ['Content-Type' => 'text/event-stream']),
    ]);

    $executedTools = [];
    $executor = function (string $name, array $args) use (&$executedTools): string {
        $executedTools[] = ['name' => $name, 'args' => $args];
        return '{"status":"saved","sections":["setup"]}';
    };

    $client = new OpenRouterClient('sk-test', 'test-model', new UrlFetcher);

    $events = [];
    $result = null;
    foreach ($client->streamChatWithTools('System', [['role' => 'user', 'content' => 'Update']], [], $executor) as $item) {
        if ($item instanceof ToolEvent) $events[] = $item;
        elseif ($item instanceof StreamResult) $result = $item;
    }

    expect($executedTools)->toHaveCount(1);
    expect($executedTools[0]['name'])->toBe('update_brand_intelligence');
    expect($events)->toHaveCount(2);
    expect($events[0]->status)->toBe('started');
    expect($events[1]->status)->toBe('completed');
    expect($events[1]->result)->toContain('saved');
});
