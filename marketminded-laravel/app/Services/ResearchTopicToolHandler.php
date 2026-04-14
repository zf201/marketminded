<?php

namespace App\Services;

use App\Models\Team;
use App\Models\Topic;

class ResearchTopicToolHandler
{
    public function execute(Team $team, int $conversationId, array $data, ?Topic $topic = null): string
    {
        $claims = $data['claims'] ?? [];

        foreach ($claims as $i => $claim) {
            if (empty($claim['id']) || empty($claim['text'])) {
                return json_encode([
                    'status' => 'error',
                    'message' => "Claim at index {$i} is missing required fields: id and text.",
                ]);
            }
        }

        return json_encode([
            'status' => 'ok',
            'claim_count' => count($claims),
            'topic_summary' => $data['topic_summary'] ?? '',
        ]);
    }

    public static function toolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'research_topic',
                'description' => 'Submit structured claims for the blog post, sourced from web search. Call this AFTER using web search and BEFORE create_outline.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['topic_summary', 'claims'],
                    'properties' => [
                        'topic_summary' => [
                            'type' => 'string',
                            'description' => '2-3 sentence summary of what this piece is about.',
                        ],
                        'queries' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'The web search queries you ran (for audit).',
                        ],
                        'claims' => [
                            'type' => 'array',
                            'minItems' => 1,
                            'items' => [
                                'type' => 'object',
                                'required' => ['id', 'text', 'sources'],
                                'properties' => [
                                    'id' => [
                                        'type' => 'string',
                                        'description' => 'Short slug like c1, c2, c3.',
                                    ],
                                    'text' => [
                                        'type' => 'string',
                                        'description' => 'The verified factual claim in a single sentence.',
                                    ],
                                    'sources' => [
                                        'type' => 'array',
                                        'minItems' => 1,
                                        'items' => [
                                            'type' => 'object',
                                            'required' => ['url', 'title'],
                                            'properties' => [
                                                'url' => ['type' => 'string'],
                                                'title' => ['type' => 'string'],
                                            ],
                                        ],
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
