<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Team;
use App\Services\Writer\Agent;
use App\Services\Writer\Agents\EditorAgent;
use App\Services\Writer\Brief;

class CreateOutlineToolHandler
{
    public function __construct(private ?Agent $agent = null) {}

    public function execute(Team $team, int $conversationId, array $args, array $priorTurnTools = []): string
    {
        $callsSoFar = collect($priorTurnTools)->where('name', 'create_outline')->count();
        if ($callsSoFar >= 1) {
            return json_encode([
                'status' => 'error',
                'message' => 'Already retried create_outline this turn. Get help from the user.',
            ]);
        }

        $conversation = Conversation::findOrFail($conversationId);
        $brief = Brief::fromJson($conversation->brief ?? []);

        $extraContext = $args['extra_context'] ?? null;
        $agent = $extraContext !== null ? new EditorAgent($extraContext) : ($this->agent ?? new EditorAgent);

        try {
            $result = $agent->execute($brief, $team);
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
                'name' => 'create_outline',
                'description' => 'Run the Editor sub-agent. Reads brief.research; writes brief.outline. Requires brief.research.',
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
