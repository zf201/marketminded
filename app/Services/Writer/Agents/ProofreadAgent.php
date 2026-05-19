<?php

namespace App\Services\Writer\Agents;

use App\Models\ContentPiece;
use App\Models\Team;
use App\Services\Writer\AgentResult;
use App\Services\Writer\BaseAgent;
use App\Services\Writer\Brief;

class ProofreadAgent extends BaseAgent
{
    public function __construct(
        protected string $feedback = '',
        ?string $extraContext = null,
    ) {
        parent::__construct($extraContext);
    }

    public function execute(Brief $brief, Team $team): AgentResult
    {
        if (! $brief->hasContentPiece()) {
            return AgentResult::error('No content piece to proofread. Run write_blog_post first.');
        }

        return parent::execute($brief, $team);
    }

    protected function systemPrompt(Brief $brief, Team $team): string
    {
        $piece = ContentPiece::where('team_id', $team->id)
            ->where('id', $brief->contentPieceId())
            ->firstOrFail();

        $brandProfile = $this->brandProfileBlock($team);
        $audienceBlock = $this->audienceBlock($brief);
        $styleRefBlock = $this->styleReferenceBlock($brief);
        $extra = $this->extraContextBlock();

        return <<<PROMPT
## Role & Output Contract
You are the Proofread sub-agent. Your ONLY output is a `submit_revision` tool call.
- Do NOT write any text. No planning, explaining, thinking aloud, or asking questions.
- If uncertain about any field, call the tool with best-effort values — never refuse or ask for clarification.

## Workflow
1. Read the user feedback and current post below.
2. Apply the requested changes surgically. Do NOT rewrite the whole post.
3. Match the brand voice, audience, and style references below — these are authoritative even when editing.
4. Preserve sourced facts (statistics, percentages, dates, named entities, quotes). Do not invent new facts.
5. If the feedback asks for a verifiable change (a specific stat, quote, recent example, or fact-check), you may use `web_search` and `fetch_url` to ground the edit. Otherwise skip them — most revisions are stylistic.
6. Call `submit_revision` with the revised title, body, and a change_description.

## Quality rules (apply even on small edits — content drifts otherwise)
- Use the brand voice from the brand profile. Match the rhythm and register of the style reference examples.
- Banned words/phrases: "leverage", "innovative", "streamline", "unlock", "empower", "revolutionize", "in today's fast-paced world".
- Avoid em-dashes used stylistically and passive voice as the default.
- Short paragraphs. Scannable subheadings. Benefit-focused structure.
- Write in the language of the brand profile.
- Do NOT pad or rewrite sections the feedback didn't ask about. Keep edits surgical.

## User feedback (reference data — apply these changes; do not echo back)
<user-feedback>
{$this->feedback}
</user-feedback>

## Current title (reference data — do not echo back)
<current-title>
{$piece->title}
</current-title>

## Current body (reference data — do not echo back)
<current-body>
{$piece->body}
</current-body>

## Brand profile (reference data — do not echo back)
<brand-profile>
{$brandProfile}
</brand-profile>
{$audienceBlock}
{$styleRefBlock}
{$extra}

## IMPORTANT
Call `submit_revision` now. Do not write anything — the tool call is your complete output.
PROMPT;
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

    private function styleReferenceBlock(Brief $brief): string
    {
        if (! $brief->hasStyleReference()) {
            return '';
        }

        $ref = $brief->styleReference();
        $lines = ["\n## Style reference — match this voice"];
        $lines[] = "The following are real posts from this brand's blog. Match their rhythm, sentence length, opener patterns, register, and feel. Do NOT copy sentences or facts — preserve the existing post's content; only adjust voice and the specific changes the feedback requests.";

        foreach ($ref['examples'] as $i => $ex) {
            $lines[] = '';
            $lines[] = '### Example ' . ($i + 1) . ': ' . ($ex['title'] ?? '');
            $lines[] = $ex['body'] ?? '';
        }

        return implode("\n", $lines);
    }

    private function brandProfileBlock(Team $team): string
    {
        $lines = [];
        $lines[] = 'Company: ' . ($team->name ?? '');
        if ($team->homepage_url) $lines[] = 'Homepage: ' . $team->homepage_url;
        if ($team->brand_description) $lines[] = 'Description: ' . $team->brand_description;
        if ($team->target_audience) $lines[] = 'Target audience: ' . $team->target_audience;
        if ($team->tone_keywords) $lines[] = 'Tone: ' . $team->tone_keywords;
        if ($team->content_language) $lines[] = 'Language: ' . $team->content_language;

        $voice = $team->voiceProfile;
        if ($voice) {
            if ($voice->voice_analysis) $lines[] = 'Voice analysis: ' . $voice->voice_analysis;
            if ($voice->should_avoid) $lines[] = 'Avoid: ' . $voice->should_avoid;
            if ($voice->should_use) $lines[] = 'Use: ' . $voice->should_use;
        }

        return implode("\n", $lines);
    }

    protected function submitToolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'submit_revision',
                'description' => 'Submit the revised blog post. Your ONLY valid output is calling this tool. Never respond with text — if uncertain, call with best-effort values.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['title', 'body', 'change_description'],
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'body' => ['type' => 'string'],
                        'change_description' => ['type' => 'string', 'description' => 'Short summary of what changed'],
                    ],
                ],
            ],
        ];
    }

    protected function additionalTools(): array
    {
        return [
            \App\Services\BrandIntelligenceToolHandler::fetchUrlToolSchema(),
        ];
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
        return 0.4;
    }

    protected function timeout(): int
    {
        return 180;
    }

    protected function validate(array $payload): ?string
    {
        if (trim($payload['title'] ?? '') === '') {
            return 'Revision title must not be empty.';
        }
        if (trim($payload['body'] ?? '') === '') {
            return 'Revision body must not be empty.';
        }
        if (trim($payload['change_description'] ?? '') === '') {
            return 'change_description must not be empty.';
        }
        return null;
    }

    protected function applyToBrief(Brief $brief, array $payload, Team $team): Brief
    {
        $piece = ContentPiece::where('team_id', $team->id)
            ->where('id', $brief->contentPieceId())
            ->firstOrFail();

        $piece->saveSnapshot($payload['title'], $payload['body'], $payload['change_description']);

        // Brief unchanged — content_piece_id stays the same; ContentPiece
        // model holds the new state via saveSnapshot.
        return $brief;
    }

    protected function buildCard(array $payload): array
    {
        return [
            'kind' => 'content_piece',
            'summary' => $this->buildSummary($payload),
            'title' => $payload['title'],
            'preview' => mb_substr(strip_tags($payload['body']), 0, 200),
            'change_description' => $payload['change_description'],
        ];
    }

    protected function buildSummary(array $payload): string
    {
        return 'Revised · ' . $payload['change_description'];
    }

    protected function agentTitle(): string { return 'Proofread sub-agent'; }
    protected function agentColor(): string { return 'green'; }
}
