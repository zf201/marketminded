<?php

namespace App\Services;

use App\Models\ContentPiece;
use App\Models\SocialPost;
use App\Models\Team;
use App\Models\Topic;

class ListAvailablePoolToolHandler
{
    public static function toolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'list_available_pool',
                'description' => 'Return the current unused topics, content pieces, and social posts for this team.',
                'parameters' => ['type' => 'object', 'properties' => (object) []],
            ],
        ];
    }

    public static function run(Team $team): array
    {
        return [
            'status' => 'ok',
            'topics' => Topic::where('team_id', $team->id)->where('used', false)->orderByDesc('created_at')->limit(25)->get(['id', 'title'])->all(),
            'content_pieces' => ContentPiece::where('team_id', $team->id)->where('used', false)->latest()->limit(25)->get(['id', 'title'])->all(),
            'social_posts' => SocialPost::where('team_id', $team->id)->where('used', false)->where('status', 'active')->latest()->limit(25)->get(['id', 'platform', 'hook'])->all(),
        ];
    }
}
