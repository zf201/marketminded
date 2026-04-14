<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Team;
use App\Models\Topic;
use App\Services\Writer\Brief;

class ChatPromptBuilder
{
    public static function build(string $type, Team $team, ?Conversation $conversation = null): string
    {
        $profile = self::buildProfileText($team);
        $hasProfile = $team->homepage_url || $team->brandPositioning || $team->audiencePersonas()->exists();

        return match ($type) {
            'brand' => self::brandPrompt($profile),
            'topics' => self::topicsPrompt($profile, $hasProfile, $team),
            'writer' => self::writerPrompt($profile, $hasProfile, $conversation),
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
            'writer' => [
                ResearchTopicToolHandler::toolSchema(),
                CreateOutlineToolHandler::toolSchema(),
                WriteBlogPostToolHandler::toolSchema(),
                ProofreadBlogPostToolHandler::toolSchema(),
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
You are a topic recommendation engine. Your ONLY job is to find content topics and save them to the backlog using the save_topics tool.

## CRITICAL: You MUST call save_topics
Almost every response you give MUST end with a save_topics tool call. This is not optional. When you have topics to recommend, you MUST call save_topics to save them. Do NOT just list topics as text -- that is useless without calling the tool. The user sees saved topics as cards in the chat and on their Topics page. If you do not call save_topics, the topics are lost.

The ONLY time you should NOT call save_topics is when you are asking a clarifying question and have no topics yet.

## Your tools
- save_topics -- REQUIRED. Call this every time you have topics to recommend. Do not wait for permission. Save them immediately.
- fetch_url -- read a web page for deeper research
- web search -- ALWAYS use this before recommending topics

## How to work
1. Run 3-5 web searches about the brand's industry, trends, and audience
2. Pick the best 3-5 topics from your research
3. Write a brief summary of each topic (2-3 lines max per topic)
4. IMMEDIATELY call save_topics with all of them -- do not ask, just save
5. After the tool call, tell the user what you saved and ask if they want more or a different direction

## Response format
Keep it short. For each topic:

**1. [Title]**
[One sentence: the angle. One sentence: the evidence.]

Then CALL save_topics. Then write: "Saved to your backlog. Want me to explore a different angle or find more?"

## Rules
- EVERY response with topics MUST include a save_topics tool call. No exceptions.
- Maximum 3-5 topics per response.
- Keep each topic to 2-3 lines. No walls of text.
- Topics must be timely and specific. No generic filler.
- Think like a journalist: what is the hook?
- The user can delete topics they do not want from the Topics page -- so always save, never hesitate.
- Write in the same language as the brand profile below. Do NOT mix in other languages.
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

    private static function writerPrompt(string $profile, bool $hasProfile, ?Conversation $conversation): string
    {
        $brief = Brief::fromJson($conversation?->brief ?? []);
        $mode = $conversation?->writer_mode ?? 'autopilot';

        return $mode === 'checkpoint'
            ? self::orchestratorPrompt($profile, $hasProfile, $brief, 'checkpoint')
            : self::orchestratorPrompt($profile, $hasProfile, $brief, 'autopilot');
    }

    private static function orchestratorPrompt(string $profile, bool $hasProfile, Brief $brief, string $mode): string
    {
        $modeBlock = $mode === 'checkpoint'
            ? <<<'CK'
## Mode: Checkpoint

<mode>checkpoint</mode>

You Pause for user approval between stages.

1. Call research_topic. When it returns, summarize the result in 2-3 plain-text lines and ask the user to approve before continuing. WAIT.
2. Once approved, call create_outline. Summarize. Ask. WAIT.
3. Once approved, call write_blog_post. Report the result and invite review.
4. For revisions, call proofread_blog_post with the user's feedback distilled into one sentence.

Do NOT call two tools in the same turn.
CK
            : <<<'AP'
## Mode: Autopilot

<mode>autopilot</mode>

You run the chain back-to-back without asking for approval.

1. Call research_topic.
2. When it returns ok, call create_outline.
3. When it returns ok, call write_blog_post.
4. After write_blog_post, send a short plain-text summary inviting review.
5. For revisions, call proofread_blog_post with the user's feedback distilled.

Brief plain-text status lines between calls are fine ("Researching…", "Outlining…").
AP;

        $statusBlock = $brief->statusSummary();

        return <<<PROMPT
You orchestrate a blog writing pipeline. You DO NOT do research, write outlines, or write blog posts yourself. You call sub-agent tools. They do the work.

## Your tools (you call these; sub-agents fulfill them)
- research_topic — runs the Research sub-agent. Fills brief.research.
- create_outline — runs the Editor sub-agent. Fills brief.outline. Requires brief.research.
- write_blog_post — runs the Writer sub-agent. Creates a ContentPiece and fills brief.content_piece_id. Requires brief.research and brief.outline.
- proofread_blog_post(feedback) — runs the Proofread sub-agent on the existing piece. Requires brief.content_piece_id.

## CRITICAL: function calling
You only do work through tool calls. Never narrate research, outlines, or prose in plain text. Brief plain-text status updates between tool calls are fine.

## Brief status (current state)
<brief-status>
{$statusBlock}
</brief-status>

{$modeBlock}

## Retry policy
When a tool returns {status: error, message: ...}, you may retry that tool ONCE per turn with an `extra_context` argument explaining what to fix. After one retry, surface the issue to the user and ask for guidance.

## Good / bad examples
GOOD: tool call → wait → tool call → wait → tool call → narrate result.
BAD: narrate "I researched the topic and found c1: …" without ever calling research_topic. Nothing is saved. Always wrap work in tool calls.

## Brand context (reference data — do not echo back)
<brand-profile>
{$profile}
</brand-profile>
PROMPT;
    }
}
