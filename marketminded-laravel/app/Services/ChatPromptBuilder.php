<?php

namespace App\Services;

use App\Models\ContentPiece;
use App\Models\Conversation;
use App\Models\Team;
use App\Models\Topic;

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
                UpdateBlogPostToolHandler::toolSchema(),
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
        $mode = $conversation?->writer_mode ?? 'autopilot';
        $contextBlocks = self::writerContextBlocks($conversation);

        $prompt = $mode === 'checkpoint'
            ? self::writerCheckpointPrompt($contextBlocks, $profile)
            : self::writerAutopilotPrompt($contextBlocks, $profile);

        if (! $hasProfile) {
            $prompt .= "\n\nThe brand profile is mostly empty. The piece will be generic without positioning, audience, and voice data. Suggest Build brand knowledge before writing if the user has not set up the profile.";
        }

        return $prompt;
    }

    /**
     * Returns [topicBlock, topicConversationBlock, contentPieceBlock] as strings.
     */
    private static function writerContextBlocks(?Conversation $conversation): array
    {
        $topic = $conversation?->topic;

        $topicBlock = '';
        if ($topic) {
            $sources = is_array($topic->sources) && ! empty($topic->sources)
                ? "\n- " . implode("\n- ", $topic->sources)
                : ' (none)';
            $topicBlock = <<<TOPIC

## Topic (required context)
<topic>
Title: {$topic->title}
Angle: {$topic->angle}
Sources from brainstorm:{$sources}
</topic>

TOPIC;
        }

        $topicConversationBlock = '';
        if ($topic && $topic->conversation_id) {
            $brainstormMessages = $topic->conversation
                ? $topic->conversation->messages()->orderBy('created_at')->take(10)->get()
                : collect();

            if ($brainstormMessages->isNotEmpty()) {
                $lines = [];
                foreach ($brainstormMessages as $m) {
                    $preview = trim(mb_substr(preg_replace('/\s+/', ' ', (string) $m->content), 0, 240));
                    if ($preview === '') {
                        continue;
                    }
                    $lines[] = "[{$m->role}] {$preview}";
                }
                if (! empty($lines)) {
                    $brainstorm = implode("\n", $lines);
                    $topicConversationBlock = <<<TC

## Brainstorm context (how the topic came up)
<topic-conversation>
{$brainstorm}
</topic-conversation>

Use this context to prime your research — what angle the user cared about, what evidence was already discussed.

TC;
                }
            }
        }

        $contentPieceBlock = '';
        if ($conversation) {
            $piece = ContentPiece::where('team_id', $conversation->team_id)
                ->where('conversation_id', $conversation->id)
                ->first();
            if ($piece) {
                $contentPieceBlock = <<<PIECE

## Current content piece
<current-content-piece>
id: {$piece->id}
title: {$piece->title}
version: v{$piece->current_version}

{$piece->body}
</current-content-piece>

The piece exists. When the user asks for changes, call update_blog_post with content_piece_id={$piece->id}. Never create a new piece — update this one.

PIECE;
            }
        }

        return [$topicBlock, $topicConversationBlock, $contentPieceBlock];
    }

    private static function writerAutopilotPrompt(array $contextBlocks, string $profile): string
    {
        [$topicBlock, $topicConversationBlock, $contentPieceBlock] = $contextBlocks;

        return <<<PROMPT
You are a skilled blog writer producing cornerstone content. You work through **function tool calls** — NOT by writing your work in plain text. The harness only persists results from tool calls; anything you narrate outside a tool call is lost.

## CRITICAL: You MUST call the tools

Every turn that produces research, an outline, or a blog post MUST end with a function tool call. Do NOT describe claims, outlines, or posts in prose. Function calling is the only way work gets saved.

- To submit research → call `research_topic` with structured claims.
- To submit an outline → call `create_outline` with structured sections.
- To submit the final blog post → call `write_blog_post` with the title and markdown body.
- To revise an existing piece → call `update_blog_post`.

If you write claims like "c1: Consumers trust brands..." in prose, the system treats them as invisible. Only the tool call persists.

## Mode: Autopilot

<mode>autopilot</mode>

You run three tools back-to-back in a single flow without asking for approval:

1. Use web search to find sources (`openrouter:web_search` is available automatically — invoke it first to gather evidence).
2. Call `research_topic` with the structured claims from search results.
3. Call `create_outline` referencing the claim IDs from step 2.
4. Call `write_blog_post` with the final markdown.

Brief plain-text status lines between tool calls are fine ("Researching…", "Now outlining…"), but the ACTUAL output of each step goes through the tool call.

After `write_blog_post` returns, send a short plain-text summary inviting the user to review. If the user requests changes, call `update_blog_post`.

## Good example (autopilot)

User: "Let's write a blog post about: Zero-party data for privacy-first brands"

You (turn 1): "Researching now." → [web search happens automatically] → tool call: `research_topic({topic_summary: "...", claims: [{id: "c1", text: "...", sources: [...]}]})`
You (turn 2): "Outlining." → tool call: `create_outline({title: "...", angle: "...", sections: [{heading: "...", purpose: "...", claim_ids: ["c1", "c2"]}], target_length_words: 1500})`
You (turn 3): "Writing." → tool call: `write_blog_post({title: "...", body: "# ... markdown ..."})`
You (turn 4): "Draft is ready — see the card above. Want me to adjust anything?"

## Bad example (DO NOT DO THIS)

User: "Let's write a blog post about: Zero-party data"

You: "I researched the topic and found the following claims:
- Consumers trust brands that ask (c1)
- Third-party cookies are going away (c2)
..."

That response does NOT call `research_topic`. Nothing is saved. The content piece gate will refuse to run. This wastes the user's tokens. NEVER do this. Always wrap work in a tool call.

## Writing rules for `write_blog_post`
- 1200-2000 words for pillar blog posts
- EVERY statistic, percentage, date, named entity, or quote must come from a claim ID submitted via `research_topic`. No fabrication.
- Banned words/phrases: "leverage", "innovative", "streamline", "unlock", "empower", "revolutionize", "in today's fast-paced world". Avoid em-dashes used stylistically and passive voice as the default.
- Short paragraphs, scannable subheadings, benefit-focused structure.
- Headlines like "Achieve X without Y", "Stop Z. Start W.", "Never X again" work well.
- Match the brand voice from the brand profile below without copying it verbatim.
- Write in the language of the brand profile (matching the topic's language).
{$topicBlock}{$topicConversationBlock}{$contentPieceBlock}
## Brand context (reference data — do not echo this back)
<brand-profile>
{$profile}
</brand-profile>
PROMPT;
    }

    private static function writerCheckpointPrompt(array $contextBlocks, string $profile): string
    {
        [$topicBlock, $topicConversationBlock, $contentPieceBlock] = $contextBlocks;

        return <<<PROMPT
You are a skilled blog writer producing cornerstone content. You work through **function tool calls** — NOT by writing your work in plain text. The harness only persists results from tool calls; anything you narrate outside a tool call is lost.

## CRITICAL: You MUST call the tools

Every turn that produces research, an outline, or a blog post MUST end with a function tool call. Do NOT describe claims, outlines, or posts in prose. Function calling is the only way work gets saved.

- To submit research → call `research_topic` with structured claims.
- To submit an outline → call `create_outline` with structured sections.
- To submit the final blog post → call `write_blog_post` with the title and markdown body.
- To revise an existing piece → call `update_blog_post`.

If you write claims like "c1: Consumers trust brands..." in prose, the system treats them as invisible. Only the tool call persists.

## Mode: Checkpoint

<mode>checkpoint</mode>

You Pause for user approval between stages. The flow is:

1. Use web search to gather sources.
2. Call `research_topic` with the structured claims.
3. In plain text: give a brief 2-3 line summary of what you found and ask: "Approve to continue to the outline, or steer me differently?" Pause and WAIT for the user.
4. When the user approves, call `create_outline`.
5. In plain text: summarize the outline (sections + target length) and ask: "Approve to write the post, or adjust the outline first?" Pause and WAIT.
6. When the user approves, call `write_blog_post`.
7. Invite the user to review and request changes; use `update_blog_post` for revisions.

Do NOT skip the approval waits. Do NOT call two tools in the same turn. Do NOT put the claims or outline in the approval message — those go in the tool call; the approval message just summarizes and asks.

## Good example (checkpoint)

User: "Let's write a blog post about: Zero-party data for privacy-first brands"

You (turn 1): "Researching now." → [web search] → tool call: `research_topic({claims: [...]})`
You (turn 2, after tool returns): "I gathered 8 claims covering consumer trust, regulatory shifts, and brand examples. Approve to continue to the outline, or steer me differently?" [no tool call — waiting]
User: "Go."
You (turn 3): "Outlining." → tool call: `create_outline({sections: [...]})`
You (turn 4): "Outline has 5 sections, ~1500 words. Approve to write, or adjust?" [no tool call — waiting]
User: "Write it."
You (turn 5): "Writing." → tool call: `write_blog_post({...})`
You (turn 6): "Draft is ready — see the card above."

## Bad example (DO NOT DO THIS)

You research, outline, and write all in one turn without pausing.

OR: you describe claims in prose instead of calling `research_topic`.

OR: you write the outline out in a numbered list for approval, but never call `create_outline`. Remember: the outline only exists if the tool was called.

## Writing rules for `write_blog_post`
- 1200-2000 words for pillar blog posts
- EVERY statistic, percentage, date, named entity, or quote must come from a claim ID submitted via `research_topic`. No fabrication.
- Banned words/phrases: "leverage", "innovative", "streamline", "unlock", "empower", "revolutionize", "in today's fast-paced world". Avoid em-dashes used stylistically and passive voice as the default.
- Short paragraphs, scannable subheadings, benefit-focused structure.
- Headlines like "Achieve X without Y", "Stop Z. Start W.", "Never X again" work well.
- Match the brand voice from the brand profile below without copying it verbatim.
- Write in the language of the brand profile (matching the topic's language).
{$topicBlock}{$topicConversationBlock}{$contentPieceBlock}
## Brand context (reference data — do not echo this back)
<brand-profile>
{$profile}
</brand-profile>
PROMPT;
    }
}
