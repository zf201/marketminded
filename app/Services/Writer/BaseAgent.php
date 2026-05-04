<?php

namespace App\Services\Writer;

use App\Models\Team;
use App\Services\BraveSearchClient;
use App\Services\OpenRouterClient;
use App\Services\SubagentLogger;
use App\Services\UrlFetcher;
use Illuminate\Support\Facades\Cache;

abstract class BaseAgent implements Agent
{
    public function __construct(protected ?string $extraContext = null) {}

    /** Set by handlers before execute() so log entries can be correlated with the chat turn. */
    public ?int $conversationId = null;

    /** Correlation id for the current execute() invocation; used by llmCall logging. */
    protected ?string $currentCallId = null;

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
        $callId = SubagentLogger::newCallId();
        $this->currentCallId = $callId;
        $startedAt = microtime(true);
        $systemPrompt = $this->systemPrompt($brief, $team);
        $submitToolName = $this->submitToolSchema()['function']['name'] ?? '?';
        $model = $this->model($team);

        SubagentLogger::write([
            'event' => 'start',
            'call_id' => $callId,
            'conversation_id' => $this->conversationId,
            'agent' => static::class,
            'model' => $model,
            'submit_tool' => $submitToolName,
            'system_prompt' => mb_substr($systemPrompt, 0, 8000),
            'system_prompt_length' => strlen($systemPrompt),
            'team_id' => $team->id,
            'team_provider' => $team->ai_provider ?? 'openrouter',
            'team_api_url' => $team->ai_api_url,
            'extra_context' => $this->extraContext,
            'pid' => getmypid(),
        ]);

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

        $this->lastIntermediateTools = [];
        $conversationId = $this->conversationId;
        if ($conversationId !== null) {
            Cache::put("subagent-active:{$conversationId}", $callId, 1800);
        }

