# Social Funnel — Design

A 4th create-workflow ("Build a Funnel") that turns a `ContentPiece` into 3–6 platform-appropriate social posts which drive traffic back to the piece. Output is browsable on a new **Social** sidebar page and is iteratively refinable through a chat conversation, mirroring the topic-researcher pattern.

## Goals

- Generate 3–6 social posts per content piece, mixing LinkedIn / Facebook / Instagram, with **at most one** short-form video idea.
- Every post body contains a `[POST_URL]` placeholder at the CTA point so the live link can be filled in at posting time.
- Posts are individually addressable in chat ("rewrite the IG one", "drop post 3").
- Starting a new chat for an already-funneled piece scopes to the existing posts and tells the user so.
- Posts are copy-pastable as markdown, scorable 1–10, toggle-able as Posted, and soft-deletable.

## Non-goals

- No publishing integrations (no auto-posting to LinkedIn/Meta APIs).
- No analytics ingestion — scoring is human-judgment only.
- No image generation — `image_prompt` is direction for a human or downstream tool.

## Architecture

The funnel reuses the existing shared chat (`create-chat` Livewire component) via a new `type` value, identical to how `writer` and `topics` are dispatched. A new `SocialPost` model stores the output. Two new routes render browse/detail pages.

### Workflow dispatch

- New `type` value: `funnel`.
- Entry URL: `create/new?type=funnel`. Existing conversation URL `create/{conversation}` already handles it once `type` is set on the `Conversation`.
- 4th tile added to the type-selection block in `create-chat.blade.php` (alongside brand / topics / writer).
- Pre-input picker (mirroring writer's topic picker): a content-piece picker plus an optional one-liner guidance field, gated on `$type === 'funnel' && !$contentPieceId && empty($messages)`.
- `ChatPromptBuilder::build('funnel', $team, $conversation)` returns the system prompt.
- `ChatPromptBuilder::tools('funnel')` returns the funnel tool schemas.
- Sidebar badge label for `funnel`: "Funnel".

### Data model

New table `social_posts`:

| column | type | notes |
| --- | --- | --- |
| `id` | bigint pk | |
| `team_id` | fk → teams | scope guard, indexed |
| `content_piece_id` | fk → content_pieces | grouping key, indexed |
| `conversation_id` | fk → conversations | originating/last-editing chat |
| `platform` | enum | `linkedin`, `facebook`, `instagram`, `short_video` |
| `hook` | text | scroll-stopping opener |
| `body` | text | markdown; must contain `[POST_URL]` exactly once |
| `hashtags` | json | array of strings, no leading `#` |
| `image_prompt` | text nullable | populated for non-video platforms |
| `video_treatment` | text nullable | populated when `platform = short_video` |
| `score` | smallint nullable | 1–10 |
| `posted_at` | timestamp nullable | "Posted" toggle |
| `status` | enum | `active`, `deleted` |
| `position` | smallint | display order within content piece |
| timestamps | | |

Relations: `ContentPiece hasMany SocialPost`; `Conversation hasMany SocialPost`. The "funnel" is implicit — every active `SocialPost` under a content piece *is* the funnel for that piece.

Migration creates indexes on `(team_id, content_piece_id, status)` and `(content_piece_id, position)`.

### Generation: the funnel agent

System prompt (`ChatPromptBuilder::build('funnel', …)`) assembles:

1. Brand context: positioning, audience persona, voice profile (same loaders writer/topics use).
2. Selected `ContentPiece` (title, body, brief).
3. Linked `Topic` brainstorm if present (sources + angles), pulled the same way the writer pulls topic context.
4. Optional one-liner guidance from the picker.
5. **If existing active posts exist for this piece:** a "Current funnel for this piece" section listing each post's id, platform, hook, body, hashtags, image_prompt/video_treatment. Prompt instructs: "The user is refining the existing funnel. Default to keeping these unless asked to change. Reference posts by id when discussing them."
6. Per-platform best-practice guidance, embedded inline (covering LinkedIn long-form line-broken style, Facebook conversational 1–3 short paras with a question/CTA, Instagram visual-first caption with a hook line and hashtag block at the end, short-form video hook-beat / value-beats / CTA at 15–45s). Spec author does a brief research pass at implementation time and embeds the result directly in the prompt.
7. The `[POST_URL]` placeholder rule: every `body` must contain it exactly once at a natural CTA point.
8. Mix constraint: 3–6 posts total, at most one with `platform = short_video`.

Tools (`ChatPromptBuilder::tools('funnel')`):

- `propose_posts(posts: [...])` — initial batch. Server validates count (3–6) and short-video cap (≤ 1). Persists `SocialPost` rows linked to content piece + conversation. Position assigned in array order.
- `update_post(id, fields)` — partial update of a single post. Server enforces team/content-piece scope and re-validates body's `[POST_URL]` and short-video cap when platform is changed.
- `delete_post(id)` — soft delete (`status = 'deleted'`). The agent should briefly say why.
- `replace_all_posts(posts)` — for "redo all of these". Soft-deletes existing active posts for this piece, creates the new set.

Each tool returns the resulting post ids and a compact summary so subsequent turns stay grounded.

### Routes & navigation

Added to the team-scoped group in `routes/web.php`:

- `social` → `pages::teams.social` (index)
- `social/{contentPiece}` → `pages::teams.social-piece` (subpage)

Sidebar: a new **Social** entry placed between **Content** and **Topics**, ordered Topics → Content → Social to read as the natural pipeline.

### Pages

**`social` index** — grid of cards, one per `ContentPiece` that has ≥ 1 active post. Each card shows the piece title, post count, and a small platform-icon row indicating which platforms are covered. Clicking navigates to the subpage. Empty state directs the user to `create/new?type=funnel`.

**`social/{contentPiece}` subpage** — header with the piece title and a "Refine in chat" button. The button links to the most recent funnel conversation for this piece if one exists; otherwise it starts a new chat at `create/new?type=funnel&content_piece={id}`. Below the header: the card grid of posts.

Per-post card:

- Platform badge (icon + name) top-left.
- Hook, rendered bold and slightly larger.
- Body, rendered markdown. `[POST_URL]` is rendered verbatim inside a subtle highlight pill so it's visually impossible to miss before posting.
- Hashtag chip row.
- Image prompt block, or video treatment block if `platform = short_video`, under a small heading.
- Footer row: score control (1–10, same component as topics), "Posted" toggle (greying the card when on, but the card stays copy-able), "Copy markdown" button, kebab menu with Delete (soft) and "Refine this in chat" (deep-links to the post's `conversation_id`).

Copy-as-markdown output for one card:

```
**LinkedIn**

[hook]

[body with [POST_URL]]

[#tag #tag #tag]

---
Image: [image_prompt]
```

For `short_video` posts, the trailing line is `Video: [video_treatment]` instead of `Image: …`.

## Lifecycle

- Soft delete only (`status = 'deleted'`); never hard-delete from the UI.
- Re-running the funnel via `replace_all_posts` soft-deletes the previous active set; the deleted rows remain in the table for history.
- Score and `posted_at` are user-only fields; the agent neither reads nor writes them.

## Out of scope for this spec

- Surfacing scored social-post history back into the agent's system prompt (the topic-researcher feedback-loop pattern). Worth doing later once we have signal on whether human scoring of social posts is reliable enough to be useful as training context.
- A `post_url` field on `ContentPiece` with auto-substitution at copy time. Bracket-token `[POST_URL]` is sufficient for v1.
- Bulk actions (mark-all-posted, export-all).
