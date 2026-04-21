<?php

namespace App\Services;

use App\Models\Team;
use Illuminate\Support\Arr;

class BrandIntelligenceToolHandler
{
    private const SETUP_FIELDS = [
        'homepage_url', 'blog_url', 'brand_description',
        'product_urls', 'competitor_urls', 'style_reference_urls',
        'target_audience', 'tone_keywords', 'content_language',
    ];

    public function execute(Team $team, array $data): string
    {
        $savedSections = [];

        if (isset($data['setup'])) {
            $team->update(Arr::only($data['setup'], self::SETUP_FIELDS));
            $savedSections[] = 'setup';
        }

        if (isset($data['positioning'])) {
            $team->brandPositioning()->updateOrCreate(
                ['team_id' => $team->id],
                $data['positioning'],
            );
            $savedSections[] = 'positioning';
        }

        if (isset($data['personas'])) {
            $team->audiencePersonas()->delete();

            foreach ($data['personas'] as $i => $persona) {
                $team->audiencePersonas()->create(array_merge($persona, ['sort_order' => $i]));
            }

            $savedSections[] = 'personas';
        }

        if (isset($data['voice'])) {
            $team->voiceProfile()->updateOrCreate(
                ['team_id' => $team->id],
                $data['voice'],
            );
            $savedSections[] = 'voice';
        }

        return json_encode(['status' => 'saved', 'sections' => $savedSections]);
    }

    public static function toolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'update_brand_intelligence',
                'description' => 'Update the brand intelligence profile. All sections and fields are optional — only include what you want to change. When updating personas, provide the full list (replaces existing).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'setup' => [
                            'type' => 'object',
                            'properties' => [
                                'homepage_url' => ['type' => 'string'],
                                'blog_url' => ['type' => 'string'],
                                'brand_description' => ['type' => 'string'],
                                'product_urls' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'competitor_urls' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'style_reference_urls' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'target_audience' => ['type' => 'string'],
                                'tone_keywords' => ['type' => 'string'],
                                'content_language' => ['type' => 'string'],
                            ],
                        ],
                        'positioning' => [
                            'type' => 'object',
                            'properties' => [
                                'value_proposition' => ['type' => 'string'],
                                'target_market' => ['type' => 'string'],
                                'differentiators' => ['type' => 'string'],
                                'core_problems' => ['type' => 'string'],
                                'products_services' => ['type' => 'string'],
                                'primary_cta' => ['type' => 'string'],
                            ],
                        ],
                        'personas' => [
                            'type' => 'array',
                            'description' => 'Full list of audience personas. Replaces all existing.',
                            'items' => [
                                'type' => 'object',
                                'required' => ['label'],
                                'properties' => [
                                    'label' => ['type' => 'string'],
                                    'role' => ['type' => 'string'],
                                    'description' => ['type' => 'string'],
                                    'pain_points' => ['type' => 'string'],
                                    'push' => ['type' => 'string'],
                                    'pull' => ['type' => 'string'],
                                    'anxiety' => ['type' => 'string'],
                                ],
                            ],
                        ],
                        'voice' => [
                            'type' => 'object',
                            'properties' => [
                                'voice_analysis' => ['type' => 'string'],
                                'content_types' => ['type' => 'string'],
                                'should_avoid' => ['type' => 'string'],
                                'should_use' => ['type' => 'string'],
                                'style_inspiration' => ['type' => 'string'],
                                'preferred_length' => ['type' => 'integer'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    public static function fetchUrlToolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'fetch_url',
                'description' => 'Fetch and read the content of a web page URL.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['url'],
                    'properties' => [
                        'url' => ['type' => 'string', 'description' => 'The URL to fetch'],
                    ],
                ],
            ],
        ];
    }
}
