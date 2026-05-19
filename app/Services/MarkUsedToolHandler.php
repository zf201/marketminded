<?php

namespace App\Services;

use App\Models\ContentPiece;
use App\Models\SocialPost;
use App\Models\Team;
use App\Models\Topic;

class MarkUsedToolHandler
{
    private const MAP = [
        'topic' => Topic::class,
        'social_post' => SocialPost::class,
        'content_piece' => ContentPiece::class,
    ];

    public static function toolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'mark_used',
                'description' => 'Toggle the used flag on a topic, social_post, or content_piece. Items marked used are excluded from future planner suggestions.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['type', 'id', 'used'],
                    'properties' => [
                        'type' => ['type' => 'string', 'enum' => array_keys(self::MAP)],
                        'id' => ['type' => 'integer'],
                        'used' => ['type' => 'boolean'],
                    ],
                ],
            ],
        ];
    }

    public static function run(Team $team, array $args): array
    {
        $type = $args['type'] ?? null;
        if (! isset(self::MAP[$type])) {
            return ['status' => 'error', 'message' => 'Unknown type.'];
        }
        $class = self::MAP[$type];
        $model = $class::where('id', $args['id'] ?? 0)->where('team_id', $team->id)->first();
        if (! $model) {
            return ['status' => 'error', 'message' => 'Not found for this team.'];
        }
        $model->used = (bool) ($args['used'] ?? true);
        $model->save();
        return ['status' => 'ok'];
    }
}
