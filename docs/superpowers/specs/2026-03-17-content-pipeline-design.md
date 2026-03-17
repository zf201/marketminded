# Content Pipeline — Production Board

## Overview

Replace the stage-based pipeline wizard with a **production board**. Each pipeline run is a persistent board showing a topic, a production plan, a cornerstone piece, and waterfall pieces. Content generates sequentially with user approval at each step. Any piece can be improved via a mini chat.

## Page Layout

### Pipeline list (`/projects/:id/pipeline`)

List of all runs with topic, status, date. "New Run" button with a topic text input.

### Pipeline run / production board (`/projects/:id/pipeline/:runId`)

Vertical board of content cards:

1. **Header** — topic, run status, date
2. **Plan card** — what will be produced (cornerstone type + waterfall pieces). Approve/Reject.
3. **Cornerstone card** — the main content piece. Streams in via SSE. Approve/Reject/Improve.
4. **Waterfall cards** — one per output piece. Show as pending placeholders until generated. Each gets Approve/Reject/Improve when in draft status.

Cards are ordered by `sort_order`. Cornerstone is always first (sort_order 0), waterfall pieces follow.

## Flow

### 1. Create run
User enters a topic, clicks Start. Creates `pipeline_run` with status `planning`.

### 2. Plan generation
AI reads the topic + client profile (especially content strategy section with waterfall flows). Proposes a production plan via SSE. The AI MUST output the plan as JSON so the backend can parse it to create piece placeholders:

```json
{
  "cornerstone": {"platform": "blog", "format": "post", "title": "Working title here"},
  "waterfall": [
    {"platform": "instagram", "format": "post", "count": 2},
    {"platform": "instagram", "format": "reel", "count": 2},
    {"platform": "linkedin", "format": "post", "count": 1},
    {"platform": "x", "format": "post", "count": 1},
    {"platform": "x", "format": "thread", "count": 1}
  ]
}
```

The raw JSON is saved in `pipeline_runs.plan`. The frontend renders it as a human-readable plan card. Approve/Reject buttons on the card.

If rejected with a reason, AI re-generates the plan incorporating the feedback.

### 3. Plan approved
Backend parses the JSON plan. Creates `content_pieces` rows:
- Cornerstone: `sort_order=0`, `status=pending`, platform/format from plan
- Waterfall pieces: `sort_order=1,2,3...` (expanding `count` into individual rows), `status=pending`, each with platform/format, `parent_id` pointing to cornerstone

Run status → `producing`. Board shows all cards. Cornerstone starts generating immediately.

### 4. Piece generation (cornerstone and waterfall — same mechanism)
Frontend connects to `GET stream/piece/:pieceId`. The handler:
1. **Guard against double-trigger:** `UPDATE content_pieces SET status = 'generating', updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status IN ('pending', 'rejected')` — if 0 rows affected, return error (piece already generating or done)
2. Stream via `StreamWithTools` (fetch/search available, `onToolEvent` sends tool indicator SSE events to the client). Temperature 0.3.
3. On stream complete: save body via `UpdateContentPiece`, set status → `draft`
4. Send SSE `{"type":"done"}` — frontend shows Approve/Reject/Improve buttons

For cornerstone: context is topic + full client profile.
For waterfall pieces: context is cornerstone body + client profile + platform-specific guidance (see below).

### 5. Approve piece
Card status → `approved`. Frontend checks if there's a next pending piece and auto-connects to its stream endpoint to start generation.

When all pieces are approved, run status → `complete`.

### 6. Reject piece
User provides a reason. Saved in `content_pieces.rejection_reason`. Status → `rejected`. The `stream/piece/:pieceId` endpoint accepts `rejected` status (see guard in step 4), reads the rejection reason, and injects it into the prompt: "Previous version was rejected. Feedback: {reason}."

### 7. Improve (anytime on any draft or approved piece)
Click Improve on a piece. Expands a mini chat below the card. User types feedback via `POST /improve`. Then `GET /improve/stream` SSE streams the AI rewrite. On stream complete, the handler saves the new body to `content_pieces` and resets status to `draft`. Can go back and forth multiple times.