        $onToolCall = function (string $name, array $args) use ($callId, $conversationId): void {
            $entry = ['name' => $name, 'args' => $args, 'ts' => time()];
            $this->lastIntermediateTools[] = $entry;
            if ($conversationId !== null) {
                $key = "subagent-tools:{$callId}:{$conversationId}";
                Cache::put($key, $this->lastIntermediateTools, 1800);
            }
        };

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
            $onToolCall,
        );

        if ($conversationId !== null) {
            Cache::forget("subagent-active:{$conversationId}");
        }

        $duration = (int) round((microtime(true) - $startedAt) * 1000);

        $outcome = $payload === null ? 'text_only' : 'submitted';
        $validationError = null;
        if ($payload !== null) {
            $validationError = $this->validate($payload);
            if ($validationError !== null) {
                $outcome = 'validation_failed';
            }
        }

        SubagentLogger::write([
            'event' => 'end',
            'call_id' => $callId,
            'conversation_id' => $this->conversationId,
            'agent' => static::class,
            'model' => $model,
            'submit_tool' => $submitToolName,
            'outcome' => $outcome,
            'duration_ms' => $duration,
            'attempts' => $this->lastAttempts,
            'input_tokens' => $this->lastInputTokens,
            'output_tokens' => $this->lastOutputTokens,
            'reasoning_tokens' => $this->lastReasoningTokens,
            'cost' => $this->lastCost,
            'reasoning_excerpt' => $this->lastReasoningContent !== ''
                ? mb_substr($this->lastReasoningContent, 0, 2000) : null,
            'text_response' => $this->lastTextResponse !== null
                ? mb_substr($this->lastTextResponse, 0, 2000) : null,
            'submit_payload_keys' => is_array($payload) ? array_keys($payload) : null,
            'submit_payload_size' => is_array($payload) ? strlen(json_encode($payload)) : null,
            'validation_error' => $validationError,
            'transport_error' => $this->lastTransportError,
        ]);

        if ($payload === null) {
            $hint = $this->lastTextResponse !== null
                ? ' Model said: "' . mb_substr($this->lastTextResponse, 0, 300) . '"'
                : '';
            return AgentResult::error("Sub-agent ({$submitToolName}) did not call the submit tool.{$hint}");
        }

        if ($validationError !== null) {
            return AgentResult::error($validationError);
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
        if (! empty($this->lastIntermediateTools)) {
            $card['intermediate_tools'] = $this->lastIntermediateTools;
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

    /** Intermediate tool calls made during this agent's run (web_search, fetch_url, etc.). */
    protected array $lastIntermediateTools = [];

    /** Populated after llmCall so handlers can surface cost/tokens on cards. */
    protected int $lastInputTokens = 0;
    protected int $lastOutputTokens = 0;
    protected int $lastReasoningTokens = 0;
    protected float $lastCost = 0.0;
    protected string $lastReasoningContent = '';
    protected int $lastAttempts = 0;
    protected ?string $lastTransportError = null;

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
        ?callable $onToolCall = null,
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
        $this->lastAttempts = 0;
        $this->lastTransportError = null;

        SubagentLogger::write([
            'event' => 'attempt',
            'call_id' => $this->currentCallId ?? null,
            'conversation_id' => $this->conversationId,
            'agent' => static::class,
            'attempt_number' => 1,
            'model' => $model,
            'tools_offered' => array_map(fn ($t) => $t['function']['name'] ?? '?', $tools),
            'use_server_tools' => $useServerTools,
            'timeout_s' => $timeout,
        ]);
        $this->lastAttempts = 1;
        $attemptStart = microtime(true);

        try {
            $result = $client->chat(
                messages: [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => 'Proceed now. Call ' . $submitToolName . ' with all required fields. If uncertain about any field, call the tool with best-effort values — do not ask for clarification or respond with text.'],
                ],
                tools: $tools,
                toolChoice: null,
                temperature: $temperature,
                useServerTools: $useServerTools,
                timeout: $timeout,
                onToolCall: $onToolCall,
            );
        } catch (\Throwable $e) {
            $this->lastTransportError = mb_substr($e->getMessage(), 0, 1000);
            SubagentLogger::write([
                'event' => 'attempt_failed',
                'call_id' => $this->currentCallId ?? null,
                'conversation_id' => $this->conversationId,
                'agent' => static::class,
                'attempt_number' => 1,
                'duration_ms' => (int) round((microtime(true) - $attemptStart) * 1000),
                'error' => $this->lastTransportError,
                'exception_class' => get_class($e),
            ]);
            return null;
        }

        SubagentLogger::write([
            'event' => 'attempt_done',
            'call_id' => $this->currentCallId ?? null,
            'conversation_id' => $this->conversationId,
            'agent' => static::class,
            'attempt_number' => 1,
            'duration_ms' => (int) round((microtime(true) - $attemptStart) * 1000),
            'got_tool_call' => is_array($result->data),
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'reasoning_tokens' => $result->reasoningTokens,
        ]);

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
                'content' => 'You responded with text instead of calling ' . $submitToolName . '. Call ' . $submitToolName . ' now with all required fields. Use what you already gathered — do not ask for clarification or explain anything, just call the tool.',
            ];

            SubagentLogger::write([
                'event' => 'attempt',
                'call_id' => $this->currentCallId,
                'conversation_id' => $this->conversationId,
                'agent' => static::class,
                'attempt_number' => 2,
                'reason' => 'first attempt returned text only',
            ]);
            $this->lastAttempts = 2;
            $retryStart = microtime(true);

            try {
                $retry = $client->chat(
                    messages: $retryMessages,
                    tools: $tools,
                    toolChoice: null,
                    temperature: $temperature,
                    useServerTools: false,
                    timeout: $timeout,
                    onToolCall: $onToolCall,
                );
            } catch (\Throwable $e) {
                $this->lastTransportError = mb_substr($e->getMessage(), 0, 1000);
                SubagentLogger::write([
                    'event' => 'attempt_failed',
                    'call_id' => $this->currentCallId,
                    'conversation_id' => $this->conversationId,
                    'agent' => static::class,
                    'attempt_number' => 2,
                    'duration_ms' => (int) round((microtime(true) - $retryStart) * 1000),
                    'error' => $this->lastTransportError,
                    'exception_class' => get_class($e),
                ]);
                $this->lastTextResponse = is_string($result->data) ? $result->data : null;
                return null;
            }

            SubagentLogger::write([
                'event' => 'attempt_done',
                'call_id' => $this->currentCallId,
                'conversation_id' => $this->conversationId,
                'agent' => static::class,
                'attempt_number' => 2,
                'duration_ms' => (int) round((microtime(true) - $retryStart) * 1000),
                'got_tool_call' => is_array($retry->data),
            ]);

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
