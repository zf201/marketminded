<?php

namespace App\Services;

use App\Models\ContentPiece;
use App\Models\SocialPost;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

class SocialPostToolHandler
{
    public static function postItemSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['platform', 'hook', 'body'],
            'properties' => [
                'platform' => [
                    'type' => 'string',
                    'enum' => ['linkedin', 'facebook', 'instagram', 'short_video'],
                ],
                'hook' => [
                    'type' => 'string',
                    'description' => 'Scroll-stopping opener line.',
                ],
                'body' => [
                    'type' => 'string',
                    'description' => 'Full post body in markdown. MUST contain [POST_URL] exactly once at a natural CTA point.',
                ],
                'hashtags' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Hashtags without leading #.',
                ],
                'image_prompt' => [
                    'type' => 'string',
                    'description' => 'Direction for the visual. Required for non-video platforms.',
                ],
                'video_treatment' => [
                    'type' => 'string',
                    'description' => 'Hook beat / value beats / CTA. Required when platform = short_video.',
                ],
            ],
        ];
    }

    public static function proposeSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'propose_posts',
                'description' => 'Save the initial set of social posts for the selected content piece. 3–6 posts, at most 1 with platform=short_video. Every body must contain [POST_URL] exactly once.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['posts'],
                    'properties' => [
                        'posts' => [
                            'type' => 'array',
                            'minItems' => 3,
                            'maxItems' => 6,
                            'items' => self::postItemSchema(),
                        ],
                    ],
                ],
            ],
        ];
    }

    public static function updateSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'update_post',
                'description' => 'Update one existing social post by id. Only include fields you want to change.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['id'],
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'platform' => ['type' => 'string', 'enum' => ['linkedin', 'facebook', 'instagram', 'short_video']],
                        'hook' => ['type' => 'string'],
                        'body' => ['type' => 'string'],
                        'hashtags' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'image_prompt' => ['type' => 'string'],
                        'video_treatment' => ['type' => 'string'],
                    ],
                ],
            ],
        ];
    }

    public static function deleteSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'delete_post',
                'description' => 'Soft-delete one social post by id. Briefly explain why in your message.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['id'],
                    'properties' => ['id' => ['type' => 'integer']],
                ],
            ],
        ];
    }

    public static function replaceAllSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'replace_all_posts',
                'description' => 'Soft-delete all current active posts for the content piece and create a new set. Same constraints as propose_posts.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['posts'],
                    'properties' => [
                        'posts' => [
                            'type' => 'array',
                            'minItems' => 3,
                            'maxItems' => 6,
                            'items' => self::postItemSchema(),
                        ],
                    ],
                ],
            ],
        ];
    }

    public function propose(Team $team, int $conversationId, ContentPiece $piece, array $data): string
    {
        if ($piece->team_id !== $team->id) {
            return $this->err('Content piece does not belong to this team.');
        }

        $validation = $this->validatePosts($data['posts'] ?? []);
        if ($validation !== null) {
            return $this->err($validation);
        }

        $ids = DB::transaction(function () use ($team, $conversationId, $piece, $data) {
            $startPos = (int) SocialPost::where('content_piece_id', $piece->id)->max('position');
            $ids = [];
            foreach ($data['posts'] as $i => $p) {
                $ids[] = SocialPost::create($this->postAttrs($team, $conversationId, $piece, $p, $startPos + $i + 1))->id;
            }
            return $ids;
        });

        return json_encode(['status' => 'saved', 'count' => count($ids), 'ids' => $ids]);
    }

    public function update(Team $team, int $conversationId, array $data): string
    {
        $post = SocialPost::where('team_id', $team->id)->find($data['id'] ?? 0);
        if (! $post) {
            return $this->err('Post not found in this team.');
        }

        $patch = array_intersect_key($data, array_flip([
            'platform', 'hook', 'body', 'hashtags', 'image_prompt', 'video_treatment',
        ]));

        if (isset($patch['body']) && substr_count($patch['body'], '[POST_URL]') !== 1) {
            return $this->err('Body must contain [POST_URL] exactly once.');
        }
        if (isset($patch['platform']) && $patch['platform'] === 'short_video') {
            $otherShortVideos = SocialPost::where('content_piece_id', $post->content_piece_id)
                ->where('status', 'active')->where('id', '!=', $post->id)
                ->where('platform', 'short_video')->count();
            if ($otherShortVideos >= 1) {
                return $this->err('Only one short_video post is allowed per content piece.');
            }
        }

        $post->update($patch + ['conversation_id' => $conversationId ?: $post->conversation_id]);

        return json_encode(['status' => 'saved', 'id' => $post->id]);
    }

    public function delete(Team $team, array $data): string
    {
        $post = SocialPost::where('team_id', $team->id)->find($data['id'] ?? 0);
        if (! $post) {
            return $this->err('Post not found in this team.');
        }
        $post->update(['status' => 'deleted']);
        return json_encode(['status' => 'deleted', 'id' => $post->id]);
    }

    public function replaceAll(Team $team, int $conversationId, ContentPiece $piece, array $data): string
    {
        if ($piece->team_id !== $team->id) {
            return $this->err('Content piece does not belong to this team.');
        }
        $validation = $this->validatePosts($data['posts'] ?? []);
        if ($validation !== null) {
            return $this->err($validation);
        }

        $ids = DB::transaction(function () use ($team, $conversationId, $piece, $data) {
            SocialPost::where('content_piece_id', $piece->id)->where('status', 'active')->update(['status' => 'deleted']);
            $ids = [];
            foreach ($data['posts'] as $i => $p) {
                $ids[] = SocialPost::create($this->postAttrs($team, $conversationId, $piece, $p, $i + 1))->id;
            }
            return $ids;
        });

        return json_encode(['status' => 'saved', 'count' => count($ids), 'ids' => $ids]);
    }

    private function validatePosts(array $posts): ?string
    {
        $count = count($posts);
        if ($count < 3 || $count > 6) {
            return 'Must propose between 3 and 6 posts.';
        }
        $shortVideos = 0;
        foreach ($posts as $i => $p) {
            $platform = $p['platform'] ?? '';
            if (! in_array($platform, ['linkedin', 'facebook', 'instagram', 'short_video'], true)) {
                return "Post #{$i}: invalid platform '{$platform}'.";
            }
            $body = $p['body'] ?? '';
            if (substr_count($body, '[POST_URL]') !== 1) {
                return "Post #{$i}: body must contain [POST_URL] exactly once.";
            }
            if ($platform === 'short_video') {
                $shortVideos++;
                if (empty($p['video_treatment'] ?? '')) {
                    return "Post #{$i}: short_video posts require video_treatment.";
                }
            } else {
                if (empty($p['image_prompt'] ?? '')) {
                    return "Post #{$i}: non-video posts require image_prompt.";
                }
            }
        }
        if ($shortVideos > 1) {
            return 'At most one post may have platform=short_video.';
        }
        return null;
    }

    private function postAttrs(Team $team, int $conversationId, ContentPiece $piece, array $p, int $position): array
    {
        return [
            'team_id' => $team->id,
            'content_piece_id' => $piece->id,
            'conversation_id' => $conversationId ?: null,
            'platform' => $p['platform'],
            'hook' => $p['hook'],
            'body' => $p['body'],
            'hashtags' => $p['hashtags'] ?? [],
            'image_prompt' => $p['image_prompt'] ?? null,
            'video_treatment' => $p['video_treatment'] ?? null,
            'status' => 'active',
            'position' => $position,
        ];
    }

    private function err(string $message): string
    {
        return json_encode(['status' => 'error', 'message' => $message]);
    }
}
