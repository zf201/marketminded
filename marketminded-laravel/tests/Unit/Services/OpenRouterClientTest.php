<?php

use App\Services\OpenRouterClient;
use App\Services\UrlFetcher;
use Illuminate\Support\Facades\Http;

test('sends chat completion request', function () {
    Http::fake([
        'openrouter.ai/*' => Http::response([
            'choices' => [
                ['message' => ['role' => 'assistant', 'content' => 'Hello world']],
            ],
        ]),
    ]);

    $client = new OpenRouterClient('sk-test', 'test-model', new UrlFetcher);
    $result = $client->chat([
        ['role' => 'user', 'content' => 'Hi'],
    ]);

    expect($result)->toBe('Hello world');

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
            ]),
    ]);

    $client = new OpenRouterClient('sk-test', 'test-model', new UrlFetcher);
    $result = $client->chat(
        [['role' => 'user', 'content' => 'Analyze this brand']],
        [['type' => 'function', 'function' => ['name' => 'submit_positioning', 'parameters' => []]]],
    );

    expect($result)->toBe(['value_proposition' => 'We rock']);
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

    expect($result)->toBe(['value_proposition' => 'From URL']);
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
            ]),
    ]);

    $client = new OpenRouterClient('sk-test', 'test-model', new UrlFetcher);
    $result = $client->chat([['role' => 'user', 'content' => 'Hi']]);

    expect($result)->toBe('Recovered');
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
            ]),
    ]);

    $client = new OpenRouterClient('sk-test', 'test-model', new UrlFetcher);
    $result = $client->chat([['role' => 'user', 'content' => 'Hi']]);

    expect($result)->toBe('OK');
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
