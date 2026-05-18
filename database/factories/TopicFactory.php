<?php

namespace Database\Factories;

use App\Models\Team;
use App\Models\Topic;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Topic>
 */
class TopicFactory extends Factory
{
    protected $model = Topic::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'conversation_id' => null,
            'title' => fake()->sentence(6),
            'angle' => fake()->sentence(14),
            'sources' => [],
            'status' => 'available',
            'score' => null,
            'used' => false,
        ];
    }
}
