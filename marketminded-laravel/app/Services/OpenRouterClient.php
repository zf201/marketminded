<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class OpenRouterClient
{
    private const API_URL = 'https://openrouter.ai/api/v1/chat/completions';

    private const MAX_RETRIES = 3;

    private const SERVER_TOOLS = [
        ['type' => 'openrouter:datetime'],
        ['type' => 'openrouter:web_search', 'parameters' => ['max_results' => 5]],
    ];

    public function __construct(
        private string $apiKey,
        private string $model,
        private UrlFetcher $urlFetcher,
        private int $maxIterations = 20,
    ) {}

    public function getModel(): string
    {
        return $this->model;
    }

    public function chat(array $messages, array $tools = [], ?string $toolChoice = null, float $temperature = 0.3, bool $useServerTools = true): ChatResult
    {
        $allTools = $useServerTools ? array_merge(self::SERVER_TOOLS, $tools) : $tools;
        $iteration = 0;
        $totalInputTokens = 0;
        $totalOutputTokens = 0;
        $totalCost = 0.0;

        while ($iteration < $this->maxIterations) {
            $iteration++;

            $body = [
                'model' => $this->model,
                'messages' => $messages,
                'temperature' => $temperature,
                'stream' => false,
            ];

            if (! empty($allTools)) {
                $body['tools'] = $allTools;
            }

            if ($toolChoice !== null) {
                $body['tool_choice'] = $toolChoice;
            }

            $response = $this->sendWithRetry($body);
            $usage = $response['usage'] ?? [];
            $totalInputTokens += $usage['prompt_tokens'] ?? 0;
            $totalOutputTokens += $usage['completion_tokens'] ?? 0;
            $totalCost += (float) ($usage['cost'] ?? 0);
            $choice = $response['choices'][0]['message'];

            $messages[] = $choice;

            if (empty($choice['tool_calls'])) {
                return new ChatResult(
                    data: $this->normalizeContent($choice['content'] ?? ''),
                    inputTokens: $totalInputTokens,
                    outputTokens: $totalOutputTokens,
                    cost: $totalCost,
                    iterations: $iteration,
                );
            }

            foreach ($choice['tool_calls'] as $toolCall) {
                $functionName = $toolCall['function']['name'];
                $arguments = json_decode($toolCall['function']['arguments'], true) ?? [];

                if (str_starts_with($functionName, 'submit_')) {
                    return new ChatResult(
                        data: $arguments,
                        inputTokens: $totalInputTokens,
                        outputTokens: $totalOutputTokens,
                        cost: $totalCost,
                        iterations: $iteration,
                    );
                }

                // Server-side tools are handled by OpenRouter — skip execution
                if (str_starts_with($functionName, 'openrouter:')) {
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCall['id'],
                        'content' => 'Handled by server.',
                    ];

                    continue;
                }

                if ($functionName === 'fetch_url') {
                    $toolResult = $this->urlFetcher->fetch($arguments['url'] ?? '');
                } else {
                    $toolResult = "Unknown tool: {$functionName}";
                }

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall['id'],
                    'content' => $toolResult,
                ];
            }
        }

        throw new \RuntimeException("Max tool iterations ({$this->maxIterations}) reached without submit tool call");
    }

    /**
     * Stream a chat completion. Yields string chunks, then a StreamResult as the final value.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return \Generator<int, string|StreamResult>
     */
    public function streamChat(string $systemPrompt, array $messages, float $temperature = 0.7): \Generator
    {
        $allMessages = array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $messages,
        );

        $body = [
            'model' => $this->model,
            'messages' => $allMessages,
            'temperature' => $temperature,
            'stream' => true,
        ];

        $response = Http::timeout(120)
            ->withHeader('Authorization', "Bearer {$this->apiKey}")
            ->withOptions(['stream' => true])
            ->post(self::API_URL, $body);

        $fullContent = '';
        $inputTokens = 0;
        $outputTokens = 0;
        $cost = 0.0;
        $buffer = '';

        $body = $response->getBody();

        while (! $body->eof()) {
            $buffer .= $body->read(1024);

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);

                $line = trim($line);

                if ($line === '' || $line === 'data: [DONE]') {
                    continue;
                }

                if (! str_starts_with($line, 'data: ')) {
                    continue;
                }

                $json = json_decode(substr($line, 6), true);

                if (! $json) {
                    continue;
                }

                $delta = $json['choices'][0]['delta'] ?? [];
                $content = $delta['content'] ?? '';

                if ($content !== '') {
                    $fullContent .= $content;
                    yield $content;
                }

                // Usage comes on the final chunk
                if (isset($json['usage'])) {
                    $inputTokens = $json['usage']['prompt_tokens'] ?? 0;
                    $outputTokens = $json['usage']['completion_tokens'] ?? 0;
                    $cost = (float) ($json['usage']['cost'] ?? 0);
                }
            }
        }

        yield new StreamResult(
            content: $fullContent,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            cost: $cost,
        );
    }

    /**
     * Stream a chat completion with tool support.
     * Yields string chunks, ToolEvent objects, and a final StreamResult.
     *
     * @return \Generator<int, string|ToolEvent|StreamResult>
     */
    public function streamChatWithTools(
        string $systemPrompt,
        array $messages,
        array $tools = [],
        ?callable $toolExecutor = null,
        float $temperature = 0.7,
        bool $useServerTools = true,
    ): \Generator {
        $allMessages = array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $messages,
        );
        $allTools = $useServerTools ? array_merge(self::SERVER_TOOLS, $tools) : $tools;

        $totalInputTokens = 0;
        $totalOutputTokens = 0;
        $totalCost = 0.0;
        $webSearchRequests = 0;
        $fullContent = '';

        for ($iteration = 0; $iteration < $this->maxIterations; $iteration++) {
            $body = [
                'model' => $this->model,
                'messages' => $allMessages,
                'temperature' => $temperature,
                'stream' => true,
            ];

            if (! empty($allTools)) {
                $body['tools'] = $allTools;
            }

            $response = Http::timeout(120)
                ->withHeader('Authorization', "Bearer {$this->apiKey}")
                ->withOptions(['stream' => true])
                ->post(self::API_URL, $body);

            $contentType = $response->header('Content-Type') ?? '';
            $isStream = str_contains($contentType, 'text/event-stream');

            // Non-streaming response (tool call)
            if (! $isStream) {
                $json = $response->json();
                $usage = $json['usage'] ?? [];
                $totalInputTokens += $usage['prompt_tokens'] ?? 0;
                $totalOutputTokens += $usage['completion_tokens'] ?? 0;
                $totalCost += (float) ($usage['cost'] ?? 0);
                $webSearchRequests += $usage['server_tool_use']['web_search_requests'] ?? 0;

                $choice = $json['choices'][0]['message'] ?? [];
                $allMessages[] = $choice;

                if (! empty($choice['tool_calls'])) {
                    foreach ($choice['tool_calls'] as $toolCall) {
                        $fnName = $toolCall['function']['name'];
                        $fnArgs = json_decode($toolCall['function']['arguments'], true) ?? [];

                        if (str_starts_with($fnName, 'openrouter:')) {
                            $allMessages[] = [
                                'role' => 'tool',
                                'tool_call_id' => $toolCall['id'],
                                'content' => 'Handled by server.',
                            ];

                            continue;
                        }

                        yield new ToolEvent($fnName, $fnArgs, null, 'started');

                        if ($toolExecutor) {
                            $toolResult = $toolExecutor($fnName, $fnArgs);
                        } elseif ($fnName === 'fetch_url') {
                            $toolResult = $this->urlFetcher->fetch($fnArgs['url'] ?? '');
                        } else {
                            $toolResult = "Unknown tool: {$fnName}";
                        }

                        yield new ToolEvent($fnName, $fnArgs, $toolResult, 'completed');

                        $allMessages[] = [
                            'role' => 'tool',
                            'tool_call_id' => $toolCall['id'],
                            'content' => $toolResult,
                        ];
                    }

                    continue;
                }

                $content = $this->normalizeContent($choice['content'] ?? '');
                if ($content !== '') {
                    $fullContent .= $content;
                    yield $content;
                }

                break;
            }

            // Streaming SSE response
            $buffer = '';
            $streamBody = $response->getBody();
            $hasToolCalls = false;
            $streamToolCalls = [];
            $streamContent = '';

            while (! $streamBody->eof()) {
                $buffer .= $streamBody->read(1024);

                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);
                    $line = trim($line);

                    if ($line === '' || $line === 'data: [DONE]') {
                        continue;
                    }

                    if (! str_starts_with($line, 'data: ')) {
                        continue;
                    }

                    $json = json_decode(substr($line, 6), true);
                    if (! $json) {
                        continue;
                    }

                    $delta = $json['choices'][0]['delta'] ?? [];

                    $content = $this->normalizeContent($delta['content'] ?? '');
                    if ($content !== '') {
                        $fullContent .= $content;
                        $streamContent .= $content;
                        yield $content;
                    }

                    if (isset($delta['tool_calls'])) {
                        $hasToolCalls = true;
                        foreach ($delta['tool_calls'] as $tc) {
                            $idx = $tc['index'] ?? 0;
                            if (! isset($streamToolCalls[$idx])) {
                                $streamToolCalls[$idx] = ['id' => '', 'name' => '', 'arguments' => ''];
                            }
                            if (isset($tc['id']) && $tc['id'] !== '') {
                                $streamToolCalls[$idx]['id'] = $tc['id'];
                            }
                            if (isset($tc['function']['name']) && $tc['function']['name'] !== '') {
                                $streamToolCalls[$idx]['name'] = $tc['function']['name'];
                            }
                            if (isset($tc['function']['arguments'])) {
                                $streamToolCalls[$idx]['arguments'] .= $tc['function']['arguments'];
                            }
                        }
                    }

                    if (isset($json['usage'])) {
                        $totalInputTokens += $json['usage']['prompt_tokens'] ?? 0;
                        $totalOutputTokens += $json['usage']['completion_tokens'] ?? 0;
                        $totalCost += (float) ($json['usage']['cost'] ?? 0);
                        $webSearchRequests += $json['usage']['server_tool_use']['web_search_requests'] ?? 0;
                    }
                }
            }

            if ($hasToolCalls && ! empty($streamToolCalls)) {
                $assistantMsg = ['role' => 'assistant', 'content' => $streamContent ?: null, 'tool_calls' => []];
                foreach ($streamToolCalls as $tc) {
                    $assistantMsg['tool_calls'][] = [
                        'id' => $tc['id'],
                        'type' => 'function',
                        'function' => ['name' => $tc['name'], 'arguments' => $tc['arguments']],
                    ];
                }
                $allMessages[] = $assistantMsg;

                foreach ($streamToolCalls as $tc) {
                    $fnName = $tc['name'];
                    $fnArgs = json_decode($tc['arguments'], true) ?? [];

                    if (str_starts_with($fnName, 'openrouter:')) {
                        $allMessages[] = [
                            'role' => 'tool',
                            'tool_call_id' => $tc['id'],
                            'content' => 'Handled by server.',
                        ];

                        continue;
                    }

                    yield new ToolEvent($fnName, $fnArgs, null, 'started');

                    if ($toolExecutor) {
                        $toolResult = $toolExecutor($fnName, $fnArgs);
                    } elseif ($fnName === 'fetch_url') {
                        $toolResult = $this->urlFetcher->fetch($fnArgs['url'] ?? '');
                    } else {
                        $toolResult = "Unknown tool: {$fnName}";
                    }

                    yield new ToolEvent($fnName, $fnArgs, $toolResult, 'completed');

                    $allMessages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $tc['id'],
                        'content' => $toolResult,
                    ];
                }

                continue;
            }

            break;
        }

        yield new StreamResult(
            content: $fullContent,
            inputTokens: $totalInputTokens,
            outputTokens: $totalOutputTokens,
            cost: $totalCost,
            webSearchRequests: $webSearchRequests,
        );
    }

    /**
     * Normalize content from API response — handles both string and array-of-blocks formats.
     */
    private function normalizeContent(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        if (is_array($content)) {
            return collect($content)
                ->filter(fn ($block) => is_array($block) && ($block['type'] ?? '') === 'text')
                ->pluck('text')
                ->implode('');
        }

        return '';
    }

    private function sendWithRetry(array $body): array
    {
        $lastException = null;

        for ($attempt = 0; $attempt < self::MAX_RETRIES; $attempt++) {
            if ($attempt > 0) {
                $delay = pow(2, $attempt - 1);
                sleep($delay);
            }

            try {
                $response = Http::timeout(120)
                    ->withHeader('Authorization', "Bearer {$this->apiKey}")
                    ->post(self::API_URL, $body);

                if ($response->successful()) {
                    return $response->json();
                }

                $status = $response->status();

                if ($status >= 400 && $status < 500 && $status !== 429) {
                    throw new \RuntimeException("OpenRouter error {$status}: {$response->body()}");
                }

                $lastException = new \RuntimeException("OpenRouter error {$status}: {$response->body()}");
            } catch (\RuntimeException $e) {
                if (! str_contains($e->getMessage(), 'OpenRouter error 4') || str_contains($e->getMessage(), 'OpenRouter error 429')) {
                    $lastException = $e;
                    continue;
                }
                throw $e;
            }
        }

        throw $lastException ?? new \RuntimeException('OpenRouter request failed after max retries');
    }
}
