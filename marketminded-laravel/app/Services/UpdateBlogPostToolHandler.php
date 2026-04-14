<?php

namespace App\Services;

use App\Models\ContentPiece;
use App\Models\Team;

class UpdateBlogPostToolHandler
{
    public function execute(Team $team, int $conversationId, array $data): string
    {
        $piece = ContentPiece::where('team_id', $team->id)
            ->find($data['content_piece_id'] ?? 0);

        if (! $piece) {
            return json_encode([
                'status' => 'error',
                'message' => 'Content piece not found.',
            ]);
        }

        $title = $data['title'] ?? '';
        $body = $data['body'] ?? '';

        if ($title === '' || $body === '') {
            return json_encode([
                'status' => 'error',
                'message' => 'title and body are required.',
            ]);
        }

        $piece->saveSnapshot($title, $body, $data['change_description'] ?? null);

        return json_encode([
            'status' => 'ok',
            'content_piece_id' => $piece->id,
            'version' => $piece->current_version,
        ]);
    }

    public static function toolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'update_blog_post',
                'description' => 'Revise the current blog post based on user feedback. Saves a new version snapshot.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['content_piece_id', 'title', 'body', 'change_description'],
                    'properties' => [
                        'content_piece_id' => ['type' => 'integer'],
                        'title' => ['type' => 'string'],
                        'body' => ['type' => 'string', 'description' => 'Full revised markdown body.'],
                        'change_description' => [
                            'type' => 'string',
                            'description' => 'Short summary of what changed, e.g. "Punched up intro".',
                        ],
                    ],
                ],
            ],
        ];
    }
}
