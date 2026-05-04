<?php

namespace Database\Factories;

use App\Models\ContentPiece;
use App\Models\SocialPost;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialPost>
 */
class SocialPostFactory extends Factory
{
    protected $model = SocialPost::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'content_piece_id' => ContentPiece::factory(),
            'conversation_id' => null,
            'platform' => 'linkedin',
            'hook' => fake()->sentence(),
            'body' => 'Body with [POST_URL] inside.',
            'hashtags' => ['ai', 'marketing'],
            'image_prompt' => 'A clean over-the-shoulder laptop shot.',
            'video_treatment' => null,
            'score' => null,
            'posted_at' => null,
            'status' => 'active',
            'position' => 0,
        ];
    }
}
