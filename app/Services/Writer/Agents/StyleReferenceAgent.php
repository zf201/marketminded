<?php

namespace App\Services\Writer\Agents;

use App\Models\Team;
use App\Services\BrandIntelligenceToolHandler;
use App\Services\Writer\AgentResult;
use App\Services\Writer\BaseAgent;
use App\Services\Writer\Brief;

class StyleReferenceAgent extends BaseAgent
{
    public function execute(Brief $brief, Team $team): AgentResult
    {
        if (! $brief->hasOutline()) {
            return AgentResult::error('Cannot fetch style reference without an outline. Run create_outline first.');
        }

        return parent::execute($brief, $team);
    }

    protected function systemPrompt(Brief $brief, Team $team): string
    {
        $topic = $brief->topic() ?? ['title' => '', 'angle' => ''];

        $curatedBlock = '';
        if (! empty($team->style_reference_urls)) {
            $urls = implode("\n", array_map(fn ($u) => "- {$u}", $team->style_reference_urls));
            $curatedBlock = "\n\n## Pre-curated style reference URLs (prefer these)\n{$urls}";
        }

        $blogUrlBlock = $team->blog_url
            ? "\n\n## Blog URL (browse index to find posts if curated list is empty)\n{$team->blog_url}"
            : '';

        $extra = $this->extraContextBlock();

        return <<<PROMPT
## Role & Output Contract
You are the StyleReference sub-agent. Your ONLY output is a `submit_style_reference` tool call.
- Do NOT write any text. No planning, explaining, thinking aloud, or asking questions.
- Use fetch_url to gather style examples, then call submit_style_reference immediately — never describe what you found in text.
- If uncertain about any field, call the tool with best-effort values — never refuse or ask for clarification.

## Task
Find 2–3 blog posts from this brand that best represent their voice and writing style. Use the pre-curated URLs if provided; otherwise use fetch_url to browse the blog index and discover posts.

## Quality rules
- Pick posts with clear brand voice. Avoid product announcements or press releases.
- `why_chosen` must explain the voice/style qualities observed — not just the topic.
- Do NOT include the post body in your submission. Only url, title, why_chosen.
- Submit exactly 2–3 examples.

## Topic being written
Title: {$topic['title']}
Angle: {$topic['angle']}
{$curatedBlock}{$blogUrlBlock}
{$extra}

## IMPORTANT
Call `submit_style_reference` now. Do not write anything — the tool call is your complete output.
PROMPT;
    }

    protected function submitToolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'submit_style_reference',
                'description' => 'Submit the style reference selection. Your ONLY valid output is calling this tool. Never respond with text — if uncertain, call with best-effort values.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['examples', 'reasoning'],
                    'properties' => [
                        'examples' => [
                            'type' => 'array',
                            'minItems' => 2,
                            'maxItems' => 3,
                            'items' => [
                                'type' => 'object',
                                'required' => ['url', 'title', 'why_chosen'],
                                'properties' => [
                                    'url' => ['type' => 'string'],
                                    'title' => ['type' => 'string'],
                                    'why_chosen' => ['type' => 'string'],
                                ],
                            ],
                        ],
                        'reasoning' => ['type' => 'string'],
                    ],
                ],
            ],
        ];
    }

    protected function additionalTools(): array
    {
        return [BrandIntelligenceToolHandler::fetchUrlToolSchema()];
    }

    protected function useServerTools(): bool
    {
        return true;
    }

    protected function model(Team $team): string
    {
        return $team->fast_model;
    }

    protected function temperature(): float
    {
        return 0.2;
    }

    protected function timeout(): int
    {
        return 180;
    }

    protected function validate(array $payload): ?string
    {
        $examples = $payload['examples'] ?? [];
        $n = count($examples);

        if ($n < 2 || $n > 3) {
            return "style_reference must have 2–3 examples, got {$n}.";
        }

        foreach ($examples as $i => $ex) {
            if (trim($ex['url'] ?? '') === '') {
                return "Example[{$i}] missing url.";
            }
            if (! filter_var($ex['url'], FILTER_VALIDATE_URL)) {
                return "Example[{$i}] url is not a valid URL: {$ex['url']}.";
            }
            if (trim($ex['title'] ?? '') === '') {
                return "Example[{$i}] missing title.";
            }
            if (trim($ex['why_chosen'] ?? '') === '') {
                return "Example[{$i}] missing why_chosen.";
            }
        }

        $urls = array_column($examples, 'url');
        if (count(array_unique($urls)) !== count($urls)) {
            return 'style_reference examples must have unique URLs.';
        }

        return null;
    }

    protected function applyToBrief(Brief $brief, array $payload, Team $team): Brief
    {
        // Store body-less examples. FetchStyleReferenceToolHandler fetches bodies
        // after execute() returns and re-calls withStyleReference with bodies.
        return $brief->withStyleReference([
            'examples' => array_map(fn ($ex) => [
                'url' => $ex['url'],
                'title' => $ex['title'],
                'why_chosen' => $ex['why_chosen'],
                'body' => '',
            ], $payload['examples']),
            'reasoning' => $payload['reasoning'],
        ]);
    }

    protected function buildCard(array $payload): array
    {
        return [
            'kind' => 'style_reference',
            'summary' => $this->buildSummary($payload),
            'examples' => array_map(fn ($ex) => [
                'title' => $ex['title'],
                'why_chosen' => $ex['why_chosen'],
            ], $payload['examples']),
        ];
    }

    protected function buildSummary(array $payload): string
    {
        $n = count($payload['examples']);
        return "Style reference: {$n} example" . ($n === 1 ? '' : 's') . ' selected';
    }
}
