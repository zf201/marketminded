<?php

use App\Services\OpenRouterClient;
use App\Services\StreamResult;
use App\Services\UrlFetcher;
use Illuminate\Support\Facades\Http;

test('streamChat yields content chunks and returns StreamResult', function () {
    $sseBody = implode("\n", [
        'data: {"choices":[{"delta":{"content":"Hello"}}]}',
        '',
        'data: {"choices":[{"delta":{"content":" world"}}]}',
        '',
        'data: {"choices":[{"delta":{"content":"!"}}],"usage":{"prompt_tokens":10,"completion_tokens":3,"cost":0.001}}',
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

    foreach ($client->streamChat('You are helpful.', [['role' => 'user', 'content' => 'Hi']]) as $chunk) {
        if ($chunk instanceof StreamResult) {
            $result = $chunk;
        } else {
            $chunks[] = $chunk;
        }
    }

    expect($chunks)->toBe(['Hello', ' world', '!']);
    expect($result)->toBeInstanceOf(StreamResult::class);
    expect($result->content)->toBe('Hello world!');
    expect($result->inputTokens)->toBe(10);
    expect($result->outputTokens)->toBe(3);

    Http::assertSent(function ($request) {
        return $request['stream'] === true
            && $request['messages'][0]['role'] === 'system'
            && $request['messages'][0]['content'] === 'You are helpful.'
            && $request['messages'][1]['role'] === 'user';
    });
});

test('streamChat sends system prompt and message history', function () {
    $sseBody = implode("\n", [
        'data: {"choices":[{"delta":{"content":"OK"}}],"usage":{"prompt_tokens":5,"completion_tokens":1}}',
        '',
        'data: [DONE]',
        '',
    ]);

    Http::fake([
        'openrouter.ai/*' => Http::response($sseBody, 200, ['Content-Type' => 'text/event-stream']),
    ]);

    $client = new OpenRouterClient('sk-test', 'test-model', new UrlFetcher);

    $messages = [
        ['role' => 'user', 'content' => 'Hi'],
        ['role' => 'assistant', 'content' => 'Hello'],
        ['role' => 'user', 'content' => 'How are you?'],
    ];

    $result = null;
    foreach ($client->streamChat('System prompt', $messages) as $chunk) {
        if ($chunk instanceof StreamResult) {
            $result = $chunk;
        }
    }

    Http::assertSent(function ($request) {
        return count($request['messages']) === 4
            && $request['messages'][0]['role'] === 'system';
    });
});
