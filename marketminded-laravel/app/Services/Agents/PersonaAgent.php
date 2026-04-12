<?php

namespace App\Services\Agents;

use App\Models\BrandPositioning;
use App\Models\Team;
use App\Services\OpenRouterClient;
use Illuminate\Support\Collection;

class PersonaAgent
{
    public function __construct(private OpenRouterClient $client) {}

    public function generate(Team $team, BrandPositioning $positioning, array $fetchedContent): Collection
    {
        $systemPrompt = $this->buildSystemPrompt($team, $positioning, $fetchedContent);

        $tools = [
            $this->fetchUrlTool(),
            $this->submitTool(),
        ];

        $result = $this->client->chat(
            [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => 'Research and define 3-5 detailed audience personas for this business.'],
            ],
            $tools,
        );

        $team->audiencePersonas()->delete();

        $personas = collect();
        foreach (($result['personas'] ?? []) as $index => $personaData) {
            $persona = $team->audiencePersonas()->create([
                'label' => $personaData['label'] ?? "Persona {$index}",
                'description' => $personaData['description'] ?? null,
                'pain_points' => $personaData['pain_points'] ?? null,
                'push' => $personaData['push'] ?? null,
                'pull' => $personaData['pull'] ?? null,
                'anxiety' => $personaData['anxiety'] ?? null,
                'role' => $personaData['role'] ?? null,
                'sort_order' => $index,
            ]);
            $personas->push($persona);
        }

        return $personas;
    }

    private function buildSystemPrompt(Team $team, BrandPositioning $positioning, array $fetchedContent): string
    {
        $prompt = <<<PROMPT
You are an expert content marketing strategist building audience personas for "{$team->name}".

## Product & Positioning
- Value Proposition: {$positioning->value_proposition}
- Target Market: {$positioning->target_market}
- Key Differentiators: {$positioning->differentiators}
- Core Problems: {$positioning->core_problems}
- Products & Services: {$positioning->products_services}

PROMPT;

        if ($team->target_audience) {
            $prompt .= "\n## Target Audience Hint\n{$team->target_audience}\n";
        }

        if ($team->brand_description) {
            $prompt .= "\n## Brand Description\n{$team->brand_description}\n";
        }

        $prompt .= <<<PROMPT

## Your Task
Research and define 3-5 detailed audience personas for this business. Use web search to research the market, competitors, and target audience.

For each persona, provide:
- **label**: A short memorable name (e.g. "The Overwhelmed Engineering Lead")
- **description**: 2-3 sentences describing who they are
- **pain_points**: Their specific frustrations and problems
- **push**: What's driving them to seek a solution NOW
- **pull**: What attracts them to THIS specific solution
- **anxiety**: Concerns that might stop them from acting
- **role**: Their job title/role

## Rules
- Write in English.
- NEVER fabricate details. Use web search to research real market data.
- Be specific to THIS business. Generic personas are useless.
- Write in plain language, not marketing jargon.
- Each persona should be distinct and non-overlapping.
- NEVER use em dashes. Use commas, periods, or restructure.
- Short, direct sentences.

Call submit_personas with your results.
PROMPT;

        return $prompt;
    }

    private function submitTool(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'submit_personas',
                'description' => 'Submit the final set of audience personas.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'personas' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'label' => ['type' => 'string'],
                                    'description' => ['type' => 'string'],
                                    'pain_points' => ['type' => 'string'],
                                    'push' => ['type' => 'string'],
                                    'pull' => ['type' => 'string'],
                                    'anxiety' => ['type' => 'string'],
                                    'role' => ['type' => 'string'],
                                ],
                                'required' => ['label', 'description', 'pain_points', 'push', 'pull', 'anxiety'],
                            ],
                        ],
                    ],
                    'required' => ['personas'],
                ],
            ],
        ];
    }

    private function fetchUrlTool(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'fetch_url',
                'description' => 'Fetch and extract text content from a URL.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'url' => ['type' => 'string', 'description' => 'The URL to fetch'],
                    ],
                    'required' => ['url'],
                ],
            ],
        ];
    }
}
