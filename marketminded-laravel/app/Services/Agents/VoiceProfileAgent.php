<?php

namespace App\Services\Agents;

use App\Models\BrandPositioning;
use App\Models\Team;
use App\Models\VoiceProfile;
use App\Services\OpenRouterClient;

class VoiceProfileAgent
{
    public function __construct(private OpenRouterClient $client) {}

    public function generate(Team $team, BrandPositioning $positioning, array $fetchedContent): VoiceProfile
    {
        $systemPrompt = $this->buildSystemPrompt($team, $positioning, $fetchedContent);

        $tools = [
            $this->fetchUrlTool(),
            $this->submitTool(),
        ];

        $result = $this->client->chat(
            [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => 'Analyze the writing style and produce a structured voice & tone profile. You MUST call submit_voice_profile with your results.'],
            ],
            $tools,
        );

        if (! is_array($result)) {
            throw new \RuntimeException('VoiceProfileAgent did not return structured data.');
        }

        return $team->voiceProfile()->updateOrCreate(
            ['team_id' => $team->id],
            [
                'voice_analysis' => $result['voice_analysis'] ?? null,
                'content_types' => $result['content_types'] ?? null,
                'should_avoid' => $result['should_avoid'] ?? null,
                'should_use' => $result['should_use'] ?? null,
                'style_inspiration' => $result['style_inspiration'] ?? null,
                'preferred_length' => $result['preferred_length'] ?? 1500,
            ],
        );
    }

    private function buildSystemPrompt(Team $team, BrandPositioning $positioning, array $fetchedContent): string
    {
        $prompt = <<<PROMPT
You are an expert brand voice analyst building a structured voice & tone profile for "{$team->name}".

## Product & Positioning
- Value Proposition: {$positioning->value_proposition}
- Target Market: {$positioning->target_market}
- Key Differentiators: {$positioning->differentiators}

PROMPT;

        if ($team->tone_keywords) {
            $prompt .= "\n## Tone Keywords (from the team)\n{$team->tone_keywords}\n";
        }

        if (! empty($fetchedContent)) {
            $prompt .= "\n## Source Material (fetched from client URLs — blog posts, style references)\n";
            foreach ($fetchedContent as $url => $content) {
                $prompt .= "\n### {$url}\n{$content}\n";
            }
        }

        $prompt .= <<<PROMPT

## Your Task

### Step 1: Analyze writing patterns
If blog posts or style reference content is provided above, analyze the writing patterns. Focus on STYLE, not content:
- Voice and personality (formal/informal, warm/cold, peer/authority)
- Sentence structure, length, and rhythm
- Vocabulary level and recurring phrases
- How they address the reader
- Formatting patterns (headings, lists, CTAs)

If no blog/style content is available, use the positioning and tone keywords to infer an appropriate voice.

### Step 2: Use fetch_url if needed
If the source material above includes blog listing pages, use fetch_url to find and read 2-3 individual blog posts for deeper style analysis.

### Step 3: Produce structured output
Call submit_voice_profile with these fields:
1. **voice_analysis** — Brand personality, formality level, warmth, how they relate to the reader
2. **content_types** — What content approaches the brand uses (educational, promotional, storytelling, opinion, how-to, case study, etc.)
3. **should_avoid** — Words, phrases, patterns, and tones to never use
4. **should_use** — Characteristic vocabulary, phrases, sentence patterns, formatting conventions
5. **style_inspiration** — Writing style patterns observed from the source material
6. **preferred_length** — Target word count as an integer. Infer from blog posts if possible, default 1500.

## Rules
- Write in English.
- Analyze STYLE, not content. Focus on HOW they write, not WHAT they write about.
- Be specific to THIS brand. Generic voice guidelines are useless.
- NEVER use em dashes. Use commas, periods, or restructure.
- Short, direct sentences.

Call submit_voice_profile with your results.
PROMPT;

        return $prompt;
    }

    private function submitTool(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'submit_voice_profile',
                'description' => 'Submit the structured voice & tone profile.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'voice_analysis' => ['type' => 'string', 'description' => 'Brand personality, formality, warmth'],
                        'content_types' => ['type' => 'string', 'description' => 'Content approaches the brand uses'],
                        'should_avoid' => ['type' => 'string', 'description' => 'Words, phrases, tones to never use'],
                        'should_use' => ['type' => 'string', 'description' => 'Characteristic vocabulary and patterns'],
                        'style_inspiration' => ['type' => 'string', 'description' => 'Writing style patterns from references'],
                        'preferred_length' => ['type' => 'integer', 'description' => 'Target word count, default 1500'],
                    ],
                    'required' => ['voice_analysis', 'content_types', 'should_avoid', 'should_use', 'style_inspiration', 'preferred_length'],
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
