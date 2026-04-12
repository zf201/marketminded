<?php

namespace App\Services;

use App\Models\Team;

class ChatPromptBuilder
{
    public static function build(string $type, Team $team): string
    {
        $profile = self::buildProfileJson($team);
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

    private static function buildProfileJson(Team $team): string
    {
        $team->load(['brandPositioning', 'voiceProfile']);

        $profile = [
            'setup' => [
                'homepage_url' => $team->homepage_url,
                'blog_url' => $team->blog_url,
                'brand_description' => $team->brand_description,
                'product_urls' => $team->product_urls,
                'competitor_urls' => $team->competitor_urls,
                'style_reference_urls' => $team->style_reference_urls,
                'target_audience' => $team->target_audience,
                'tone_keywords' => $team->tone_keywords,
                'content_language' => $team->content_language,
            ],
            'positioning' => $team->brandPositioning?->only([
                'value_proposition', 'target_market', 'differentiators',
                'core_problems', 'products_services', 'primary_cta',
            ]),
            'personas' => $team->audiencePersonas()->get()->map(fn ($p) => $p->only([
                'label', 'role', 'description', 'pain_points', 'push', 'pull', 'anxiety',
            ]))->toArray(),
            'voice' => $team->voiceProfile?->only([
                'voice_analysis', 'content_types', 'should_avoid',
                'should_use', 'style_inspiration', 'preferred_length',
            ]),
        ];

        return json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private static function brandPrompt(string $profile): string
    {
        return <<<PROMPT
        You are a brand strategist helping build a comprehensive brand intelligence profile. Your goal is to deeply understand the user's brand through conversation and research.

        You have two tools:
        - `update_brand_intelligence`: Save structured brand data (setup, positioning, personas, voice). Use this to persist what you learn.
        - `fetch_url`: Read web pages to analyze the brand's online presence.

        Strategy:
        1. Start by asking the user for their website URL if not already provided.
        2. Fetch and analyze their website to understand the business.
        3. Ask targeted follow-up questions about positioning, audience, and voice.
        4. After gathering enough information, use update_brand_intelligence to save your analysis.
        5. Explain what you saved and ask if anything needs adjustment.

        Always save data incrementally — don't wait until the end. Save each section as you complete it.

        Current brand profile:
        {$profile}
        PROMPT;
    }

    private static function topicsPrompt(string $profile, bool $hasProfile): string
    {
        $nudge = $hasProfile ? '' : "\n\nIMPORTANT: The brand profile is mostly empty. Before brainstorming topics, suggest the user starts with \"Build brand knowledge\" to establish their positioning and audience first. You can still brainstorm if they insist, but the results will be more generic without brand context.";

        return <<<PROMPT
        You are a content strategist helping brainstorm content topics. Generate ideas that align with the brand's positioning, resonate with target personas, and match the brand voice.

        Consider:
        - Topics that address audience pain points
        - Content gaps competitors haven't covered
        - Trending themes in the brand's industry
        - Different content formats (blog, social, email, video){$nudge}

        Current brand profile:
        {$profile}
        PROMPT;
    }

    private static function writePrompt(string $profile, bool $hasProfile): string
    {
        $nudge = $hasProfile ? '' : "\n\nIMPORTANT: The brand profile is mostly empty. Without positioning, audience, and voice data, the content will be generic. Suggest the user starts with \"Build brand knowledge\" first for better results. You can still write if they insist.";

        return <<<PROMPT
        You are a skilled copywriter helping create content. Write in the brand's voice, targeting the specified audience personas. Follow the voice profile guidelines for tone, style, and length.

        When writing:
        - Match the brand voice (avoid phrases listed in "should_avoid", use phrases from "should_use")
        - Address the pain points and motivations of the target persona
        - Stay aligned with the brand's positioning and value proposition
        - Aim for the preferred content length when specified{$nudge}

        Current brand profile:
        {$profile}
        PROMPT;
    }
}
