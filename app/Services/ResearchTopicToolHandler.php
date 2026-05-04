<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Team;
use App\Services\Writer\Agents\ResearchAgent;
use App\Services\Writer\Brief;

class ResearchTopicToolHandler
{
    public function __construct(private ?ResearchAgent $agent = null) {}

    public function execute(Team $team, int $conversationId, array $args, array $priorTurnTools = [], ?ConversationBus $bus = null): string
    {
        $callsSoFar = collect($priorTurnTools)->where('name', 'research_topic')->where('status', 'ok')->count();
        if ($callsSoFar >= 1) {
            return json_encode([
                'status' => 'error',
                'message' => 'Already retried research_topic this turn. Get help from the user.',
            ]);
        }

        $conversation = Conversation::findOrFail($conversationId);
        $brief = Brief::fromJson($conversation->brief ?? []);

        $extraContext = $args['extra_context'] ?? null;
        $agent = $extraContext !== null ? new ResearchAgent($extraContext) : ($this->agent ?? new ResearchAgent);
        $agent->conversationId = $conversationId;
        $agent->bus = $bus;

        try {
            $result = $agent->execute($brief, $team);
        } catch (TurnStoppedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }

        if (! $result->isOk()) {
            return json_encode(['status' => 'error', 'message' => $result->errorMessage]);
        }

        $conversation->update(['brief' => $result->brief->toJson()]);

        return json_encode([
            'status' => 'ok',
            'summary' => $result->summary,
            'card' => $result->cardPayload,
        ]);
    }

    public static function toolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'research_topic',
                'description' => 'Run the Research sub-agent. Reads brief.topic; writes brief.research with structured claims sourced via web search.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'extra_context' => [
                            'type' => 'string',
                            'description' => 'Optional guidance for the sub-agent on retry.',
                        ],
                    ],
                ],
            ],
        ];
    }
}