## Platform-Specific Guidance

Used in waterfall piece prompts. Mapping from platform+format to writing instructions:

| Platform | Format | Guidance |
|----------|--------|----------|
| linkedin | post | Professional but personal. Hook in the first line. Use line breaks for readability. 1300 char max. End with a question or CTA. No hashtags in body, 3-5 at the end if guidelines allow. |
| instagram | post | Visual-first caption. Hook in first line. Short paragraphs. Hashtags at the end (up to 15 relevant ones). Under 2200 chars. Engage with a question. |
| instagram | reel | Script for a 30-60 second video. Hook in first 3 seconds. One clear point. End with CTA. Conversational, not scripted-sounding. |
| x | post | Single tweet, under 280 chars. Punchy, opinionated, or surprising. No filler words. |
| x | thread | 5-8 tweets. First tweet is the hook. Each tweet stands alone but builds on the previous. Last tweet is CTA. Number them. |
| blog | post | Long-form markdown. 1200-2000 words. SEO-friendly headers. Intro with hook, clear sections, actionable takeaways, strong conclusion. |
| youtube | script | Video script with timestamps. Hook in first 15 seconds. Clear sections. Conversational delivery notes in [brackets]. |
| youtube | short | Script for under 60 seconds. One point. Hook immediately. Fast-paced. End with follow CTA. |
| facebook | post | Conversational. Hook first line. Encourage comments. 500 chars ideal. One CTA. |

## Data Model

### Migration strategy
Clean break — rewrite `001_initial.sql` directly and delete the DB file. No goose migration versioning needed. This is a dev tool with no production data.

### Migration changes

