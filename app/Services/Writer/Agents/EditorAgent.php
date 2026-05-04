<?php

namespace App\Services\Writer\Agents;

use App\Models\Team;
use App\Services\Writer\AgentResult;
use App\Services\Writer\BaseAgent;
use App\Services\Writer\Brief;

class EditorAgent extends BaseAgent
{
    /** @var array<int, string> */
    private array $knownClaimIds = [];

    public function execute(Brief $brief, Team $team): AgentResult
    {
        if (! $brief->hasResearch()) {
            return AgentResult::error('Cannot create outline without research. Run research_topic first.');
        }

        $this->knownClaimIds = array_map(fn ($c) => $c['id'], $brief->research()['claims']);

        return parent::execute($brief, $team);
    }

    protected function systemPrompt(Brief $brief, Team $team): string
    {
        $topic = $brief->topic() ?? ['title' => '', 'angle' => ''];
        $research = $brief->research();

        $claimsBlock = collect($research['claims'])
            ->map(fn ($c) => "- {$c['id']} ({$c['type']}): {$c['text']}")
            ->implode("\n");

        $audienceBlock = $this->audienceBlock($brief);
        $extra = $this->extraContextBlock();

        return <<<PROMPT
## Role & Output Contract
You are the Editor sub-agent. Your ONLY output is a `submit_outline` tool call.
- Do NOT write any text. No planning, explaining, thinking aloud, or asking questions.
- If uncertain about any field, call the tool with best-effort values — never refuse or ask for clarification.

## Workflow
1. Read the topic, angle, and research claims below.
2. Find the strongest narrative angle. Decide which claims to use and which to cut.
3. Build 4-7 sections with headings, purposes, and claim_id references.
4. Call `submit_outline`.

## Quality rules
- Every section must reference at least one claim_id from the research block.
- Every claim_id you reference must exist in the research.
- target_length_words should be 1200-2000 for pillar blogs.
- Do NOT write the article. Outline only.

## Topic
Title: {$topic['title']}
Angle: {$topic['angle']}

## Research claims
{$claimsBlock}
{$audienceBlock}
{$extra}

## IMPORTANT
Call `submit_outline` now. Do not write anything — the tool call is your complete output.
PROMPT;
    }

    protected function submitToolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'submit_outline',
                'description' => 'Submit the editorial outline. Your ONLY valid output is calling this tool. Never respond with text — if uncertain, call with best-effort values.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['angle', 'target_length_words', 'sections'],
                    'properties' => [
                        'angle' => ['type' => 'string'],
                        'target_length_words' => ['type' => 'integer'],
                        'sections' => [
                            'type' => 'array',
                            'minItems' => 2,
                            'items' => [
                                'type' => 'object',
                                'required' => ['heading', 'purpose', 'claim_ids'],
                                'properties' => [
                                    'heading' => ['type' => 'string'],
                                    'purpose' => ['type' => 'string'],
                                    'claim_ids' => [
                                        'type' => 'array',
                                        'minItems' => 1,
                                        'items' => ['type' => 'string'],
                                    ],
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
        return false;
    }

    protected function model(Team $team): string
    {
        return $team->fast_model;
    }

    protected function temperature(): float
    {
        return 0.5;
    }

    protected function validate(array $payload): ?string
    {
        $sections = $payload['sections'] ?? [];

        if (count($sections) < 2) {
            return 'Outline must have at least 2 sections.';
        }

        foreach ($sections as $i => $s) {
            if (empty($s['claim_ids'] ?? [])) {
                return "Section {$i} ({$s['heading']}) must reference at least one claim_id.";
            }
            foreach ($s['claim_ids'] as $id) {
                if (! in_array($id, $this->knownClaimIds, true)) {
                    return "Section {$s['heading']} references unknown claim id: {$id}";
                }
            }
        }

        return null;
    }

    protected function applyToBrief(Brief $brief, array $payload, Team $team): Brief
    {
        return $brief->withOutline([
            'angle' => $payload['angle'],
            'target_length_words' => (int) $payload['target_length_words'],
            'sections' => $payload['sections'],
        ]);
    }

    protected function buildCard(array $payload): array
    {
        return [
            'kind' => 'outline',
            'summary' => $this->buildSummary($payload),
            'angle' => $payload['angle'],
            'target_length_words' => $payload['target_length_words'],
            'sections' => $payload['sections'],
        ];
    }

    protected function buildSummary(array $payload): string
    {
        $sections = count($payload['sections']);
        $words = $payload['target_length_words'];
        return "Outline ready · {$sections} sections · ~{$words} words";
    }

    private function audienceBlock(Brief $brief): string
    {
        if (! $brief->hasAudience()) {
            return '';
        }

        $audience = $brief->audience();
        $lines = ["\n## Audience target"];
        $lines[] = 'Mode: ' . ($audience['mode'] ?? 'unknown');

        if (($audience['mode'] ?? '') === 'persona' && ! empty($audience['persona_label'])) {
            $summary = $audience['persona_summary'] ?? '';
            $lines[] = 'Persona: ' . $audience['persona_label'] . ($summary ? ' — ' . $summary : '');
        }

        $lines[] = 'Writer guidance: ' . ($audience['guidance_for_writer'] ?? '');

        return implode("\n", $lines);
    }
}
