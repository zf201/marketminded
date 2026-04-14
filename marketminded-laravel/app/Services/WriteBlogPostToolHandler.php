<?php

namespace App\Services;

use App\Models\ContentPiece;
use App\Models\Conversation;
use App\Models\Team;
use App\Services\Writer\Agent;
use App\Services\Writer\Agents\WriterAgent;
use App\Services\Writer\Brief;

class WriteBlogPostToolHandler
{
    public function __construct(private ?Agent $agent = null) {}

    public function execute(Team $team, int $conversationId, array $args, array $priorTurnTools = []): string
    {
        $callsSoFar = collect($priorTurnTools)->where('name', 'write_blog_post')->count();
        if ($callsSoFar >= 1) {
            return json_encode([
                'status' => 'error',
                'message' => 'Already retried write_blog_post this turn. Get help from the user.',
            ]);
        }

        $conversation = Conversation::findOrFail($conversationId);
        $brief = Brief::fromJson($conversation->brief ?? []);

        $extraContext = $args['extra_context'] ?? null;
        $agent = $extraContext !== null ? new WriterAgent($extraContext) : ($this->agent ?? new WriterAgent);

        try {
            $result = $agent->execute($brief, $team);
        } catch (\Throwable $e) {
            return json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }

        if (! $result->isOk()) {
            return json_encode(['status' => 'error', 'message' => $result->errorMessage]);
        }

        // Patch conversation_id onto the piece (the agent didn't know it).
        if ($pieceId = $result->brief->contentPieceId()) {
            ContentPiece::where('id', $pieceId)->update(['conversation_id' => $conversation->id]);
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
                'name' => 'write_blog_post',
                'description' => 'Run the Writer sub-agent. Requires brief.research and brief.outline. Creates the ContentPiece and writes brief.content_piece_id.',
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
