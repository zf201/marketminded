<?php

namespace App\Services;

use App\Models\Team;
use App\Models\Topic;

class ChatPromptBuilder
{
    public static function build(string $type, Team $team): string
    {
        $profile = self::buildProfileText($team);
        $hasProfile = $team->homepage_url || $team->brandPositioning || $team->audiencePersonas()->exists();

        return match ($type) {
            'brand' => self::brandPrompt($profile),
            'topics' => self::topicsPrompt($profile, $hasProfile, $team),
            'write' => self::writePrompt($profile, $hasProfile),
            default => 'You are a helpful AI assistant.',
        };
    }

    public static function tools(string $type): array
    {
        return match ($type) {
            'brand' => [
                BrandIntelligenceToolHandler::toolSchema(),
                BrandIntelligenceToolHandler::fetchUrlToolSchema(),
            ],
            'topics' => [
                TopicToolHandler::toolSchema(),
                BrandIntelligenceToolHandler::fetchUrlToolSchema(),
            ],
            default => [],
        };
    }

    private static function buildProfileText(Team $team): string
    {
        $team->load(['brandPositioning', 'voiceProfile']);

        $lines = [];

        $lines[] = '## Company';
        $lines[] = 'Homepage: ' . ($team->homepage_url ?: 'not set');
        if ($team->blog_url) $lines[] = 'Blog: ' . $team->blog_url;
        if ($team->brand_description) $lines[] = 'Description: ' . $team->brand_description;
        if ($team->target_audience) $lines[] = 'Target audience: ' . $team->target_audience;
        if ($team->tone_keywords) $lines[] = 'Tone: ' . $team->tone_keywords;
        if ($team->content_language) $lines[] = 'Language: ' . $team->content_language;
        if (! empty($team->product_urls)) $lines[] = 'Product pages: ' . implode(', ', $team->product_urls);
        if (! empty($team->competitor_urls)) $lines[] = 'Competitors: ' . implode(', ', $team->competitor_urls);
        if (! empty($team->style_reference_urls)) $lines[] = 'Style references: ' . implode(', ', $team->style_reference_urls);

        $pos = $team->brandPositioning;
        $lines[] = '';
        $lines[] = '## Positioning';
        if ($pos) {
            if ($pos->value_proposition) $lines[] = 'Value proposition: ' . $pos->value_proposition;
            if ($pos->target_market) $lines[] = 'Target market: ' . $pos->target_market;
            if ($pos->differentiators) $lines[] = 'Differentiators: ' . $pos->differentiators;
            if ($pos->core_problems) $lines[] = 'Core problems solved: ' . $pos->core_problems;
            if ($pos->products_services) $lines[] = 'Products/services: ' . $pos->products_services;
            if ($pos->primary_cta) $lines[] = 'Primary CTA: ' . $pos->primary_cta;
        } else {
            $lines[] = 'Not yet defined.';
        }

        $personas = $team->audiencePersonas()->get();
        $lines[] = '';
        $lines[] = '## Audience Personas';
        if ($personas->isEmpty()) {
            $lines[] = 'None defined yet.';
        } else {
            foreach ($personas as $p) {
                $lines[] = '';
                $lines[] = '### ' . $p->label . ($p->role ? ' (' . $p->role . ')' : '');
                if ($p->description) $lines[] = $p->description;
                if ($p->pain_points) $lines[] = 'Pain points: ' . $p->pain_points;
                if ($p->push) $lines[] = 'Push: ' . $p->push;
                if ($p->pull) $lines[] = 'Pull: ' . $p->pull;
                if ($p->anxiety) $lines[] = 'Anxiety: ' . $p->anxiety;
            }
        }

        $voice = $team->voiceProfile;
        $lines[] = '';
        $lines[] = '## Voice & Tone';
        if ($voice) {
            if ($voice->voice_analysis) $lines[] = 'Analysis: ' . $voice->voice_analysis;
            if ($voice->content_types) $lines[] = 'Content types: ' . $voice->content_types;
            if ($voice->should_avoid) $lines[] = 'Avoid: ' . $voice->should_avoid;
            if ($voice->should_use) $lines[] = 'Use: ' . $voice->should_use;
            if ($voice->style_inspiration) $lines[] = 'Style inspiration: ' . $voice->style_inspiration;
            if ($voice->preferred_length) $lines[] = 'Preferred length: ' . $voice->preferred_length . ' words';
        } else {
            $lines[] = 'Not yet defined.';
        }

        return implode("\n", $lines);
    }

