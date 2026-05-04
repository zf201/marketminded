<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'user_id' => User::factory(),
            'title' => fake()->sentence(4),
            'type' => null,
            'topic_id' => null,
            'content_piece_id' => null,
            'brief' => [],
        ];
    }
}
