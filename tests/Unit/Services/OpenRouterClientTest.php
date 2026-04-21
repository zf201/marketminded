<?php

use App\Services\ChatResult;
use App\Services\OpenRouterClient;
use App\Services\UrlFetcher;
use Illuminate\Support\Facades\Http;

test('sends chat completion request', function () {
    Http::fake([
        'openrouter.ai/*' => Http::response([
            'choices' => [
                ['message' => ['role' => 'assistant', 'content' => 'Hello world']],
            ],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ]),
    ]);

    $client = new OpenRouterClient('sk-test', 'test-model', new UrlFetcher);
    $result = $client->chat([
        ['role' => 'user', 'content' => 'Hi'],
    ]);

    expect($result)->toBeInstanceOf(ChatResult::class);
    expect($result->data)->toBe('Hello world');
    expect($result->inputTokens)->toBe(10);
    expect($result->outputTokens)->toBe(5);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
            && $request->header('Authorization')[0] === 'Bearer sk-test'
            && $request['model'] === 'test-model'
            && $request['stream'] === false;
    });
});

test('handles tool call and executes submit tool', function () {
    Http::fake([
        'openrouter.ai/*' => Http::sequence()
            ->push([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_1',
                            'type' => 'function',
                            'function' => [
                                'name' => 'submit_positioning',
                                'arguments' => json_encode(['value_proposition' => 'We rock']),
                            ],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
            ]),
    ]);

    $client = new OpenRouterClient('sk-test', 'test-model', new UrlFetcher);
    $result = $client->chat(
        [['role' => 'user', 'content' => 'Analyze this brand']],
        [['type' => 'function', 'function' => ['name' => 'submit_positioning', 'parameters' => []]]],
    );

    expect($result)->toBeInstanceOf(ChatResult::class);
    expect($result->data)->toBe(['value_proposition' => 'We rock']);
    expect($result->inputTokens)->toBe(100);
});

test('executes fetch_url tool and continues loop', function () {
    Http::fake([
        'openrouter.ai/*' => Http::sequence()
            ->push([
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
                    'finish_reason' => 'tool_calls',
                ]],
                'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 20],
            ])
            ->push([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_2',
                            'type' => 'function',
                            'function' => [
                                'name' => 'submit_positioning',
                                'arguments' => json_encode(['value_proposition' => 'From URL']),
                            ],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 30],
            ]),
        'example.com' => Http::response('<html><head><title>Ex</title></head><body><p>Content</p></body></html>'),
    ]);

    $client = new OpenRouterClient('sk-test', 'test-model', new UrlFetcher);
    $result = $client->chat(
        [['role' => 'user', 'content' => 'Analyze']],
        [
            ['type' => 'function', 'function' => ['name' => 'submit_positioning', 'parameters' => []]],
            ['type' => 'function', 'function' => ['name' => 'fetch_url', 'parameters' => []]],
        ],
    );

    expect($result)->toBeInstanceOf(ChatResult::class);
    expect($result->data)->toBe(['value_proposition' => 'From URL']);
    expect($result->inputTokens)->toBe(150);
    expect($result->outputTokens)->toBe(50);
    expect($result->iterations)->toBe(2);
});

test('retries on 5xx errors with backoff', function () {
    Http::fake([
        'openrouter.ai/*' => Http::sequence()
            ->push('Server Error', 500)
            ->push('Server Error', 500)
            ->push([
                'choices' => [
                    ['message' => ['role' => 'assistant', 'content' => 'Recovered']],
                ],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ]),
    ]);

    $client = new OpenRouterClient('sk-test', 'test-model', new UrlFetcher);
    $result = $client->chat([['role' => 'user', 'content' => 'Hi']]);

    expect($result->data)->toBe('Recovered');
    Http::assertSentCount(3);
});

test('retries on 429 rate limit', function () {
    Http::fake([
        'openrouter.ai/*' => Http::sequence()
            ->push('Rate limited', 429)
            ->push([
                'choices' => [
                    ['message' => ['role' => 'assistant', 'content' => 'OK']],
                ],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ]),
    ]);

    $client = new OpenRouterClient('sk-test', 'test-model', new UrlFetcher);
    $result = $client->chat([['role' => 'user', 'content' => 'Hi']]);

    expect($result->data)->toBe('OK');
});

test('does not retry on 4xx client errors', function () {
    Http::fake([
        'openrouter.ai/*' => Http::response('Unauthorized', 401),
    ]);

    $client = new OpenRouterClient('sk-test', 'test-model', new UrlFetcher);

    expect(fn () => $client->chat([['role' => 'user', 'content' => 'Hi']]))
        ->toThrow(\RuntimeException::class, 'OpenRouter error 401');
});

test('throws after max retries exhausted', function () {
    Http::fake([
        'openrouter.ai/*' => Http::response('Server Error', 500),
    ]);

    $client = new OpenRouterClient('sk-test', 'test-model', new UrlFetcher);

    expect(fn () => $client->chat([['role' => 'user', 'content' => 'Hi']]))
        ->toThrow(\RuntimeException::class);
});

test('throws after max iterations in tool loop', function () {
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
            'finish_reason' => 'tool_calls',
        ]],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
    ];

    Http::fake([
        'openrouter.ai/*' => Http::response($toolCallResponse),
        'example.com' => Http::response('<html><head><title>Ex</title></head><body>content</body></html>'),
    ]);

    $client = new OpenRouterClient('sk-test', 'test-model', new UrlFetcher, maxIterations: 3);

    expect(fn () => $client->chat(
        [['role' => 'user', 'content' => 'Analyze']],
        [['type' => 'function', 'function' => ['name' => 'fetch_url', 'parameters' => []]]],
    ))->toThrow(\RuntimeException::class, 'Max tool iterations');
});

test('includes server tools in request', function () {
    Http::fake([
        'openrouter.ai/*' => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'OK']]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ]),
    ]);

    $client = new OpenRouterClient('sk-test', 'test-model', new UrlFetcher);
    $client->chat(
        [['role' => 'user', 'content' => 'Hi']],
        [['type' => 'function', 'function' => ['name' => 'my_tool', 'parameters' => []]]],
    );

    Http::assertSent(function ($request) {
        $tools = $request['tools'];
        $hasDatetime = collect($tools)->contains(fn ($t) => ($t['type'] ?? '') === 'openrouter:datetime');
        $hasWebSearch = collect($tools)->contains(fn ($t) => ($t['type'] ?? '') === 'openrouter:web_search');

        return $hasDatetime && $hasWebSearch;
    });
});
