<?php

namespace App\Services\Writer\Agents;

use App\Models\AudiencePersona;
use App\Models\Team;
use App\Services\Writer\AgentResult;
use App\Services\Writer\BaseAgent;
use App\Services\Writer\Brief;

class AudiencePickerAgent extends BaseAgent
{
    public function execute(Brief $brief, Team $team): AgentResult
    {
        if (! $brief->hasResearch()) {
            return AgentResult::error('Cannot pick audience without research. Run research_topic first.');
        }

        try {
            return parent::execute($brief, $team);
        } catch (\RuntimeException $e) {
            return AgentResult::error($e->getMessage());
        }
    }

    protected function systemPrompt(Brief $brief, Team $team): string
    {
        $topic = $brief->topic() ?? ['title' => '', 'angle' => ''];
        $topicSummary = $brief->research()['topic_summary'] ?? '';
        $personasBlock = $this->formatPersonasBlock($team->audiencePersonas()->get());
        $extra = $this->extraContextBlock();

        return <<<PROMPT
## Role & Output Contract
You are the AudiencePicker sub-agent. Your ONLY output is a `submit_audience_selection` tool call.
- Do NOT write any text. No planning, explaining, thinking aloud, or asking questions.
- If uncertain about any field, call the tool with best-effort values — never refuse or ask for clarification.

## Task
Read the topic, research summary, and available personas. Select the persona this post should address, or pick a mode if no persona fits.

## Modes
- `persona` — the post targets a specific persona. You MUST set `persona_id` to the persona's integer id from the list below.
- `educational` — no persona fits; write for a curious learner. Set `persona_id` to 0.
- `commentary` — no persona fits; write for an informed reader of this space. Set `persona_id` to 0.

## Quality rules
- Choose `persona` only if the topic + angle clearly matches that persona's needs or pain points.
- `guidance_for_writer` must be concrete and actionable (1–2 sentences). Do NOT echo the persona description.

## Topic (reference data — do not echo back)
<topic>
Title: {$topic['title']}
Angle: {$topic['angle']}
</topic>

## Research summary (reference data — do not echo back)
<research-summary>
{$topicSummary}
</research-summary>

## Available personas (reference data — do not echo back)
<personas>
{$personasBlock}
</personas>
{$extra}

## IMPORTANT
Call `submit_audience_selection` now. Do not write anything — the tool call is your complete output.
PROMPT;
    }

    protected function submitToolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'submit_audience_selection',
                'description' => 'Submit the audience selection. Your ONLY valid output is calling this tool. Never respond with text — if uncertain, call with best-effort values.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['mode', 'persona_id', 'reasoning', 'guidance_for_writer'],
                    'properties' => [
                        'mode' => ['type' => 'string', 'enum' => ['persona', 'educational', 'commentary']],
                        'persona_id' => ['type' => 'integer', 'description' => 'ID of the selected persona when mode=persona. Set to 0 when mode is educational or commentary.'],
                        'reasoning' => ['type' => 'string'],
                        'guidance_for_writer' => ['type' => 'string'],
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
        return 0.2;
    }

    protected function validate(array $payload): ?string
    {
        $mode = $payload['mode'] ?? '';

        if (! in_array($mode, ['persona', 'educational', 'commentary'], true)) {
            return 'mode must be one of: persona, educational, commentary.';
        }

        if ($mode === 'persona' && empty($payload['persona_id'])) {
            return 'persona_id is required and must be > 0 when mode=persona.';
        }

        if (trim($payload['guidance_for_writer'] ?? '') === '') {
            return 'guidance_for_writer must not be empty.';
        }

        return null;
    }

    protected function applyToBrief(Brief $brief, array $payload, Team $team): Brief
    {
        $audience = [
            'mode' => $payload['mode'],
            'reasoning' => $payload['reasoning'],
            'guidance_for_writer' => $payload['guidance_for_writer'],
        ];

        if ($payload['mode'] === 'persona') {
            $personaId = (int) $payload['persona_id'];
            $persona = AudiencePersona::where('id', $personaId)
                ->where('team_id', $team->id)
                ->first();

            if ($persona === null) {
                throw new \RuntimeException("persona_id {$personaId} not found for this team");
            }

            $audience['persona_id'] = $personaId;
            $audience['persona_label'] = $persona->label;
            $audience['persona_summary'] = $this->buildPersonaSummary($persona);
        }

        return $brief->withAudience($audience);
    }

    protected function buildCard(array $payload): array
    {
        return [
            'kind' => 'audience',
            'summary' => $this->buildSummary($payload),
            'mode' => $payload['mode'],
            'guidance_for_writer' => $payload['guidance_for_writer'],
        ];
    }

    protected function buildSummary(array $payload): string
    {
        return match ($payload['mode']) {
            'persona' => 'Audience: persona selected',
            'educational' => 'Audience: educational (no persona)',
            'commentary' => 'Audience: commentary (no persona)',
            default => 'Audience selected',
        };
    }

    private function buildPersonaSummary(AudiencePersona $persona): string
    {
        $parts = [];
        if ($persona->description) $parts[] = $persona->description;
        if ($persona->pain_points) $parts[] = 'Pain points: ' . $persona->pain_points;
        if ($persona->push) $parts[] = 'Push: ' . $persona->push;
        if ($persona->pull) $parts[] = 'Pull: ' . $persona->pull;
        if ($persona->anxiety) $parts[] = 'Anxiety: ' . $persona->anxiety;
        return implode('. ', $parts);
    }

    private function formatPersonasBlock(\Illuminate\Database\Eloquent\Collection $personas): string
    {
        if ($personas->isEmpty()) {
            return '(none)';
        }

        $lines = [];
        foreach ($personas as $i => $p) {
            $lines[] = ($i + 1) . ". [id={$p->id}] {$p->label}" . ($p->role ? " ({$p->role})" : '');
            if ($p->description) $lines[] = "   description: {$p->description}";
            if ($p->pain_points) $lines[] = "   pain_points: {$p->pain_points}";
            if ($p->push) $lines[] = "   push: {$p->push}";
            if ($p->pull) $lines[] = "   pull: {$p->pull}";
            if ($p->anxiety) $lines[] = "   anxiety: {$p->anxiety}";
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    protected function agentTitle(): string { return 'Audience sub-agent'; }
    protected function agentColor(): string { return 'amber'; }
}
