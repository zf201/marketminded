<?php

namespace App\Services;

use App\Services\ConversationBus;
use App\Services\StreamResult;
use Illuminate\Support\Facades\Http;

class OpenRouterClient
{
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
        private string $baseUrl = 'https://openrouter.ai/api/v1',
        private string $provider = 'openrouter',
        private ?BraveSearchClient $braveSearchClient = null,
    ) {}

    public function getModel(): string
    {
        return $this->model;
    }

    public function chat(array $messages, array $tools = [], string|array|null $toolChoice = null, float $temperature = 0.3, bool $useServerTools = true, int $timeout = 120, ?callable $onToolCall = null, ?callable $stopCheck = null): ChatResult
    {
        $allTools = $useServerTools ? array_merge(self::SERVER_TOOLS, $tools) : $tools;
        $iteration = 0;
        $totalInputTokens = 0;
        $totalOutputTokens = 0;
        $totalCost = 0.0;
        $totalReasoningTokens = 0;
        $totalCacheReadTokens = 0;
        $totalCacheWriteTokens = 0;
        $totalReasoningContent = '';

        while ($iteration < $this->maxIterations) {
            $iteration++;

            if ($stopCheck !== null) {
                ($stopCheck)();
            }

            $body = [
                'model'       => $this->model,
                'messages'    => $messages,
                'temperature' => $temperature,
                'stream'      => false,
                // reasoning_effort: controls thinking depth on reasoning models (o1, o3, DeepSeek-R1, etc.)
                // Ignored by non-reasoning models. 'medium' is the safe balanced default.
                // Future: make this configurable per team or per call.
                'reasoning_effort' => 'medium',
            ];

            if ($this->provider === 'openrouter') {
                // verbosity: OpenRouter-specific; controls response detail level.
                // Future: make this configurable per team.
                $body['verbosity'] = 'medium';
            }

            if (! empty($allTools)) {
                $body['tools'] = $allTools;
            }

            if ($toolChoice !== null) {
                $body['tool_choice'] = $toolChoice;
            }

            $response = $this->sendWithRetry($body, $timeout);

            if ($stopCheck !== null) {
                ($stopCheck)();
            }

            $usage = $response['usage'] ?? [];
            $totalInputTokens      += $usage['prompt_tokens'] ?? 0;
            $totalOutputTokens     += $usage['completion_tokens'] ?? 0;
            $totalCost             += (float) ($usage['cost'] ?? 0);
            $totalReasoningTokens  += $usage['completion_tokens_details']['reasoning_tokens'] ?? 0;
            $totalCacheReadTokens  += $usage['prompt_tokens_details']['cached_tokens'] ?? 0;
            $totalCacheWriteTokens += $usage['prompt_tokens_details']['cache_write_tokens'] ?? 0;
            $choice = $response['choices'][0]['message'];

            // Normalize array content blocks to string
            if (isset($choice['content']) && is_array($choice['content'])) {
                $choice['content'] = $this->normalizeContent($choice['content']);
            }

            // Accumulate the reasoning trace if the model returned one (DeepSeek-R1
            // and o1-class models put their chain-of-thought here). Tag each turn so
            // multi-iteration calls remain readable.
            if (! empty($choice['reasoning_content'])) {
                $totalReasoningContent .= ($totalReasoningContent === '' ? '' : "\n\n--- iteration {$iteration} ---\n\n")
                    . $choice['reasoning_content'];
            }

            $messages[] = $choice;

            if (empty($choice['tool_calls'])) {
                return new ChatResult(
                    data: $this->normalizeContent($choice['content'] ?? ''),
                    inputTokens: $totalInputTokens,
                    outputTokens: $totalOutputTokens,
                    cost: $totalCost,
                    iterations: $iteration,
                    messages: $messages,
                    reasoningTokens: $totalReasoningTokens,
                    cacheReadTokens: $totalCacheReadTokens,
                    cacheWriteTokens: $totalCacheWriteTokens,
                    reasoningContent: $totalReasoningContent,
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
                        messages: $messages,
                        reasoningTokens: $totalReasoningTokens,
                        cacheReadTokens: $totalCacheReadTokens,
                        cacheWriteTokens: $totalCacheWriteTokens,
                        reasoningContent: $totalReasoningContent,
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

                if ($onToolCall !== null) {
                    ($onToolCall)($functionName, $arguments);
                }

                if ($functionName === 'fetch_url') {
                    $toolResult = $this->urlFetcher->fetch($arguments['url'] ?? '');
                } elseif ($functionName === 'brave_web_search' && $this->braveSearchClient !== null) {
                    $toolResult = $this->braveSearchClient->search(
                        $arguments['query'] ?? '',
                        $arguments['country'] ?? null,
                    );
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
            'model'       => $this->model,
            'messages'    => $allMessages,
            'temperature' => $temperature,
            'stream'      => true,
            // reasoning_effort: see chat() method comment.
            'reasoning_effort' => 'medium',
        ];

        if ($this->provider === 'openrouter') {
            $body['verbosity'] = 'medium';
        }

        $response = Http::timeout(120)
            ->withHeader('Authorization', "Bearer {$this->apiKey}")
            ->withOptions(['stream' => true])
            ->post($this->baseUrl . '/chat/completions', $body);

        $fullContent = '';
        $inputTokens = 0;
        $outputTokens = 0;
        $cost = 0.0;
        $reasoningTokens = 0;
        $cacheReadTokens = 0;
        $cacheWriteTokens = 0;
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
                    $inputTokens      = $json['usage']['prompt_tokens'] ?? 0;
                    $outputTokens     = $json['usage']['completion_tokens'] ?? 0;
                    $cost             = (float) ($json['usage']['cost'] ?? 0);
                    $reasoningTokens  = $json['usage']['completion_tokens_details']['reasoning_tokens'] ?? 0;
                    $cacheReadTokens  = $json['usage']['prompt_tokens_details']['cached_tokens'] ?? 0;
                    $cacheWriteTokens = $json['usage']['prompt_tokens_details']['cache_write_tokens'] ?? 0;
                }
            }
        }

        yield new StreamResult(
            content: $fullContent,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            cost: $cost,
            reasoningTokens: $reasoningTokens,
            cacheReadTokens: $cacheReadTokens,
            cacheWriteTokens: $cacheWriteTokens,
        );
    }

    /**
     * Stream a chat completion with tool support.
     * Publishes text_chunk events to the bus as they arrive.
     * Returns a StreamResult with token counts and final content.
     */
    public function streamChatWithTools(
        string $systemPrompt,
        array $messages,
        array $tools = [],
        ?callable $toolExecutor = null,
        float $temperature = 0.7,
        bool $useServerTools = true,
        ?ConversationBus $bus = null,
    ): StreamResult {
        $allMessages = array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $messages,
        );
        $allTools = $useServerTools ? array_merge(self::SERVER_TOOLS, $tools) : $tools;

        $totalInputTokens = 0;
        $totalOutputTokens = 0;
        $totalCost = 0.0;
        $webSearchRequests = 0;
        $totalReasoningTokens = 0;
        $totalCacheReadTokens = 0;
        $totalCacheWriteTokens = 0;
        $totalReasoningContent = '';
        $fullContent = '';

        for ($iteration = 0; $iteration < $this->maxIterations; $iteration++) {
            $body = [
                'model'       => $this->model,
                'messages'    => $allMessages,
                'temperature' => $temperature,
                'stream'      => true,
                // reasoning_effort: see chat() method comment.
                'reasoning_effort' => 'medium',
            ];

            if ($this->provider === 'openrouter') {
                $body['verbosity'] = 'medium';
            }

            if (! empty($allTools)) {
                $body['tools'] = $allTools;
            }

            $response = Http::timeout(120)
                ->withHeader('Authorization', "Bearer {$this->apiKey}")
                ->withOptions(['stream' => true])
                ->post($this->baseUrl . '/chat/completions', $body);

            $status = $response->status();
            if ($status >= 400) {
                throw new \RuntimeException("API error {$status}: " . $response->body());
            }

            $contentType = $response->header('Content-Type') ?? '';
            $isStream = str_contains($contentType, 'text/event-stream');

            // Non-streaming response (tool call)
            if (! $isStream) {
                $json = $response->json();
                $usage = $json['usage'] ?? [];
                $totalInputTokens      += $usage['prompt_tokens'] ?? 0;
                $totalOutputTokens     += $usage['completion_tokens'] ?? 0;
                $totalCost             += (float) ($usage['cost'] ?? 0);
                $webSearchRequests     += $usage['server_tool_use']['web_search_requests'] ?? 0;
                $totalReasoningTokens  += $usage['completion_tokens_details']['reasoning_tokens'] ?? 0;
                $totalCacheReadTokens  += $usage['prompt_tokens_details']['cached_tokens'] ?? 0;
                $totalCacheWriteTokens += $usage['prompt_tokens_details']['cache_write_tokens'] ?? 0;

                $choice = $json['choices'][0]['message'] ?? [];

                // Normalize array content blocks to string before adding to history
                if (isset($choice['content']) && is_array($choice['content'])) {
                    $choice['content'] = $this->normalizeContent($choice['content']);
                }

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

                        if ($fnName === 'brave_web_search' && $this->braveSearchClient !== null) {
                            $bus?->publish('subagent_tool_call', ['agent' => 'main', 'name' => 'web search']);
                            $toolResult = $this->braveSearchClient->search(
                                $fnArgs['query'] ?? '',
                                $fnArgs['country'] ?? null,
                            );
                        } elseif ($toolExecutor) {
                            $toolResult = $toolExecutor($fnName, $fnArgs);
                        } elseif ($fnName === 'fetch_url') {
                            $bus?->publish('subagent_tool_call', ['agent' => 'main', 'name' => 'fetch url']);
                            $toolResult = $this->urlFetcher->fetch($fnArgs['url'] ?? '');
                        } else {
                            $toolResult = "Unknown tool: {$fnName}";
                        }

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
                    $bus?->publish('text_chunk', ['content' => $content]);
                }

                break;
            }

            // Streaming SSE response
            $buffer = '';
            $streamBody = $response->getBody();
            $hasToolCalls = false;
            $streamToolCalls = [];
            $streamContent = '';
            $streamReasoningContent = '';

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

                    if (isset($delta['reasoning_content']) && $delta['reasoning_content'] !== '') {
                        $streamReasoningContent .= $delta['reasoning_content'];
                    }

                    $content = $this->normalizeContent($delta['content'] ?? '');
                    if ($content !== '') {
                        $fullContent .= $content;
                        $streamContent .= $content;
                        $bus?->publish('text_chunk', ['content' => $content]);
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
                        $totalInputTokens      += $json['usage']['prompt_tokens'] ?? 0;
                        $totalOutputTokens     += $json['usage']['completion_tokens'] ?? 0;
                        $totalCost             += (float) ($json['usage']['cost'] ?? 0);
                        $webSearchRequests     += $json['usage']['server_tool_use']['web_search_requests'] ?? 0;
                        $totalReasoningTokens  += $json['usage']['completion_tokens_details']['reasoning_tokens'] ?? 0;
                        $totalCacheReadTokens  += $json['usage']['prompt_tokens_details']['cached_tokens'] ?? 0;
                        $totalCacheWriteTokens += $json['usage']['prompt_tokens_details']['cache_write_tokens'] ?? 0;
                    }
                }
            }

            if ($streamReasoningContent !== '') {
                $totalReasoningContent .= $streamReasoningContent;
            }

            if ($hasToolCalls && ! empty($streamToolCalls)) {
                $assistantMsg = ['role' => 'assistant', 'content' => $streamContent ?: null, 'tool_calls' => []];
                if ($streamReasoningContent !== '') {
                    $assistantMsg['reasoning_content'] = $streamReasoningContent;
                }
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

                    if ($fnName === 'brave_web_search' && $this->braveSearchClient !== null) {
                        $bus?->publish('subagent_tool_call', ['agent' => 'main', 'name' => 'web search']);
                        $toolResult = $this->braveSearchClient->search(
                            $fnArgs['query'] ?? '',
                            $fnArgs['country'] ?? null,
                        );
                    } elseif ($toolExecutor) {
                        $toolResult = $toolExecutor($fnName, $fnArgs);
                    } elseif ($fnName === 'fetch_url') {
                        $bus?->publish('subagent_tool_call', ['agent' => 'main', 'name' => 'fetch url']);
                        $toolResult = $this->urlFetcher->fetch($fnArgs['url'] ?? '');
                    } else {
                        $toolResult = "Unknown tool: {$fnName}";
                    }

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

        return new StreamResult(
            content: $fullContent,
            inputTokens: $totalInputTokens,
            outputTokens: $totalOutputTokens,
            cost: $totalCost,
            webSearchRequests: $webSearchRequests,
            reasoningTokens: $totalReasoningTokens,
            cacheReadTokens: $totalCacheReadTokens,
            cacheWriteTokens: $totalCacheWriteTokens,
            reasoningContent: $totalReasoningContent,
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

    private function sendWithRetry(array $body, int $timeout = 120): array
    {
        $lastException = null;

        for ($attempt = 0; $attempt < self::MAX_RETRIES; $attempt++) {
            if ($attempt > 0) {
                $delay = pow(2, $attempt - 1);
                sleep($delay);
            }

            try {
                $response = Http::timeout($timeout)
                    ->withHeader('Authorization', "Bearer {$this->apiKey}")
                    ->post($this->baseUrl . '/chat/completions', $body);

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
