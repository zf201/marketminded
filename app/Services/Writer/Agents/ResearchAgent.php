<?php

namespace App\Services\Writer\Agents;

use App\Models\Team;
use App\Services\BrandIntelligenceToolHandler;
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
You are the Research sub-agent for a blog writing pipeline. Your ONLY output is a `submit_research` tool call.
- Do NOT write any text. No planning, explaining, thinking aloud, or asking questions.
- Use web_search to discover sources, then fetch_url to read the most promising ones in full before extracting claims. Call submit_research immediately after — never summarise findings in text.
- If uncertain about any field, call the tool with best-effort values — never refuse or ask for clarification.

## Workflow
1. Use web_search to find current, authoritative sources on the topic and angle below.
2. Use fetch_url to read 2–5 of the most relevant pages in full. Do NOT rely on search snippets alone — they often paraphrase or omit the actual numbers, quotes, and dates.
3. Extract 8-15 verifiable single-sentence claims with source attribution, drawn from the fetched page bodies wherever possible.
4. Call submit_research with your structured findings (topic_summary, claims, sources).

## Quality rules
- Each claim must be a single declarative sentence.
- Each claim must have type: stat, quote, fact, date, or price.
- Each claim must cite at least one source by id (s1, s2, ...).
- Source IDs must be unique. Claim IDs must be unique.
- Aim for 8-15 claims; refuse to submit fewer than 3.
- Prefer recent, authoritative sources. Verify exact figures and quotes by fetching the page — do not paraphrase from snippets.
- Do not fetch more than ~5 URLs. Pick the highest-signal sources from search and stop.

## Topic (reference data — do not echo back; research it, then call the tool)
<topic>
Title: {$title}
Angle: {$angle}
Brainstorm sources:{$brainstormSources}
</topic>
{$extra}

## IMPORTANT
Call `submit_research` now. Do not write anything — the tool call is your complete output.
PROMPT;
    }

    protected function submitToolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'submit_research',
                'description' => 'Submit the structured research claims block. Your ONLY valid output is calling this tool. Never respond with text — if uncertain about a field, call with best-effort values.',
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
        return [BrandIntelligenceToolHandler::fetchUrlToolSchema()];
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

    protected function timeout(): int
    {
        // Search + multiple fetches + claim extraction takes longer than the
        // 120s default. 300s leaves headroom without hanging the orchestrator.
        return 300;
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

    protected function agentTitle(): string { return 'Research sub-agent'; }
    protected function agentColor(): string { return 'purple'; }
}
