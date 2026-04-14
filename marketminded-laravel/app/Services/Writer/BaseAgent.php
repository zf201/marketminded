<?php

namespace App\Services\Writer;

use App\Models\Team;
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

        $payload = $this->llmCall(
            $systemPrompt,
            array_merge([$this->submitToolSchema()], $this->additionalTools()),
            $model,
            $this->temperature(),
            $this->useServerTools(),
            $team->openrouter_api_key,
            $this->timeout(),
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
    protected float $lastCost = 0.0;

    protected function llmCall(
        string $systemPrompt,
        array $tools,
        string $model,
        float $temperature,
        bool $useServerTools,
        ?string $apiKey,
        int $timeout = 120,
    ): ?array {
        $client = new OpenRouterClient(
            apiKey: $apiKey,
            model: $model,
            urlFetcher: new UrlFetcher,
            maxIterations: 10,
        );

        // Sub-agents need a user turn to actually act — most providers will
        // just acknowledge a system-only message without invoking a tool.
        // The system prompt tells them WHAT and HOW; this user message is
        // the "go" signal that triggers the required submit_* tool call.
        // Constrain tool_choice to eliminate the "I'll do X..." prose failure
        // mode. If the agent has server tools (web_search), we use "required"
        // so the model can still call web_search before converging on the
        // submit tool. Otherwise we force the specific submit function.
        $submitToolName = $tools[0]['function']['name'] ?? 'submit';
        $toolChoice = $useServerTools
            ? 'required'
            : [
                'type' => 'function',
                'function' => ['name' => $submitToolName],
            ];

        $result = $client->chat(
            messages: [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => 'Proceed now. Produce your output by calling ' . $submitToolName . ' with all required fields.'],
            ],
            tools: $tools,
            toolChoice: $toolChoice,
            temperature: $temperature,
            useServerTools: $useServerTools,
            timeout: $timeout,
        );

        $this->lastInputTokens = $result->inputTokens;
        $this->lastOutputTokens = $result->outputTokens;
        $this->lastCost = $result->cost;

        if (is_array($result->data)) {
            $this->lastTextResponse = null;
            return $result->data;
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
