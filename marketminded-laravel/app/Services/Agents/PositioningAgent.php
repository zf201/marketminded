<?php

namespace App\Services\Agents;

use App\Models\BrandPositioning;
use App\Models\Team;
use App\Services\OpenRouterClient;

class PositioningAgent
{
    public function __construct(private OpenRouterClient $client) {}

    public function generate(Team $team, array $fetchedContent): BrandPositioning
    {
        $systemPrompt = $this->buildSystemPrompt($team, $fetchedContent);

        $tools = [
            $this->fetchUrlTool(),
            $this->submitTool(),
        ];

        $result = $this->client->chat(
            [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => 'Analyze the brand and produce structured positioning. You MUST call submit_positioning with your results.'],
            ],
            $tools,
        );

        if (! is_array($result)) {
            throw new \RuntimeException('PositioningAgent did not return structured data.');
        }

        return $team->brandPositioning()->updateOrCreate(
            ['team_id' => $team->id],
            [
                'value_proposition' => $result['value_proposition'] ?? null,
                'target_market' => $result['target_market'] ?? null,
                'differentiators' => $result['differentiators'] ?? null,
                'core_problems' => $result['core_problems'] ?? null,
                'products_services' => $result['products_services'] ?? null,
                'primary_cta' => $result['primary_cta'] ?? null,
            ],
        );
    }

    private function buildSystemPrompt(Team $team, array $fetchedContent): string
    {
        $prompt = <<<PROMPT
You are an expert content marketing strategist. Analyze the brand and produce structured positioning for "{$team->name}".

## What to produce
For each field, write specific prose about THIS client. If it could apply to any company, it's too generic.

1. **Value Proposition** — What the company does and why it matters. One clear statement.
2. **Target Market** — Who they serve. Industry, company size, role, pain level.
3. **Key Differentiators** — What sets them apart from alternatives. Be specific.
4. **Core Problems Solved** — What pain points the product addresses. Why existing solutions fail.
5. **Products & Services** — What they actually sell. Key features.
6. **Primary CTA** — What action they want readers to take (book a call, sign up, buy, etc.).

PROMPT;

        if ($team->brand_description) {
            $prompt .= "\n## Brand Description (from the team)\n{$team->brand_description}\n";
        }

        if ($team->target_audience) {
            $prompt .= "\n## Target Audience Hint\n{$team->target_audience}\n";
        }

        if ($team->tone_keywords) {
            $prompt .= "\n## Tone Keywords\n{$team->tone_keywords}\n";
        }

        if (! empty($fetchedContent)) {
            $prompt .= "\n## Source Material (fetched from client URLs)\n";
            foreach ($fetchedContent as $url => $content) {
                $prompt .= "\n### {$url}\n{$content}\n";
            }
        }

        $prompt .= <<<PROMPT

## Rules
- NEVER fabricate or assume details. Base everything on the source material.
- Write specific prose about THIS client.
- Be thorough and comprehensive.
- Write in English.
- Write like a human. NEVER sound like AI-generated content.
- NEVER use em dashes. Use commas, periods, or restructure.
- Zero emojis.
- Avoid: "dive into", "leverage", "elevate", "streamline", "game-changer", "unlock", "harness", "at the end of the day", "it's worth noting".
- Short, direct sentences. Vary length.

Call submit_positioning with your results.
PROMPT;

        return $prompt;
    }

    private function submitTool(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'submit_positioning',
                'description' => 'Submit the structured brand positioning.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'value_proposition' => ['type' => 'string', 'description' => 'What the company does and why it matters'],
                        'target_market' => ['type' => 'string', 'description' => 'Who they serve'],
                        'differentiators' => ['type' => 'string', 'description' => 'What sets them apart'],
                        'core_problems' => ['type' => 'string', 'description' => 'Pain points the product addresses'],
                        'products_services' => ['type' => 'string', 'description' => 'What they sell'],
                        'primary_cta' => ['type' => 'string', 'description' => 'Desired reader action'],
                    ],
                    'required' => ['value_proposition', 'target_market', 'differentiators', 'core_problems', 'products_services', 'primary_cta'],
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
