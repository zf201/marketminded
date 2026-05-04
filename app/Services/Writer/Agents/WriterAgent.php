<?php

namespace App\Services\Writer\Agents;

use App\Models\ContentPiece;
use App\Models\Team;
use App\Models\Topic;
use App\Services\Writer\AgentResult;
use App\Services\Writer\BaseAgent;
use App\Services\Writer\Brief;

class WriterAgent extends BaseAgent
{
    public function execute(Brief $brief, Team $team): AgentResult
    {
        if (! $brief->hasResearch()) {
            return AgentResult::error('Cannot write without research. Run research_topic first.');
        }
        if (! $brief->hasOutline()) {
            return AgentResult::error('Cannot write without an outline. Run create_outline first.');
        }
        if ($brief->conversationId() === null) {
            return AgentResult::error('Writer requires conversation_id on the brief.');
        }

        if ($brief->hasContentPiece()) {
            $existing = ContentPiece::where('id', $brief->contentPieceId())
                ->where('team_id', $team->id)
                ->first();

            if ($existing !== null && $existing->current_version >= 1) {
                return AgentResult::ok(
                    brief: $brief,
                    cardPayload: $this->buildCardFromPiece($existing),
                    summary: 'Draft already exists · v' . $existing->current_version,
                );
            }
        }

        return parent::execute($brief, $team);
    }

    protected function systemPrompt(Brief $brief, Team $team): string
    {
        $topic = $brief->topic();
        $research = $brief->research();
        $outline = $brief->outline();

        $claimsBlock = collect($research['claims'])
            ->map(fn ($c) => "- {$c['id']} ({$c['type']}): {$c['text']}")
            ->implode("\n");

        $sourcesBlock = collect($research['sources'])
            ->map(fn ($s) => "- {$s['id']}: {$s['title']} ({$s['url']})")
            ->implode("\n");

        $outlineBlock = "Angle: {$outline['angle']}\nTarget length: {$outline['target_length_words']} words\n\nSections:\n"
            . collect($outline['sections'])
                ->map(fn ($s, $i) => sprintf("%d. %s — %s [%s]",
                    $i + 1,
                    $s['heading'],
                    $s['purpose'],
                    implode(', ', $s['claim_ids']),
                ))
                ->implode("\n");

        $brandProfile = $this->brandProfileBlock($team);
        $audienceBlock = $this->audienceBlock($brief);
        $styleRefBlock = $this->styleReferenceBlock($brief);
        $extra = $this->extraContextBlock();

        return <<<PROMPT
## Role & Output Contract
You are the Writer sub-agent. Your ONLY output is a `submit_blog_post` tool call containing the complete post.
- Do NOT write the blog post as text. Write it inside the tool call's `body` field.
- No preamble, no commentary, no "here is the post" — just call the tool.
- If uncertain about any aspect, write your best version and call the tool — never refuse or ask for clarification.

## Workflow
1. Read the outline, research claims, and brand profile below.
2. Write a publishable blog post following the outline.
3. Call `submit_blog_post` with the title and full body.

## Quality rules
- Target length: {$outline['target_length_words']} words ±10%.
- Follow the outline section order. Each section uses the claims listed
  in [brackets] for its claim_ids.
- EVERY statistic, percentage, date, named entity, or quote must come
  from a claim by id. Never fabricate facts.
- Use the brand voice from the brand profile.
- Banned words/phrases: "leverage", "innovative", "streamline", "unlock",
  "empower", "revolutionize", "in today's fast-paced world".
- Avoid em-dashes used stylistically and passive voice as the default.
- Short paragraphs. Scannable subheadings. Benefit-focused structure.
- Write in the language of the brand profile.

## Topic
Title: {$topic['title']}
Angle: {$topic['angle']}

## Outline
{$outlineBlock}

## Research claims (cite by id implicitly through the facts you use)
{$claimsBlock}

## Sources (do NOT cite inline; the platform handles attribution)
{$sourcesBlock}

## Brand profile
{$brandProfile}
{$audienceBlock}
{$styleRefBlock}
{$extra}

## IMPORTANT
Call `submit_blog_post` now with the complete title and body. Do not write anything outside the tool call.
PROMPT;
    }

