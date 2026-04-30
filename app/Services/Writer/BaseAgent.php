<?php

namespace App\Services\Writer;

use App\Models\Team;
use App\Services\BraveSearchClient;
use App\Services\OpenRouterClient;
use App\Services\UrlFetcher;

abstract class BaseAgent implements Agent
{
    public function __construct(protected ?string $extraContext = null) {}

    /**
     * Build the full system prompt for this agent's LLM call. Should embed
     * everything the LLM needs from the brief + team profile.
     */
    abstract protected function systemPrompt(Brief $brief, Team $team): string;

    /**
     * The OpenAI/OpenRouter function-calling schema for the submit_* tool
     * the LLM uses to deliver structured output.
     *
     * @return array<string, mixed>
     */
    abstract protected function submitToolSchema(): array;

    /**
     * Additional non-submit tools the agent can use during its turn (e.g.
     * fetch_url for brand_enricher). Return [] if none.
     *
     * @return array<int, array<string, mixed>>
     */
    abstract protected function additionalTools(): array;

    /**
     * Whether the agent should have OpenRouter's server-side web_search
     * tool available.
     */
    abstract protected function useServerTools(): bool;

    abstract protected function model(Team $team): string;

    abstract protected function temperature(): float;

    /** HTTP timeout for this agent's LLM call, in seconds. Override for long-running agents like Writer. */
    protected function timeout(): int
    {
        return 120;
    }

    /**
     * Validate the payload submitted via the submit tool.
     * Return null on success; an error message on failure.
     *
     * @param array<string, mixed> $payload
     */
    abstract protected function validate(array $payload): ?string;

    /**
     * Apply the validated payload to the brief, returning the new Brief.
     *
     * @param array<string, mixed> $payload
     */
    abstract protected function applyToBrief(Brief $brief, array $payload, Team $team): Brief;

    /**
     * Build the small UI card payload returned to the orchestrator.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    abstract protected function buildCard(array $payload): array;

    /**
     * One-line factual summary returned to the orchestrator (e.g. "Gathered 13 claims").
     *
     * @param array<string, mixed> $payload
     */
    abstract protected function buildSummary(array $payload): string;

