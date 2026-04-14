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
        $callsSoFar = collect($priorTurnTools)->where('name', 'proofread_blog_post')->count();
        if ($callsSoFar >= 1) {
            return json_encode([
                'status' => 'error',
                'message' => 'Already retried proofread_blog_post this turn. Get help from the user.',
            ]);
        }

        $feedback = trim($args['feedback'] ?? '');
        if ($feedback === '') {
            return json_encode([
                'status' => 'error',
                'message' => 'feedback is required for proofread_blog_post.',
            ]);
        }

        $conversation = Conversation::findOrFail($conversationId);
        $brief = Brief::fromJson($conversation->brief ?? []);

        $extraContext = $args['extra_context'] ?? null;
        // In production we always construct fresh (ProofreadAgent needs $feedback).
        // Tests may inject a fake via constructor — keep that path for them.
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
