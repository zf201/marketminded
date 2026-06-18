<?php

namespace App\Services;

use App\Models\CalendarEntry;
use App\Models\ContentPiece;
use App\Models\SocialPost;
use App\Models\Team;
use App\Models\Topic;

class DraftPostToolHandler
{
    public static function toolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'draft_post',
                'description' => 'Run a single-post drafting sub-agent. Returns one draft (title, image_headline, image_prompt, content) tailored to a specific platform. The orchestrator then decides whether to save it via propose_entries or update_entry.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['platform', 'idea'],
                    'properties' => [
                        'platform' => [
                            'type' => 'string',
                            'description' => 'Target platform (e.g. linkedin, instagram, facebook).',
                        ],
                        'idea' => [
                            'type' => 'string',
                            'description' => 'Short description of the post idea or angle in the team language.',
                        ],
                        'apply_to_entry_id' => [
                            'type' => 'integer',
                            'description' => 'If set, the draft is saved directly into this existing calendar entry (overwrites title, image_headline, image_prompt, content). Use this whenever you want the draft to land on a specific placeholder day. Omit to just see the draft without saving.',
                        ],
                        'source_topic_id' => [
                            'type' => 'integer',
                            'description' => 'Optional Topic id to base the post on.',
                        ],
                        'source_content_piece_id' => [
                            'type' => 'integer',
                            'description' => 'Optional ContentPiece id to summarise.',
                        ],
                        'source_social_post_id' => [
                            'type' => 'integer',
                            'description' => 'Optional SocialPost id to rework.',
                        ],
                        'source_url' => [
                            'type' => 'string',
                            'description' => 'Optional reference URL the sub-agent should fetch for context.',
                        ],
                        'extra_guidance' => [
                            'type' => 'string',
                            'description' => 'Tone, CTA, or constraint notes for the drafter.',
                        ],
                    ],
                ],
            ],
        ];
    }

    public function execute(Team $team, array $args, ?ConversationBus $bus = null): array
    {
        $platform = trim((string) ($args['platform'] ?? ''));
        $idea = trim((string) ($args['idea'] ?? ''));
        if ($platform === '' || $idea === '') {
            return ['status' => 'error', 'message' => 'platform and idea are required.'];
        }

        $sourceBlock = $this->buildSourceBlock($team, $args);
        $extraGuidance = trim((string) ($args['extra_guidance'] ?? ''));
        $profile = $this->buildBrandBlock($team);

        $bus?->publish('subagent_started', ['agent' => 'drafter', 'title' => __('Drafting :platform post', ['platform' => $platform]), 'color' => 'amber']);

        $systemPrompt = <<<PROMPT
## Role
You are the SinglePostDrafter sub-agent. Your ONLY output is a single `submit_post` tool call. Do not write text, planning, or explanations.

## Task
Draft ONE social media post for the platform "{$platform}". Tailor length, hook, and tone to that platform. Match the brand voice from the brand profile below.

## Output fields (all required unless noted)
- `title` — short internal label (4–8 words) for the calendar entry. Not part of the post copy.
- `image_headline` — short text that goes on the post image (≤ 8 words). Optional but encouraged.
- `image_prompt` — one-sentence visual direction for whoever creates the image. Optional.
- `content` — the actual post body in the brand's language. Tailor format to the platform.

## Platform rules
- linkedin: 700–1500 chars, professional hook on line 1, generous line breaks.
- instagram: punchy hook, 2–4 short paragraphs separated by blank lines.
- facebook: conversational, 1–3 short paragraphs.
- Any other platform: short and direct.

## Writing discipline
- Hashtags: 2–5 relevant hashtags maximum, on EVERY platform. Place them only at the very end of the post, never scattered through the body. Skip them entirely if none are genuinely relevant.
- Do NOT wrap the `content` in quotation marks. The content field IS the post, not a quote — no surrounding "", «», or '' around the whole body.
- Avoid em dashes (—) and en dashes (–). Use commas, full stops, or parentheses instead. Overusing dashes is a common AI tell and reads unnaturally.
- Write in correct, natural grammar for the brand's language. Re-read the draft for agreement, declensions, and word order before submitting — this matters most in non-English languages.
- Not every post is a sales pitch. Honour the brand's content guidance: many posts should educate, tell a story, or spark engagement with no call to action at all. Only push a CTA when the idea genuinely calls for it.

## Idea
{$idea}

## Extra guidance
{$extraGuidance}

## Source context
{$sourceBlock}

## Brand profile (reference — do not echo back)
<brand-profile>
{$profile}
</brand-profile>

## IMPORTANT
Call `submit_post` now with all four fields. Do not output text.
PROMPT;

        $submitSchema = [
            'type' => 'function',
            'function' => [
                'name' => 'submit_post',
                'description' => 'Submit the drafted post.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['title', 'content'],
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'image_headline' => ['type' => 'string'],
                        'image_prompt' => ['type' => 'string'],
                        'content' => ['type' => 'string'],
                    ],
                ],
            ],
        ];

        $client = new OpenRouterClient(
            apiKey: $team->ai_api_key,
            model: $team->powerful_model,
            urlFetcher: new UrlFetcher,
            maxIterations: 3,
            baseUrl: $team->ai_api_url ?? 'https://openrouter.ai/api/v1',
            provider: $team->ai_provider ?? 'openrouter',
            reasoningEffort: 'high',
        );

        try {
            $result = $client->chat(
                messages: [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => 'Draft the post now by calling submit_post.'],
                ],
                tools: [$submitSchema],
                toolChoice: null,
                temperature: 0.6,
                useServerTools: false,
                timeout: 90,
            );
        } catch (\Throwable $e) {
            $bus?->publish('subagent_error', ['agent' => 'drafter', 'message' => $e->getMessage()]);
            return ['status' => 'error', 'message' => $e->getMessage()];
        }

        $payload = is_array($result->data) ? $result->data : null;
        if (! $payload || empty($payload['content'])) {
            $bus?->publish('subagent_error', ['agent' => 'drafter', 'message' => 'Drafter did not submit a post.']);
            return ['status' => 'error', 'message' => 'Drafter did not submit a post.'];
        }

        $applied = false;
        $entryId = $args['apply_to_entry_id'] ?? null;
        if ($entryId) {
            $entry = CalendarEntry::where('id', $entryId)->where('team_id', $team->id)->first();
            if (! $entry) {
                $bus?->publish('subagent_error', ['agent' => 'drafter', 'message' => 'apply_to_entry_id not found for this team.']);
                return ['status' => 'error', 'message' => 'apply_to_entry_id not found for this team.'];
            }
            $entry->fill([
                'platform' => $platform,
                'title' => $payload['title'] ?: $entry->title,
                'image_headline' => $payload['image_headline'] ?? $entry->image_headline,
                'image_prompt' => $payload['image_prompt'] ?? $entry->image_prompt,
                'content' => $payload['content'],
            ])->save();
            $applied = true;
        }

        $bus?->publish('subagent_completed', ['agent' => 'drafter', 'card' => [
            'kind' => 'draft_post',
            'summary' => $applied
                ? __('Drafted and saved :platform post', ['platform' => $platform])
                : __('Drafted a :platform post', ['platform' => $platform]),
            'platform' => $platform,
            'title' => $payload['title'] ?? '',
            'applied_to_entry_id' => $entryId,
        ]]);

        return [
            'status' => 'ok',
            'platform' => $platform,
            'title' => $payload['title'] ?? '',
            'image_headline' => $payload['image_headline'] ?? '',
            'image_prompt' => $payload['image_prompt'] ?? '',
            'content' => $payload['content'] ?? '',
            'applied_to_entry_id' => $applied ? $entryId : null,
            'message' => $applied
                ? 'Draft saved into entry '.$entryId.'. No follow-up tool call needed.'
                : 'Draft returned only. Call update_entry or propose_entries to persist.',
        ];
    }

    private function buildSourceBlock(Team $team, array $args): string
    {
        $lines = [];

        if (! empty($args['source_topic_id'])) {
            $topic = Topic::where('team_id', $team->id)->find($args['source_topic_id']);
            if ($topic) {
                $lines[] = "Source topic: {$topic->title}";
                $lines[] = "Angle: {$topic->angle}";
                if (is_array($topic->sources) && ! empty($topic->sources)) {
                    $lines[] = 'Topic sources: ' . implode(', ', array_slice($topic->sources, 0, 3));
                }
            }
        }

        if (! empty($args['source_content_piece_id'])) {
            $piece = ContentPiece::where('team_id', $team->id)->find($args['source_content_piece_id']);
            if ($piece) {
                $lines[] = "Source blog post: {$piece->title}";
                $lines[] = 'Body excerpt: ' . mb_substr(strip_tags($piece->body), 0, 800);
            }
        }

        if (! empty($args['source_social_post_id'])) {
            $post = SocialPost::where('team_id', $team->id)->find($args['source_social_post_id']);
            if ($post) {
                $lines[] = "Source social post: [{$post->platform}] {$post->hook}";
                $lines[] = 'Body: ' . mb_substr($post->body, 0, 600);
            }
        }

        if (! empty($args['source_url'])) {
            $fetched = (new UrlFetcher)->fetch($args['source_url']);
            $lines[] = "Source URL: {$args['source_url']}";
            $lines[] = 'Content excerpt: ' . mb_substr(strip_tags((string) $fetched), 0, 1200);
        }

        return empty($lines) ? '(no source — rely on the idea above)' : implode("\n", $lines);
    }

    private function buildBrandBlock(Team $team): string
    {
        $lines = [];
        if ($team->brand_description) $lines[] = 'Description: ' . $team->brand_description;
        if ($team->target_audience) $lines[] = 'Target audience: ' . $team->target_audience;
        if ($team->tone_keywords) $lines[] = 'Tone: ' . $team->tone_keywords;
        if ($team->calendar_steer) $lines[] = 'Content guidance: ' . $team->calendar_steer;
        if ($team->content_language) $lines[] = 'Language: ' . $team->content_language;
        return $lines === [] ? '(no brand profile)' : implode("\n", $lines);
    }

}
