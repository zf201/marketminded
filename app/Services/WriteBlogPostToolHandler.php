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

    public function execute(Team $team, int $conversationId, array $args, array $priorTurnTools = [], ?ConversationBus $bus = null): string
    {
        $conversation = Conversation::findOrFail($conversationId);

        // Idempotent path: if this turn already tried write_blog_post successfully,
        // return the existing piece's card instead of erroring out.
        $callsSoFar = collect($priorTurnTools)->where('name', 'write_blog_post')->where('status', 'ok')->count();
        if ($callsSoFar >= 1) {
            $brief = Brief::fromJson($conversation->brief ?? []);
            if ($brief->hasContentPiece()) {
                $piece = ContentPiece::where('id', $brief->contentPieceId())
                    ->where('team_id', $team->id)
                    ->first();
                if ($piece !== null) {
                    return json_encode([
                        'status' => 'ok',
                        'summary' => 'Draft already exists · v' . $piece->current_version,
                        'card' => [
                            'kind' => 'content_piece',
                            'summary' => 'Draft already exists · v' . $piece->current_version,
                            'title' => $piece->title,
                            'preview' => mb_substr(strip_tags($piece->body), 0, 200),
                            'word_count' => str_word_count(strip_tags($piece->body)),
                            'piece_id' => $piece->id,
                        ],
                        'piece_id' => $piece->id,
                    ]);
                }
            }
            // Prior call claimed to have run but we can't find the piece — fall through.
        }

        $brief = Brief::fromJson($conversation->brief ?? [])
            ->withConversationId($conversation->id);

        $extraContext = $args['extra_context'] ?? null;
        $agent = $extraContext !== null ? new WriterAgent($extraContext) : ($this->agent ?? new WriterAgent);
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

        $pieceId = $result->brief->contentPieceId();
        if ($pieceId !== null) {
            ContentPiece::where('id', $pieceId)->update(['conversation_id' => $conversation->id]);
        }

        $conversation->update(['brief' => $result->brief->toJson()]);

        return json_encode([
            'status' => 'ok',
            'summary' => $result->summary,
            'card' => $result->cardPayload,
            'piece_id' => $pieceId,
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
