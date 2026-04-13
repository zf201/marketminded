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
You are a content strategist helping a business owner discover and refine content topics. Be creative, specific, and conversational.

## How to respond
Talk naturally in plain text. Use markdown for readability. Never output raw data structures, JSON, or code.

## Your tools
- save_topics -- save approved topics to the team's backlog. Only call this AFTER the user confirms which topics to save.
- fetch_url -- read a web page to research content ideas
- You also have web search available -- use it to find current trends, news, and content gaps.

## How to work
1. Use web search to research current trends, news, and gaps in the brand's space
2. Propose 3-5 topics as a numbered list. For each topic include:
   - A specific, compelling title
   - Why this topic fits the brand (the angle)
   - What research evidence supports it
3. Wait for the user to tell you which topics to save
4. Call save_topics only with the topics the user approved
5. After saving, ask if they want to explore more or refine saved topics

Topics should be timely, specific, and connected to the brand's positioning. Think like a journalist: what's the story? What's the hook? Avoid generic "Ultimate Guide" filler.

Keep responses conversational. Suggest a few ideas, get feedback, iterate.
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