    public function execute(Brief $brief, Team $team): AgentResult
    {
        $systemPrompt = $this->systemPrompt($brief, $team);
        $submitToolName = $this->submitToolSchema()['function']['name'] ?? '?';
        $model = $this->model($team);

        $webSearchProvider = $team->web_search_provider ?? 'openrouter_builtin';
        $useServerTools = $this->useServerTools()
            && $team->ai_provider !== 'custom'
            && $webSearchProvider === 'openrouter_builtin';

        // Brave web search is only attached to agents that explicitly want web
        // access (useServerTools() === true). Agents like the Writer should
        // not be able to wander off and search the web — they work from the
        // research already gathered. Without this gate, deepseek-reasoner
        // burned the whole writer step calling brave_web_search in a loop
        // instead of submitting the post.
        $extraTools = [];
        $braveClient = null;
        if ($this->useServerTools() && $webSearchProvider === 'brave' && $team->brave_api_key) {
            $braveClient = new BraveSearchClient($team->brave_api_key);
            $extraTools[] = BraveSearchClient::toolSchema();
        }

        $payload = $this->llmCall(
            $systemPrompt,
            array_merge([$this->submitToolSchema()], $this->additionalTools(), $extraTools),
            $model,
            $this->temperature(),
            $useServerTools,
            $team->ai_api_key,
            $this->timeout(),
            $team->ai_api_url ?? 'https://openrouter.ai/api/v1',
            $team->ai_provider ?? 'openrouter',
            $braveClient,
        );

        // Append a sub-agent log line to storage/logs/agent-debug.log so we can
        // see what each sub-agent's LLM actually did. One JSON line per call.
        try {
            $logPath = storage_path('logs/agent-debug.log');
            file_put_contents(
                $logPath,
                json_encode([
                    'ts' => now()->toIso8601String(),
                    'agent' => static::class,
                    'model' => $model,
                    'submit_tool' => $submitToolName,
                    'system_prompt_length' => strlen($systemPrompt),
                    'result_is_array' => is_array($payload),
                    'result' => is_array($payload) ? $payload : null,
                    'text_response' => $this->lastTextResponse !== null ? mb_substr($this->lastTextResponse, 0, 2000) : null,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
                FILE_APPEND | LOCK_EX,
            );
        } catch (\Throwable) {
            // swallow — don't let logging break the agent
        }

        if ($payload === null) {
            $hint = $this->lastTextResponse !== null
                ? ' Model said: "' . mb_substr($this->lastTextResponse, 0, 300) . '"'
                : '';
            return AgentResult::error("Sub-agent ({$submitToolName}) did not call the submit tool.{$hint}");
        }

        $err = $this->validate($payload);
        if ($err !== null) {
            return AgentResult::error($err);
        }

        $newBrief = $this->applyToBrief($brief, $payload, $team);

        $card = $this->buildCard($payload);
        // Decorate every card with cost/token metadata so the chat UI can
        // render it consistently across agents. Subclasses don't need to
        // carry this — it's universal per sub-agent call.
        $card['cost'] = $this->lastCost;
        $card['input_tokens'] = $this->lastInputTokens;
        $card['output_tokens'] = $this->lastOutputTokens;
        $card['reasoning_tokens'] = $this->lastReasoningTokens;
        if ($this->lastReasoningContent !== '') {
            $card['reasoning'] = $this->lastReasoningContent;
        }

        return AgentResult::ok(
            brief: $newBrief,
            cardPayload: $card,
            summary: $this->buildSummary($payload),
        );
    }

    /**
     * Make the actual LLM call. Returns the args of the submit_* tool call,
     * or null if the LLM did not call it.
     *
     * Tests override this method to inject canned payloads without HTTP.
     *
     * @param array<int, array<string, mixed>> $tools
     * @return array<string, mixed>|null
     */
    /** Captured when llmCall's response is text (not a tool call) — used for diagnostics. */
    protected ?string $lastTextResponse = null;

    /** Populated after llmCall so handlers can surface cost/tokens on cards. */
    protected int $lastInputTokens = 0;
    protected int $lastOutputTokens = 0;
    protected int $lastReasoningTokens = 0;
    protected float $lastCost = 0.0;
    protected string $lastReasoningContent = '';

    protected function llmCall(
        string $systemPrompt,
        array $tools,
        string $model,
        float $temperature,
        bool $useServerTools,
        ?string $apiKey,
        int $timeout = 120,
        string $baseUrl = 'https://openrouter.ai/api/v1',
        string $provider = 'openrouter',
        ?BraveSearchClient $braveSearchClient = null,
    ): ?array {
        $client = new OpenRouterClient(
            apiKey: $apiKey,
            model: $model,
            urlFetcher: new UrlFetcher,
            maxIterations: 10,
            baseUrl: $baseUrl,
            provider: $provider,
            braveSearchClient: $braveSearchClient,
        );

        // Sub-agents need a user turn to actually act — most providers will
        // just acknowledge a system-only message without invoking a tool.
        // The system prompt tells them WHAT and HOW; this user message is
        // the "go" signal that triggers the submit_* tool call.
        //
        // We don't set tool_choice — reasoning models like deepseek-reasoner
        // reject any forced value, and the explicit "You MUST call X" in the
        // user message is enough for compliant models. If a model returns
        // text instead, the retry loop below tries once more.
        $submitToolName = $tools[0]['function']['name'] ?? 'submit';
        $hasFreeTools = $useServerTools || count($tools) > 1;

        $result = $client->chat(
            messages: [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => 'Proceed now. Produce your output by calling ' . $submitToolName . ' with all required fields. Do not respond with text.'],
            ],
            tools: $tools,
            toolChoice: null,
            temperature: $temperature,
            useServerTools: $useServerTools,
            timeout: $timeout,
        );

        $this->lastInputTokens = $result->inputTokens;
        $this->lastOutputTokens = $result->outputTokens;
        $this->lastReasoningTokens = $result->reasoningTokens;
        $this->lastCost = $result->cost;
        $this->lastReasoningContent = $result->reasoningContent;

        if (is_array($result->data)) {
            $this->lastTextResponse = null;
            return $result->data;
        }

        // The model returned text instead of calling the submit tool. Retry
        // once with the full conversation history (including any web search
        // results the model gathered) and a stronger instruction. We still
        // don't set tool_choice — see the note above.
        if ($hasFreeTools && ! empty($result->messages)) {
            $retryMessages = $result->messages;
            $retryMessages[] = [
                'role' => 'user',
                'content' => 'You responded with text instead of calling ' . $submitToolName . '. You MUST call ' . $submitToolName . ' now with all required fields. Use the information you already gathered.',
            ];

            $retry = $client->chat(
                messages: $retryMessages,
                tools: $tools,
                toolChoice: null,
                temperature: $temperature,
                useServerTools: false,
                timeout: $timeout,
            );

            $this->lastInputTokens += $retry->inputTokens;
            $this->lastOutputTokens += $retry->outputTokens;
            $this->lastReasoningTokens += $retry->reasoningTokens;
            $this->lastCost += $retry->cost;
            if ($retry->reasoningContent !== '') {
                $this->lastReasoningContent .= ($this->lastReasoningContent === '' ? '' : "\n\n--- retry ---\n\n")
                    . $retry->reasoningContent;
            }

            if (is_array($retry->data)) {
                $this->lastTextResponse = null;
                return $retry->data;
            }
        }

        $this->lastTextResponse = is_string($result->data) ? $result->data : null;
        return null;
    }

    protected function extraContextBlock(): string
    {
        if ($this->extraContext === null || $this->extraContext === '') {
            return '';
        }
        return "\n\n## Orchestrator guidance for this attempt\n{$this->extraContext}\n";
    }
}