    protected function submitToolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'submit_blog_post',
                'description' => 'Submit the finished blog post. Your ONLY valid output is calling this tool. Never respond with text — call this with the full title and body when done.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['title', 'body'],
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'body' => ['type' => 'string', 'description' => 'Full blog post in markdown.'],
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
        return $team->powerful_model;
    }

    protected function temperature(): float
    {
        return 0.6;
    }

    /**
     * Writing a 1200-2000 word blog post with a big prompt takes longer than
     * the 120s default — especially on the powerful model tier. Bump to 5
     * minutes to avoid cURL timeouts that have nothing to do with the LLM
     * actually failing.
     */
    protected function timeout(): int
    {
        // Reasoning models (e.g. deepseek-reasoner / deepseek-v4-pro) emit a
        // long chain-of-thought trace before the blog post body. For a
        // 1000-word post that can take 5–8 minutes. 300s was clipping at the
        // edge; 600s leaves headroom without the orchestrator getting stuck
        // for too long if the model genuinely hangs.
        return 600;
    }

    protected function validate(array $payload): ?string
    {
        $title = trim($payload['title'] ?? '');
        $body = $payload['body'] ?? '';

        if ($title === '') {
            return 'Blog post title must not be empty.';
        }

        $wordCount = str_word_count(strip_tags($body));
        if ($wordCount < 800) {
            return "Blog post body must be at least 800 words (got {$wordCount}).";
        }

        return null;
    }

    protected function applyToBrief(Brief $brief, array $payload, Team $team): Brief
    {
        $topic = $brief->topic();

        $piece = ContentPiece::firstOrCreate(
            ['conversation_id' => $brief->conversationId()],
            [
                'team_id' => $team->id,
                'topic_id' => $topic['id'] ?? null,
                'title' => '',
                'body' => '',
                'status' => 'draft',
                'platform' => 'blog',
                'format' => 'pillar',
                'current_version' => 0,
            ],
        );

        if ($piece->current_version === 0) {
            $piece->saveSnapshot($payload['title'], $payload['body'], 'Initial draft');
        }

        if (! empty($topic['id'])) {
            Topic::where('id', $topic['id'])->update(['status' => 'used']);
        }

        return $brief->withContentPieceId($piece->id);
    }

    protected function buildCard(array $payload): array
    {
        $body = $payload['body'];
        return [
            'kind' => 'content_piece',
            'summary' => $this->buildSummary($payload),
            'title' => $payload['title'],
            'preview' => mb_substr(strip_tags($body), 0, 200),
            'word_count' => str_word_count(strip_tags($body)),
        ];
    }

    protected function buildSummary(array $payload): string
    {
        return 'Draft created · v1 · ' . str_word_count(strip_tags($payload['body'])) . ' words';
    }

    protected function buildCardFromPiece(ContentPiece $piece): array
    {
        return [
            'kind' => 'content_piece',
            'summary' => 'Draft already exists · v' . $piece->current_version,
            'title' => $piece->title,
            'preview' => mb_substr(strip_tags($piece->body), 0, 200),
            'word_count' => str_word_count(strip_tags($piece->body)),
            'cost' => 0.0,
            'input_tokens' => 0,
            'output_tokens' => 0,
        ];
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
        $lines[] = "The following are real posts from this brand's blog. Match their rhythm, sentence length, opener patterns, register, and feel. Do NOT copy sentences or facts — the new post's content comes from the claims block.";

        foreach ($ref['examples'] as $i => $ex) {
            $lines[] = '';
            $lines[] = '### Example ' . ($i + 1) . ': ' . ($ex['title'] ?? '');
            $lines[] = $ex['body'] ?? '';
        }

        return implode("\n", $lines);
    }

    protected function brandProfileBlock(Team $team): string
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

    protected function agentTitle(): string { return 'Writer sub-agent'; }
    protected function agentColor(): string { return 'green'; }
}
