<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Team;
use App\Services\Writer\Agent;
use App\Services\Writer\Agents\AudiencePickerAgent;
use App\Services\Writer\Brief;

class PickAudienceToolHandler
{
    public function __construct(private ?Agent $agent = null) {}

    public function execute(Team $team, int $conversationId, array $args, array $priorTurnTools = []): string
    {
        if (! $team->audiencePersonas()->exists()) {
            return json_encode([
                'status' => 'skipped',
                'reason' => 'No personas configured for this team.',
            ]);
        }

        $callsSoFar = collect($priorTurnTools)->where('name', 'pick_audience')->where('status', 'ok')->count();
        if ($callsSoFar >= 1) {
            $conversation = Conversation::findOrFail($conversationId);
            $brief = Brief::fromJson($conversation->brief ?? []);
            $audience = $brief->audience();
            return json_encode([
                'status' => 'ok',
                'summary' => 'Audience already selected this turn',
                'card' => [
                    'kind' => 'audience',
                    'summary' => 'Audience already selected this turn',
                    'mode' => $audience['mode'] ?? 'unknown',
                    'guidance_for_writer' => $audience['guidance_for_writer'] ?? '',
                ],
            ]);
        }

        $conversation = Conversation::findOrFail($conversationId);
        $brief = Brief::fromJson($conversation->brief ?? []);

        $extraContext = $args['extra_context'] ?? null;
        $agent = $extraContext !== null ? new AudiencePickerAgent($extraContext) : ($this->agent ?? new AudiencePickerAgent);

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
                'name' => 'pick_audience',
                'description' => 'Run the AudiencePicker sub-agent. Reads brief.research + team personas; writes brief.audience with mode, persona selection, and writer guidance. Returns status=skipped if no personas are configured.',
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
