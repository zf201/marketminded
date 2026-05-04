<?php

namespace Database\Factories;

use App\Models\ContentPiece;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentPiece>
 */
class ContentPieceFactory extends Factory
{
    protected $model = ContentPiece::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'conversation_id' => null,
            'topic_id' => null,
            'title' => fake()->sentence(6),
            'body' => fake()->paragraphs(3, true),
            'status' => 'draft',
            'platform' => 'blog',
            'format' => 'pillar',
            'current_version' => 1,
        ];
    }
}
