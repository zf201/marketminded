<?php

namespace App\Services\Writer\Agents;

use App\Models\Team;
use App\Services\Writer\BaseAgent;
use App\Services\Writer\Brief;

class ResearchAgent extends BaseAgent
{
    protected function systemPrompt(Brief $brief, Team $team): string
    {
        $topic = $brief->topic() ?? ['title' => '', 'angle' => '', 'sources' => []];
        $title = $topic['title'] ?? '';
        $angle = $topic['angle'] ?? '';
        $brainstormSources = is_array($topic['sources'] ?? null) && ! empty($topic['sources'])
            ? "\n- " . implode("\n- ", $topic['sources'])
            : ' (none)';

        $extra = $this->extraContextBlock();

        return <<<PROMPT
## Role & Output Contract
You are the Research sub-agent for a blog writing pipeline. You deliver output EXCLUSIVELY by calling the submit_research tool.
- Text responses are system failures. Do not narrate, plan, explain, or offer commentary.
- You MUST end your turn with a submit_research call.

## Workflow
1. Use web_search to find current, authoritative information on the topic and angle below.
2. Extract 8-15 verifiable single-sentence claims with source attribution.
3. Call submit_research with your structured findings (topic_summary, claims, sources).

## Quality rules
- Each claim must be a single declarative sentence.
- Each claim must have type: stat, quote, fact, date, or price.
- Each claim must cite at least one source by id (s1, s2, ...).
- Source IDs must be unique. Claim IDs must be unique.
- Aim for 8-15 claims; refuse to submit fewer than 3.
- Prefer recent, authoritative sources.

## Topic
Title: {$title}
Angle: {$angle}
Brainstorm sources:{$brainstormSources}
{$extra}

## IMPORTANT
Your turn MUST end with a submit_research call. Any text output is a failure.
PROMPT;
    }

    protected function submitToolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'submit_research',
                'description' => 'Submit the structured research claims block. This is your ONLY way to deliver output.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['topic_summary', 'claims', 'sources'],
                    'properties' => [
                        'topic_summary' => ['type' => 'string', 'description' => '2-3 sentence summary'],
                        'claims' => [
                            'type' => 'array',
                            'minItems' => 3,
                            'items' => [
                                'type' => 'object',
                                'required' => ['id', 'text', 'type', 'source_ids'],
                                'properties' => [
                                    'id' => ['type' => 'string'],
                                    'text' => ['type' => 'string'],
                                    'type' => ['type' => 'string', 'enum' => ['stat', 'quote', 'fact', 'date', 'price']],
                                    'source_ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1],
                                ],
                            ],
                        ],
                        'sources' => [
                            'type' => 'array',
                            'minItems' => 1,
                            'items' => [
                                'type' => 'object',
                                'required' => ['id', 'url', 'title'],
                                'properties' => [
                                    'id' => ['type' => 'string'],
                                    'url' => ['type' => 'string'],
                                    'title' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    protected function additionalTools(): array
    {
        return [];
    }

    protected function useServerTools(): bool
    {
        return true;  // web_search
    }

    protected function model(Team $team): string
    {
        return $team->fast_model;
    }

    protected function temperature(): float
    {
        return 0.4;
    }

    protected function validate(array $payload): ?string
    {
        $claims = $payload['claims'] ?? [];
        $sources = $payload['sources'] ?? [];

        if (count($claims) < 3) {
            return 'Research must contain at least 3 claims.';
        }

        $claimIds = array_map(fn ($c) => $c['id'] ?? '', $claims);
        if (count($claimIds) !== count(array_unique($claimIds))) {
            return 'Research has duplicate claim ids.';
        }

        $sourceIds = array_map(fn ($s) => $s['id'] ?? '', $sources);
        if (count($sourceIds) !== count(array_unique($sourceIds))) {
            return 'Research has duplicate source ids.';
        }

        $sourceIdSet = array_flip($sourceIds);
        foreach ($claims as $c) {
            foreach ($c['source_ids'] ?? [] as $sid) {
                if (! isset($sourceIdSet[$sid])) {
                    return "Claim {$c['id']} cites unknown source: {$sid}";
                }
            }
        }

        return null;
    }

    protected function applyToBrief(Brief $brief, array $payload, Team $team): Brief
    {
        return $brief->withResearch([
            'topic_summary' => $payload['topic_summary'],
            'claims' => $payload['claims'],
            'sources' => $payload['sources'],
        ]);
    }

    protected function buildCard(array $payload): array
    {
        return [
            'kind' => 'research',
            'summary' => $this->buildSummary($payload),
            'topic_summary' => $payload['topic_summary'],
            'claims' => $payload['claims'],
            'sources' => $payload['sources'],
        ];
    }

    protected function buildSummary(array $payload): string
    {
        $claims = count($payload['claims']);
        $sources = count($payload['sources']);
        return "Gathered {$claims} claims from {$sources} sources";
    }
}
