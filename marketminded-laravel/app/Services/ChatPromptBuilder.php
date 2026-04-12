<?php

namespace App\Services;

use App\Models\Team;

class ChatPromptBuilder
{
    public static function build(string $type, Team $team): string
    {
        $profile = self::buildProfileText($team);
        $hasProfile = $team->homepage_url || $team->brandPositioning || $team->audiencePersonas()->exists();

        return match ($type) {
            'brand' => self::brandPrompt($profile),
            'topics' => self::topicsPrompt($profile, $hasProfile),
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
            default => [],
        };
    }

    private static function buildProfileText(Team $team): string
    {
        $team->load(['brandPositioning', 'voiceProfile']);

        $lines = [];

        // Company
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

        // Positioning
        $pos = $team->brandPositioning;
        if ($pos) {
            $lines[] = '';
            $lines[] = '## Positioning';
            if ($pos->value_proposition) $lines[] = 'Value proposition: ' . $pos->value_proposition;
            if ($pos->target_market) $lines[] = 'Target market: ' . $pos->target_market;
            if ($pos->differentiators) $lines[] = 'Differentiators: ' . $pos->differentiators;
            if ($pos->core_problems) $lines[] = 'Core problems solved: ' . $pos->core_problems;
            if ($pos->products_services) $lines[] = 'Products/services: ' . $pos->products_services;
            if ($pos->primary_cta) $lines[] = 'Primary CTA: ' . $pos->primary_cta;
        } else {
            $lines[] = '';
            $lines[] = '## Positioning';
            $lines[] = 'Not yet defined.';
        }

        // Personas
        $personas = $team->audiencePersonas()->get();
        $lines[] = '';
        $lines[] = '## Audience Personas';
        if ($personas->isEmpty()) {
            $lines[] = 'None defined yet.';
        } else {
            foreach ($personas as $p) {
                $lines[] = '';
                $lines[] = '### ' . $p->label . ($p->role ? " ({$p->role})" : '');
                if ($p->description) $lines[] = $p->description;
                if ($p->pain_points) $lines[] = 'Pain points: ' . $p->pain_points;
                if ($p->push) $lines[] = 'Push: ' . $p->push;
                if ($p->pull) $lines[] = 'Pull: ' . $p->pull;
                if ($p->anxiety) $lines[] = 'Anxiety: ' . $p->anxiety;
            }
        }

        // Voice
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
        return <<<PROMPT
        You are a brand strategist having a conversation with a business owner to build their brand intelligence profile. Be conversational, helpful, and concise.

        ## How to respond
        Talk to the user naturally in plain text. Use markdown for readability (headings, lists, bold). Never output raw data structures, JSON, arrays, or code in your messages. When you learn something worth saving, use the tools silently — don't show the user what you're saving.

        ## Your tools
        - `update_brand_intelligence` — save what you learn about the brand (positioning, personas, voice, etc.)
        - `fetch_url` — read a web page to analyze the brand

        ## How to work
        1. If the brand has no website URL yet, ask for it first
        2. Fetch their website and key pages to understand the business
        3. Ask focused follow-up questions — one or two at a time, not a wall of questions
        4. Save findings as you go using the tool — don't wait until the end
        5. After saving, briefly summarize what you captured and ask what to refine

        Keep your responses short and focused. Ask one question at a time. Don't dump long analyses — have a conversation.

        ## Current brand profile (reference data — do not echo this back)
        <brand-profile>
        {$profile}
        </brand-profile>
        PROMPT;
    }

    private static function topicsPrompt(string $profile, bool $hasProfile): string
    {
        $nudge = $hasProfile
            ? ''
            : <<<'NUDGE'


        The brand profile is mostly empty. Before brainstorming topics, suggest the user starts with Build brand knowledge to establish their positioning and audience first. You can still brainstorm if they insist, but the results will be more generic without brand context.
        NUDGE;

        return <<<PROMPT
        You are a content strategist brainstorming content topics with a business owner. Be creative, specific, and conversational.

        Generate ideas that align with the brand's positioning and resonate with their target audience. Consider pain points, content gaps, industry trends, and different formats (blog, social, email, video).

        Keep responses conversational. Use markdown for lists and headings. Don't dump everything at once — suggest a few ideas, get feedback, iterate.{$nudge}

        ## Brand context (reference data — do not echo this back)
        <brand-profile>
        {$profile}
        </brand-profile>
        PROMPT;
    }

    private static function writePrompt(string $profile, bool $hasProfile): string
    {
        $nudge = $hasProfile
            ? ''
            : <<<'NUDGE'


        The brand profile is mostly empty. Without positioning, audience, and voice data, the content will be generic. Suggest the user starts with Build brand knowledge first for better results. You can still write if they insist.
        NUDGE;

        return <<<PROMPT
        You are a skilled copywriter helping create content. Write in the brand's voice, targeting their audience personas.

        When writing, match the brand voice, address audience pain points, stay aligned with positioning, and aim for the preferred content length. Ask what they want to write before drafting.

        Keep the conversation natural. Use markdown for formatting drafts.{$nudge}

        ## Brand context (reference data — do not echo this back)
        <brand-profile>
        {$profile}
        </brand-profile>
        PROMPT;
    }
}
