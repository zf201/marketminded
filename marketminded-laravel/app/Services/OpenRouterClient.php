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

    public function chat(array $messages, array $tools = [], ?string $toolChoice = null, float $temperature = 0.3): mixed
    {
        $allTools = array_merge(self::SERVER_TOOLS, $tools);
        $iteration = 0;

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
            $choice = $response['choices'][0]['message'];

            $messages[] = $choice;

            if (empty($choice['tool_calls'])) {
                return $choice['content'] ?? '';
            }

            foreach ($choice['tool_calls'] as $toolCall) {
                $functionName = $toolCall['function']['name'];
                $arguments = json_decode($toolCall['function']['arguments'], true) ?? [];

                if (str_starts_with($functionName, 'submit_')) {
                    return $arguments;
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
