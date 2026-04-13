<?php

namespace App\Services;

use App\Models\Team;
use App\Models\Topic;

class TopicToolHandler
{
    public function execute(Team $team, int $conversationId, array $data): string
    {
        $savedTitles = [];

        foreach ($data['topics'] ?? [] as $topicData) {
            Topic::create([
                'team_id' => $team->id,
                'conversation_id' => $conversationId,
                'title' => $topicData['title'],
                'angle' => $topicData['angle'] ?? '',
                'sources' => $topicData['sources'] ?? [],
                'status' => 'available',
            ]);

            $savedTitles[] = $topicData['title'];
        }

        return json_encode([
            'status' => 'saved',
            'count' => count($savedTitles),
            'titles' => $savedTitles,
        ]);
    }

    public static function toolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'save_topics',
                'description' => 'Save approved content topics to the team\'s topic backlog. Only call this when the user has approved specific topics.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['topics'],
                    'properties' => [
                        'topics' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'required' => ['title', 'angle'],
                                'properties' => [
                                    'title' => [
                                        'type' => 'string',
                                        'description' => 'The topic title -- specific and compelling',
                                    ],
                                    'angle' => [
                                        'type' => 'string',
                                        'description' => 'Why this topic fits the brand and what angle to take, 1-2 sentences',
                                    ],
                                    'sources' => [
                                        'type' => 'array',
                                        'items' => ['type' => 'string'],
                                        'description' => 'Research evidence supporting this topic',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