    private static function brandPrompt(string $profile): string
    {
        return <<<'PROMPT'
You are a brand strategist having a conversation with a business owner to build their brand intelligence profile. Be conversational, helpful, and concise.

## How to respond
Talk to the user naturally in plain text. Use markdown for readability (headings, lists, bold). Never output raw data structures, JSON, arrays, or code in your messages. When you learn something worth saving, use the tools silently -- do not show the user what you are saving.

## Your tools
- update_brand_intelligence -- save what you learn about the brand (positioning, personas, voice, etc.)
- fetch_url -- read a web page to analyze the brand

## How to work
1. If the brand has no website URL yet, ask for it first
2. Fetch their website and key pages to understand the business
3. Ask focused follow-up questions -- one or two at a time, not a wall of questions
4. Save findings as you go using the tool -- do not wait until the end
5. After saving, briefly summarize what you captured and ask what to refine

Keep your responses short and focused. Ask one question at a time. Do not dump long analyses -- have a conversation.

## Current brand profile (reference data -- do not echo this back)
<brand-profile>
PROMPT
        . $profile . <<<'PROMPT'

</brand-profile>
PROMPT;
    }

    private static function topicsPrompt(string $profile, bool $hasProfile, Team $team): string
    {
        $prompt = <<<'PROMPT'
You are a topic recommendation engine for a business owner. Your job is to research and propose content topics they can save to their backlog.

## IMPORTANT: You MUST use web search
Before proposing any topics, you MUST run web searches to find CURRENT trends, news, data, and conversations in the brand's industry. Do NOT propose topics from general knowledge alone. Every topic must be backed by something you found through research.

## Your tools
- save_topics -- save topics to the backlog. Call this when the user says to save (e.g. "save all", "save 1 and 3", "yes", "looks good").
- fetch_url -- read a web page for deeper research
- web search -- ALWAYS use this first to find current trends and news

## How to work
1. Run 3-5 focused web searches about the brand's industry, audience pain points, and current trends
2. Based on your research, propose exactly 3-5 topics in this format:

**1. [Title]**
[One sentence: why this fits the brand]
[One sentence: the research evidence]

3. ALWAYS end your response with a save prompt. Use this exact format:

---
**Save to backlog?** Reply "save all" or pick numbers like "save 1, 3"

4. When the user approves, call save_topics immediately with the approved topics
5. After saving, propose more topics or ask what direction to explore next

## Rules
- Your primary goal is recommending topics. Every response should either propose topics or ask a clarifying question to propose better ones.
- ALWAYS include the save prompt after listing topics. Never skip it.
- Maximum 3-5 topics per response. Never more.
- Keep each topic to 2-3 lines. No paragraphs.
- Topics must be timely and specific, not generic ("Ultimate Guide to X" is banned).
- Think like a journalist: what is the story? What is the hook?
PROMPT;

        if (! $hasProfile) {
            $prompt .= <<<'NUDGE'


The brand profile is mostly empty. Before brainstorming topics, suggest the user starts with Build brand knowledge to establish their positioning and audience first. You can still brainstorm if they insist, but the results will be more generic without brand context.
NUDGE;
        }

        // Add existing backlog to avoid duplicates
        $existingTopics = Topic::where('team_id', $team->id)
            ->whereIn('status', ['available', 'used'])
            ->pluck('title')
            ->toArray();

        if (! empty($existingTopics)) {
            $topicList = implode("\n", array_map(fn ($t) => "- {$t}", $existingTopics));
            $prompt .= <<<BACKLOG


## Existing topics in backlog (do not propose duplicates)
<existing-topics>
{$topicList}
</existing-topics>
BACKLOG;
        }

        $prompt .= <<<'PROMPT'


## Brand context (reference data -- do not echo this back)
<brand-profile>
PROMPT;

        $prompt .= $profile;

        $prompt .= <<<'PROMPT'

</brand-profile>
PROMPT;

        return $prompt;
    }

    private static function writePrompt(string $profile, bool $hasProfile): string
    {
        $prompt = <<<'PROMPT'
You are a skilled copywriter helping create content. Write in the brand voice, targeting their audience personas.

When writing, match the brand voice, address audience pain points, stay aligned with positioning, and aim for the preferred content length. Ask what they want to write before drafting.

Keep the conversation natural. Use markdown for formatting drafts.
PROMPT;

        if (! $hasProfile) {
            $prompt .= <<<'NUDGE'


The brand profile is mostly empty. Without positioning, audience, and voice data, the content will be generic. Suggest the user starts with Build brand knowledge first for better results. You can still write if they insist.
NUDGE;
        }

        $prompt .= <<<'PROMPT'


## Brand context (reference data -- do not echo this back)
<brand-profile>
PROMPT;

        $prompt .= $profile;

        $prompt .= <<<'PROMPT'

</brand-profile>
PROMPT;

        return $prompt;
    }
}
