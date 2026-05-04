<?php

namespace App\Services;

use App\Models\ContentPiece;
use App\Models\Conversation;
use App\Models\SocialPost;
use App\Models\Team;
use App\Models\Topic;
use App\Services\Writer\Brief;
use App\Services\FetchStyleReferenceToolHandler;
use App\Services\PickAudienceToolHandler;

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
            'funnel' => self::funnelPrompt($profile, $team, $conversation),
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
                PickAudienceToolHandler::toolSchema(),
                CreateOutlineToolHandler::toolSchema(),
                FetchStyleReferenceToolHandler::toolSchema(),
                WriteBlogPostToolHandler::toolSchema(),
                ProofreadBlogPostToolHandler::toolSchema(),
                BrandIntelligenceToolHandler::fetchUrlToolSchema(),
            ],
            'funnel' => [
                SocialPostToolHandler::proposeSchema(),
                SocialPostToolHandler::updateSchema(),
                SocialPostToolHandler::deleteSchema(),
                SocialPostToolHandler::replaceAllSchema(),
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
        if ($team->countries) $lines[] = 'Target countries: ' . $team->countries;
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

        // Past topics, scored. Calibration signal for the next brainstorm.
        $pastTopics = Topic::where('team_id', $team->id)
            ->whereIn('status', ['available', 'used'])
            ->orderByDesc('created_at')
            ->limit(25)
            ->get(['title', 'score']);

        if ($pastTopics->isNotEmpty()) {
            $topicList = $pastTopics
                ->map(fn ($t) => '- '.$t->title.' — score: '.($t->score === null ? 'not yet rated' : $t->score.'/10'))
                ->implode("\n");
            $prompt .= <<<BACKLOG


## Your past topics for this team (most recent 25)
These are topics you proposed in earlier sessions. The "score" is the user's quality rating (1–10) — it's how the user tells you what good looks like. Use it to recalibrate.

- Treat unrated topics ("not yet rated") as average (≈5/10).
- Do not propose duplicates of these.
- Lean toward topic shapes that scored 7+. Avoid shapes that scored ≤4.

<past-topics>
{$topicList}
</past-topics>
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
        return self::orchestratorPrompt($profile, $hasProfile, $brief);
    }

    private static function orchestratorPrompt(string $profile, bool $hasProfile, Brief $brief): string
    {
        $statusBlock = $brief->statusSummary();

        return <<<PROMPT
You orchestrate a blog writing pipeline. You DO NOT do research, write outlines, or write blog posts yourself. You call sub-agent tools. They do the work.

## Your tools (call these in order — each fills a brief slot)
- research_topic — runs the Research sub-agent. Fills brief.research.
- pick_audience — runs the AudiencePicker sub-agent. Fills brief.audience. Requires brief.research. Returns status=skipped if no personas are configured — treat skipped as success and continue.
- create_outline — runs the Editor sub-agent. Fills brief.outline. Requires brief.research.
- fetch_style_reference — runs the StyleReference sub-agent. Fills brief.style_reference. Requires brief.outline. Returns status=skipped if no blog URL is configured — treat skipped as success and continue.
- write_blog_post — runs the Writer sub-agent. Creates a ContentPiece. Requires brief.research and brief.outline.
- proofread_blog_post(feedback) — runs the Proofread sub-agent on the existing piece. Call only when the user asks for revisions. Requires brief.content_piece_id.

## Pipeline order
Run tools back-to-back without pausing for approval:
1. research_topic
2. pick_audience
3. create_outline
4. fetch_style_reference
5. write_blog_post
6. After write_blog_post completes, send a short plain-text summary and invite the user to review.

Brief plain-text status lines between calls are fine ("Researching…", "Outlining…"). Do NOT narrate the content of tool results.

## CRITICAL: function calling
You only do work through tool calls. Never narrate research, outlines, or prose in plain text.

## Handling skipped tools
When a tool returns {status: skipped}, log it briefly ("Audience step skipped — no personas configured.") and immediately call the next tool in the pipeline.

## Brief status (current state)
<brief-status>
{$statusBlock}
</brief-status>

## Retry policy
When a tool returns {status: error, message: ...}, retry that tool ONCE per turn with an `extra_context` argument explaining what to fix. After one retry, surface the issue to the user and ask for guidance.

## Good / bad examples
GOOD: tool call → wait → tool call → wait → tool call → narrate result.
BAD: narrate "I researched the topic and found c1: …" without calling research_topic. Nothing is saved.

## Brand context (reference data — do not echo back)
<brand-profile>
{$profile}
</brand-profile>
PROMPT;
    }

    private static function funnelPrompt(string $profile, Team $team, ?Conversation $conversation): string
    {
        $piece = $conversation?->contentPiece;
        $pieceBlock = '(no content piece selected yet)';
        if ($piece) {
            $pieceBlock = "Title: {$piece->title}\n\nBody:\n{$piece->body}";
        }

        $brief = $conversation?->brief ?? [];
        $guidance = is_array($brief) && ! empty($brief['funnel_guidance']) ? $brief['funnel_guidance'] : null;

        $topicBlock = '';
        if ($piece && $piece->topic_id) {
            $topic = Topic::find($piece->topic_id);
            if ($topic) {
                $sources = is_array($topic->sources) ? implode("\n- ", $topic->sources) : '';
                $topicBlock = "\n\n## Source topic brainstorm\nTitle: {$topic->title}\nAngle: {$topic->angle}" .
                    ($sources ? "\nSources:\n- {$sources}" : '');
            }
        }

        $existing = $piece
            ? SocialPost::where('content_piece_id', $piece->id)->where('status', 'active')->orderBy('position')->get()
            : collect();
        $existingBlock = '';
        if ($existing->isNotEmpty()) {
            $lines = $existing->map(function ($p) {
                $tags = is_array($p->hashtags) ? implode(' ', array_map(fn ($t) => '#'.$t, $p->hashtags)) : '';
                $visual = $p->platform === 'short_video' ? "Video: {$p->video_treatment}" : "Image: {$p->image_prompt}";
                return "- id={$p->id} platform={$p->platform}\n  hook: {$p->hook}\n  body: {$p->body}\n  tags: {$tags}\n  {$visual}";
            })->implode("\n");
            $existingBlock = "\n\n## Current funnel for this piece\nThe user is refining the existing funnel. Default to keeping these unless asked to change. Reference posts by id when discussing them.\n\n<existing-posts>\n{$lines}\n</existing-posts>";
        }

        $guidanceBlock = $guidance ? "\n\n## User guidance\n{$guidance}" : '';

        return <<<PROMPT
You are a social-media strategist building a traffic funnel back to one piece of long-form content. You produce 3–6 platform-appropriate posts that drive readers to that piece.

## CRITICAL: function calling
You only do work through tool calls. The user sees saved posts as cards on the Social page — text-only responses with post drafts are useless.

## Your tools
- propose_posts — REQUIRED on first turn for a new funnel. Saves the initial 3–6 posts.
- update_post(id, fields) — patch one post when the user asks to fix a specific one.
- delete_post(id) — drop one post.
- replace_all_posts — soft-delete current set and create a new one (use only when the user wants a full redo).
- fetch_url — fetch a URL when needed.

## Hard rules
- Output 3–6 posts total.
- AT MOST ONE post may have platform=short_video. Most funnels will have zero.
- Every body MUST contain the literal token `[POST_URL]` exactly once at a natural CTA point. The user replaces it with the live link at posting time.
- Hashtags array — no leading `#`, no spaces inside tags.
- Non-video posts require an image_prompt. short_video posts require a video_treatment.
- Write in the same language as the source content piece.

## Per-platform best practices
- LinkedIn: long-form ok (700–1500 chars). Hook on line 1, single-sentence paragraphs, generous line breaks. End with the CTA paragraph that contains [POST_URL]. Hashtags: 3–5, professional, end of post. No emoji spam.
- Facebook: conversational, 1–3 short paragraphs. Open with a question or a vivid detail. Hashtags optional, 0–3. CTA paragraph carries [POST_URL].
- Instagram: caption-style, visual-first. Punchy hook line, then 2–4 short paragraphs separated by blank lines. Heavy hashtag block (8–15) at the very end after the [POST_URL] CTA. image_prompt should describe a single, scroll-stopping shot.
- short_video (TikTok / Reels / Shorts): a 15–45s treatment, written as Hook (0–3s) / Value beats (3–25s) / CTA (last 5s). The body field is the on-screen caption + voice-over script. video_treatment is the directorial / shot-list version. Both must contain [POST_URL] inside the body once (e.g. "Link below — [POST_URL]").

## How to mix the funnel
- Aim for 1 LinkedIn + 1 Facebook + 1–2 Instagram by default. Add a short_video only if the source piece has a strong visual or narrative hook.
- Don't repeat the same hook across posts. Vary angle: data point → personal story → contrarian take → tactical how-to.

## Source content piece
<content-piece>
{$pieceBlock}
</content-piece>{$topicBlock}{$guidanceBlock}{$existingBlock}

## Brand context (reference data — do not echo back)
<brand-profile>
{$profile}
</brand-profile>
PROMPT;
    }
}
