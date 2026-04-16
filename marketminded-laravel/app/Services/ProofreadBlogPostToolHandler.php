<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Team;
use App\Services\Writer\Agent;
use App\Services\Writer\Agents\ProofreadAgent;
use App\Services\Writer\Brief;

class ProofreadBlogPostToolHandler
{
    public function __construct(private ?Agent $agent = null) {}

    public function execute(Team $team, int $conversationId, array $args, array $priorTurnTools = []): string
    {
        $conversation = Conversation::findOrFail($conversationId);

        $callsSoFar = collect($priorTurnTools)->where('name', 'proofread_blog_post')->where('status', 'ok')->count();
        if ($callsSoFar >= 1) {
            $brief = Brief::fromJson($conversation->brief ?? []);
            if ($brief->hasContentPiece()) {
                $piece = \App\Models\ContentPiece::where('id', $brief->contentPieceId())
                    ->where('team_id', $team->id)
                    ->first();
                if ($piece !== null) {
                    return json_encode([
                        'status' => 'ok',
                        'summary' => 'Revision already applied · v' . $piece->current_version,
                        'card' => [
                            'kind' => 'content_piece',
                            'summary' => 'Revision already applied · v' . $piece->current_version,
                            'title' => $piece->title,
                            'preview' => mb_substr(strip_tags($piece->body), 0, 200),
                            'change_description' => 'already applied this turn',
                        ],
                        'piece_id' => $piece->id,
                    ]);
                }
            }
        }

        $feedback = trim($args['feedback'] ?? '');
        if ($feedback === '') {
            return json_encode([
                'status' => 'error',
                'message' => 'feedback is required for proofread_blog_post.',
            ]);
        }

        $brief = Brief::fromJson($conversation->brief ?? [])
            ->withConversationId($conversation->id);

        $extraContext = $args['extra_context'] ?? null;
        $agent = $this->agent ?? new ProofreadAgent($feedback, $extraContext);

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
            'piece_id' => $result->brief->contentPieceId(),
        ]);
    }

    public static function toolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'proofread_blog_post',
                'description' => 'Run the Proofread sub-agent on the existing piece. Requires brief.content_piece_id and the user feedback distilled into one sentence.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['feedback'],
                    'properties' => [
                        'feedback' => [
                            'type' => 'string',
                            'description' => 'The user\'s requested change, distilled into one or two sentences.',
                        ],
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