**`pipeline_runs` table** — rewrite:
```sql
CREATE TABLE pipeline_runs (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    topic TEXT NOT NULL,
    plan TEXT,  -- production plan text (AI-generated, human-approved)
    status TEXT NOT NULL DEFAULT 'planning'
        CHECK(status IN ('planning','producing','complete','abandoned')),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

Drop: `selected_topic` column (replaced by `topic` which is NOT NULL).

**`content_pieces` table** — add columns:
```sql
CREATE TABLE content_pieces (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    pipeline_run_id INTEGER NOT NULL REFERENCES pipeline_runs(id) ON DELETE CASCADE,
    type TEXT NOT NULL,  -- kept for backward compat, same as format
    platform TEXT NOT NULL DEFAULT '',
    format TEXT NOT NULL DEFAULT '',
    title TEXT,
    body TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'pending'
        CHECK(status IN ('pending','generating','draft','approved','rejected')),
    parent_id INTEGER REFERENCES content_pieces(id),
    sort_order INTEGER NOT NULL DEFAULT 0,
    rejection_reason TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

New columns: `platform`, `format`, `sort_order`, `rejection_reason`. New status: `pending`, `generating` added. `pipeline_run_id` is now NOT NULL (every piece belongs to a run).

**`brainstorm_chats` table** — add `content_piece_id`:
```sql
ALTER TABLE brainstorm_chats ADD COLUMN content_piece_id INTEGER REFERENCES content_pieces(id);
```

When `content_piece_id` is set, it's an improvement chat scoped to that piece.

## Endpoints

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/projects/:id/pipeline` | List all runs |
| POST | `/projects/:id/pipeline` | Create new run (form: `topic`) |
| GET | `/projects/:id/pipeline/:runId` | Show production board |
| GET | `/projects/:id/pipeline/:runId/stream/plan` | SSE: generate production plan |
| POST | `/projects/:id/pipeline/:runId/approve-plan` | Approve plan, create piece placeholders |
| POST | `/projects/:id/pipeline/:runId/reject-plan` | Reject plan with reason, re-plan |
| GET | `/projects/:id/pipeline/:runId/stream/piece/:pieceId` | SSE: generate a specific piece |
| POST | `/projects/:id/pipeline/:runId/piece/:pieceId/approve` | Approve piece, trigger next |
| POST | `/projects/:id/pipeline/:runId/piece/:pieceId/reject` | Reject piece with reason, re-generate |
| POST | `/projects/:id/pipeline/:runId/piece/:pieceId/improve` | Save improvement message, returns 200 |
| GET | `/projects/:id/pipeline/:runId/piece/:pieceId/improve/stream` | SSE: improvement chat response |
| POST | `/projects/:id/pipeline/:runId/abandon` | Abandon the run |

## AI Prompts

### Plan generation
```
Today's date: {date}

You are a content production planner. Given a topic and the client's content strategy, create a production plan.

Client profile:
{full profile}

Topic: {topic}

Based on the content strategy and waterfall flows defined in the profile, propose:
1. The cornerstone content type (blog post, video script, etc.)
2. The waterfall pieces that will be produced from it, with platform and format for each

Be specific. Reference the waterfall patterns from the content strategy section. If the strategy doesn't define waterfalls, propose reasonable defaults based on the platforms listed.

Respond with a clear, numbered plan. No fluff.
```

### Cornerstone writing
```
Today's date: {date}

You are a content writer. Write the cornerstone piece for this production run.

Client profile:
{full profile}

Topic: {topic}
Format: {format} (e.g. blog post, video script)

Write the complete piece in markdown. Follow the client's voice and tone exactly. Reference their content pillars where relevant. The piece should be thorough, specific to this client's expertise, and valuable to their target audience.

{If rejected previously: "Previous version was rejected. Feedback: {reason}. Address this in your rewrite."}

WRITING RULES:
- Write like a human. Never sound AI-generated.
- Never use em dashes. Use commas, periods, or restructure.
- No emoji in blog posts or scripts.
- Avoid: "dive into", "leverage", "elevate", "streamline", "game-changer", "unlock", "harness".
- Short, direct sentences. Vary length. Sound like a real person.
```

### Waterfall piece writing
```
Today's date: {date}

You are a content repurposer. Adapt the cornerstone content into a {platform} {format}.

Client profile:
{full profile}

Cornerstone content:
{cornerstone body}

Target: {platform} {format}
{Platform-specific guidance based on platform value}

Adapt the cornerstone into this format. Don't just summarize — reshape the content to work natively on this platform. Match the client's voice and tone.

{If rejected previously: "Previous version was rejected. Feedback: {reason}. Address this in your rewrite."}

Same writing rules as cornerstone. Adapt emoji/hashtag usage to platform norms only where the client's guidelines allow it.
```

### Improvement chat
```
Today's date: {date}

You are improving a content piece. Here is the current version:

{current piece body}

Platform: {platform}, Format: {format}

Client profile:
{full profile}

The user wants to improve this piece. Respond to their feedback and provide a complete rewritten version. Don't explain what you changed — just provide the improved content.

Same writing rules apply.
```

## What Gets Removed

- `internal/pipeline/pipeline.go` — state machine package (ValidateTransition, Advance, etc.)
- `internal/pipeline/pipeline_test.go`
- Old `web/handlers/pipeline.go` — rewrite entirely
- Old `web/templates/pipeline.templ` — rewrite entirely
- `pipelineStoreAdapter` in `cmd/server/main.go`

## What Gets Added/Rewritten

- `web/handlers/pipeline.go` — new handler with plan/generate/approve/reject/improve endpoints
- `web/templates/pipeline.templ` — production board layout with content cards
- `web/static/app.js` — JS for streaming into specific cards, mini chat expansion
- `web/static/style.css` — production board card styles
- `internal/store/pipeline.go` — updated for new schema. `CreatePipelineRun(projectID, topic)` now requires topic. New methods: `UpdatePipelinePlan`, `UpdatePipelineStatus`.
- `internal/store/content.go` — updated for new columns (platform, format, sort_order, rejection_reason, updated_at). New: `ListContentByPipelineRunOrdered` (ORDER BY sort_order). `CreateContentPiece` updated with new fields. `SetContentPieceStatus`, `SetContentPieceRejection`.
- `cmd/server/main.go` — simplified pipeline handler wiring (no more state machine adapter)
- Migration rewrite for pipeline_runs and content_pieces tables
