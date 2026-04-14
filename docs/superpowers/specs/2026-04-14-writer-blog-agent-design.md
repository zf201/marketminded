# Writer Blog Agent Design

## Goal

Add an AI-powered blog writer to the existing chat system. The writer is a new conversation type that produces cornerstone blog posts through a tool-driven workflow grounded in a user-selected Topic. Output is saved as a versioned `ContentPiece` with a dedicated page.

This is the PHP replacement for the Go writer pipeline. This spec covers blog posts only -- other platforms (LinkedIn, Instagram, YouTube, etc.) will be added later.

## Architecture

The writer runs inside a new `writer` chat type. The AI uses specialized tools to research, outline, write, and revise a blog post. There is no separate pipeline engine or state machine -- the AI drives tool invocation, and a system prompt enforces order. The `write` tool additionally acts as a hard gate, refusing to execute unless the required preceding tools have produced output in the conversation.

Two operating modes selected via starter sub-cards when creating the conversation:

- **Autopilot** -- AI runs research -> editor -> write sequentially without pausing
- **Checkpoint** -- AI pauses after research and after editor to let the user approve or redirect before continuing

The mode is stored on the conversation and can be switched mid-conversation via `!autopilot` or `!checkpoint` commands.

Selecting an existing Topic is mandatory. The Topic's conversation history primes the research step with context; the research tool then fetches external sources to verify and ground claims.

## Tech Stack

- Laravel 13 / Livewire / Volt (inline components)
- Flux UI Pro components
- OpenRouter API (web search server tools, streaming) via existing `OpenRouterClient::streamChatWithTools()`
- Follows existing patterns from `TopicToolHandler` and `ChatPromptBuilder`

---

## Data Model

### `content_pieces` table

| Column            | Type                    | Notes                                      |
|-------------------|-------------------------|--------------------------------------------|
| `id`              | `bigIncrements`         | PK                                         |
| `team_id`         | `foreignId`             | FK to `teams`, cascades on delete          |
| `conversation_id` | `foreignId`, nullable   | FK to `conversations`, null on delete      |
| `topic_id`        | `foreignId`, nullable   | FK to `topics`, null on delete             |
| `title`           | `string`                | Blog post title                            |
| `body`            | `longText`              | Blog post markdown body (current version)  |
| `status`          | `string(20)`            | `draft`, `approved`, `archived`            |
| `platform`        | `string(20)`            | `blog` (only value for now)                |
| `format`          | `string(30)`            | `pillar` (only value for now)              |
| `current_version` | `unsignedInteger`       | Version number of the current body         |
| `created_at`      | `timestamp`             |                                            |
| `updated_at`      | `timestamp`             |                                            |

Indexes on `(team_id, status)` and `(topic_id)`.

### `content_piece_versions` table

| Column               | Type                  | Notes                                                  |
|----------------------|-----------------------|--------------------------------------------------------|
| `id`                 | `bigIncrements`       | PK                                                     |
| `content_piece_id`   | `foreignId`           | FK to `content_pieces`, cascades on delete             |
| `version`            | `unsignedInteger`     | Sequential per piece: 1, 2, 3...                       |
| `title`              | `string`              | Full title snapshot                                    |
| `body`               | `longText`            | Full body snapshot                                     |
| `change_description` | `text`, nullable      | Short summary of what changed in this revision         |
| `created_at`         | `timestamp`           |                                                        |

Unique index on `(content_piece_id, version)`.

### `ContentPiece` model

- Fillable: `team_id`, `conversation_id`, `topic_id`, `title`, `body`, `status`, `platform`, `format`, `current_version`
- Relationships: `team()`, `conversation()`, `topic()`, `versions()` hasMany ordered by version desc
- Method: `saveSnapshot(string $title, string $body, ?string $changeDescription = null): ContentPieceVersion` -- inside a DB transaction: increments `current_version`, creates a new `ContentPieceVersion` row with the new `version`, `title`, `body`, and `change_description`, updates the piece's own `title`/`body` to match, saves the piece, returns the version record. This keeps the piece's current state and the latest version row always in sync.

### `ContentPieceVersion` model

- Fillable: `content_piece_id`, `version`, `title`, `body`, `change_description`
- `$timestamps = false`, auto-set `created_at` via `booted()` (same pattern as `Topic` and `Message`)
- Relationships: `contentPiece()`

### `Team` model addition

- Add `contentPieces()` hasMany relationship

### `Topic` model addition

- Add `contentPieces()` hasMany relationship (so a Topic can show which pieces it spawned)

### `Conversation` model addition

- Add `contentPieces()` hasMany relationship
- Add `writer_mode` column (`string(20)`, nullable): `autopilot` or `checkpoint`. Set when the user picks the starter tile, updated when `!autopilot` or `!checkpoint` commands are used.
- Add `topic_id` column (`foreignId`, nullable): FK to `topics`, null on delete. Set when the user picks a Topic at conversation creation. Required for `writer` type conversations.

A small migration adds `writer_mode` and `topic_id` to the `conversations` table.

---

## Chat Flow

### Entry from Create page

Add a fourth chat type card on `⚡create.blade.php` alongside Brand / Topics / Write (the existing generic "Write" type is being superseded -- see "Retiring the generic `write` type" below):

- **"Write a blog post"** -- icon: `document-text`, description: "Produce a cornerstone blog post grounded in one of your topics."

Selecting this card routes into `⚡create-chat.blade.php` with `conversation->type === 'writer'`.

### Mandatory Topic selection

For `writer` type conversations, before any sub-card or composer appears, the user must pick an existing available Topic. Render this as a Topic picker block in `⚡create-chat.blade.php`:

- Query: `Topic::where('team_id', $team->id)->where('status', 'available')->latest()->get()`.
- If the result is empty: show an empty state ("You need a topic first") with a button linking to the Topics page (`/teams/{team}/topics`).
- Otherwise: render them as selectable cards (title + angle). Clicking one calls `selectWriterTopic(int $topicId)`, which writes `conversation->topic_id`, saves the conversation, and reveals the mode sub-cards.

Implementation: Add `$topicId` (nullable int) and `selectWriterTopic(int $topicId)` to the chat component. When `conversation->type === 'writer'` and `conversation->topic_id` is null and no messages exist, show the Topic picker instead of sub-cards or composer.

### Mode sub-card selection

After a Topic is selected and before the first message, show two sub-cards (same styling as topics sub-cards in `⚡create-chat.blade.php`):

- **"Autopilot"** -- icon: `bolt`, description: "Run research, outline, and write in one go."
- **"Checkpoint mode"** -- icon: `check-circle`, description: "Pause after research and after the outline so you can approve before writing."

Clicking a card sets `conversation->writer_mode` and reveals the composer with a pre-filled first user message: `"Let's write a blog post about: {topic->title}"`. The user can send this as-is or edit it before submitting.

Implementation: Add `$writerMode` (nullable string) and `selectWriterMode(string $mode)` to the chat component. When `conversation->type === 'writer'` and `conversation->topic_id` is set and `conversation->writer_mode` is null and no messages exist, show the mode sub-cards instead of the composer.

### Mid-conversation mode switching

In the `writer` chat, if the user sends a message that is exactly `!autopilot` or `!checkpoint` (trimmed, case-insensitive), the chat component intercepts it before sending to the AI, updates `conversation->writer_mode`, and posts a short assistant-style notice inline: "Switched to autopilot mode." / "Switched to checkpoint mode." No LLM call is made for these commands.

### Tool-driven workflow

Once the composer is active, the conversation proceeds as normal AI chat. The AI has four tools available:

1. `research_topic` -- gathers claims from the linked Topic and web sources
2. `create_outline` -- produces the editorial outline from research
3. `write_blog_post` -- produces the final blog post (hard-gated)
4. `update_blog_post` -- revises the current content piece

The system prompt instructs the order (`research_topic` -> `create_outline` -> `write_blog_post`) and behaviour per mode. The `write_blog_post` tool enforces the gate at runtime.

---

## Tools

All tools follow the `TopicToolHandler` pattern: a service class with `execute(...)` and a static `toolSchema()`. The chat `$toolExecutor` closure in `⚡create-chat.blade.php` dispatches by tool name.

### 1. `research_topic`

**Purpose:** Produce structured claims for the editor and writer to use.

**Inputs (tool args):**
- `queries` -- array of web search queries the AI plans to run (the tool handler does not issue these; the LLM uses the built-in web search server tool on its own turn to gather results. The `research_topic` tool call is where the LLM submits structured, sourced claims it has distilled.)
- `claims` -- array of `{ id, text, sources: [{ url, title }] }`. IDs are short slugs like `c1`, `c2`.
- `topic_summary` -- 2-3 sentence summary of what this piece is about, pulled from the Topic's angle and research context.

**Behaviour:** Creates a structured message/tool result containing the claims block. Stored in the message `metadata.tools` so it is retrievable on subsequent turns. Returns JSON: `{ status: 'ok', claim_count: N }`.

**Why this shape:** The LLM does web searches itself via OpenRouter's server-tool web search; the `research_topic` tool is the structured hand-off the LLM uses to commit its findings so later tools (and the writer's gate) can see them. This mirrors the Go pipeline's structured claims output without re-implementing research in PHP.

### 2. `create_outline`

**Purpose:** Create the editorial outline that the writer follows.

**Inputs:**
- `title` -- working title
- `angle` -- the angle/positioning of the piece
- `sections` -- array of `{ heading, purpose, claim_ids: [...] }`. Each section references claim IDs from the research step.
- `target_length_words` -- integer

**Behaviour:** Validates that every `claim_ids` entry exists in the most recent `research_topic` output for this conversation. If any are missing, returns `{ status: 'error', message: 'Unknown claim IDs: ...' }` so the LLM can retry. Otherwise stores the outline in the tool result and returns `{ status: 'ok' }`.

### 3. `write_blog_post`

**Purpose:** Produce the final blog post and create the `ContentPiece` record.

**Inputs:**
- `title` -- final title
- `body` -- full blog post in markdown

**Behaviour -- hard gate:**
1. Scan previous messages in the conversation for tool results from `research_topic` and `create_outline`. If either is missing, return `{ status: 'error', message: 'You must call research_topic and create_outline before write_blog_post.' }` -- the LLM must retry.
2. Check whether a `ContentPiece` already exists for this conversation. If one exists, return `{ status: 'error', message: 'A blog post already exists for this conversation. Use update_blog_post to revise it.' }`.
3. Create a `ContentPiece` with empty `title`/`body`, `status=draft`, `platform=blog`, `format=pillar`, `current_version=0`, linked to the team, conversation, and topic.
4. Call `$piece->saveSnapshot($title, $body, 'Initial draft')` -- this creates v1, populates title/body, and sets `current_version=1`.
5. Mark the linked Topic's status as `used`.
6. Return JSON: `{ status: 'ok', content_piece_id, title, version: 1 }`.

### 4. `update_blog_post`

**Purpose:** Revise the blog post based on user feedback. Saves a new version snapshot.

**Inputs:**
- `content_piece_id` -- the piece to update
- `title` -- updated title (can match existing)
- `body` -- updated body
- `change_description` -- short summary of what changed, e.g. "Punched up intro, tightened section 3."

**Behaviour:**
1. Load the `ContentPiece`, scoped to the current team. If not found or not in this team, return `{ status: 'error', message: 'Content piece not found.' }`.
2. Call `$piece->saveSnapshot($title, $body, $changeDescription)` -- creates the new version row, updates the piece, increments `current_version`.
3. Return JSON: `{ status: 'ok', content_piece_id, version: $piece->current_version }`.

There is no reject/rewrite cycle. All revisions flow through `update_blog_post`.

### Tool schemas

Each tool exposes a `static toolSchema(): array` following the OpenAI/OpenRouter function-calling format used by `TopicToolHandler`. Schemas live in the respective handler classes.

### Tool handler classes

Create under `app/Services/`:

- `ResearchTopicToolHandler.php`
- `CreateOutlineToolHandler.php`
- `WriteBlogPostToolHandler.php`
- `UpdateBlogPostToolHandler.php`

Each has `execute(Team $team, int $conversationId, array $data, ?Topic $topic = null): string` (the topic argument is only needed by `ResearchTopicToolHandler` and `WriteBlogPostToolHandler`; the others ignore it) and a static `toolSchema()`.

### Registering tools

In `ChatPromptBuilder::tools()`:

```php
'writer' => [
    ResearchTopicToolHandler::toolSchema(),
    CreateOutlineToolHandler::toolSchema(),
    WriteBlogPostToolHandler::toolSchema(),
    UpdateBlogPostToolHandler::toolSchema(),
    BrandIntelligenceToolHandler::fetchUrlToolSchema(),
],
```

In the `$toolExecutor` closure in `⚡create-chat.blade.php`, add cases for each tool name that instantiate the handler, pass the team, conversation id, and (where relevant) the topic, and return the JSON result.

---

## System Prompt

Add `'writer' => self::writerPrompt($profile, $hasProfile, $team, $conversation)` to `ChatPromptBuilder::build()`. The `$conversation` parameter is new and must be threaded through from the caller. Other prompt types ignore it.

The `writerPrompt()` method produces a prompt with these blocks:

1. **Role and task:** "You are a skilled blog writer producing cornerstone content. Your job is to research a topic, outline it, write it, and revise based on user feedback. All work is grounded in verified sources from the research step."
2. **Tool order (hard rule):** The AI must call `research_topic` -> `create_outline` -> `write_blog_post` in that order. `write_blog_post` will refuse to run otherwise. `update_blog_post` is only used after `write_blog_post` has produced a content piece.
3. **Mode-specific behaviour:**
   - **Autopilot:** Run all three steps without pausing. Brief status messages between steps are fine but do not ask for approval. After `write_blog_post`, report the result and ask the user to review.
   - **Checkpoint:** After `research_topic`, pause and summarize the claims for user approval. After `create_outline`, pause and summarize the outline. Only call `write_blog_post` after the user approves the outline.
4. **Writing rules (distilled from Go `prompts/types/blog_post.md`):**
   - 1,200-2,000 words for pillar content
   - Every statistic, percentage, date, named entity, or quote must come from a claim ID in the research step
   - Banned words/phrases: "leverage," "innovative," "streamline," "unlock," "empower," "revolutionize," "in today's fast-paced world," em-dashes used stylistically, passive voice as default, corporate filler
   - Headline formulas encouraged: "Achieve X without Y", "Stop Z. Start W.", "Never X again"
   - Clear benefit-focused structure; short paragraphs; scannable subheadings
   - Match brand voice from the brand profile without copying
5. **Revision behaviour:** When the user gives feedback after a piece exists, use `update_blog_post` with a clear `change_description`. Do not re-run the full pipeline.
6. **Brand context:** Include `<brand-profile>` block (same as other prompts).
7. **Topic context:** Include a `<topic>` block with the selected Topic's title, angle, and sources. Include a `<topic-conversation>` block with the titles and first lines of any messages in the Topic's originating conversation, if present, so the AI has the brainstorming context.
8. **Current mode:** Include `<mode>autopilot</mode>` or `<mode>checkpoint</mode>` so the LLM can see its active mode.
9. **Current content piece (if present):** If a `ContentPiece` already exists for this conversation, include `<current-content-piece>` with id, title, current version, and body so the AI can reason about updates.

The prompt is rebuilt on every LLM turn, so mode switches and newly created content pieces are reflected automatically.

### Retiring the generic `write` type

The existing `write` chat type (`ChatPromptBuilder::writePrompt()`) is a simple copywriter chat with no tools or structure. Since this new `writer` type is the structured replacement, remove the generic `write` type:

- Remove the `write` case from `ChatPromptBuilder::build()` and `ChatPromptBuilder::tools()`.
- Remove the `write` type card from `⚡create.blade.php`.
- Keep the `writePrompt()` method deleted.
- No migration needed -- existing `write` conversations will render with the default prompt and no tools. If any exist in the user's database, they remain viewable but inert.

---

## Chat UI Changes

### Content piece card (inline in chat stream)

When `write_blog_post` or `update_blog_post` completes during streaming, render a compact card in the message stream. Each card shows:

- Green "Draft created" (for write) or "Revised" (for update) badge
- Title (bold, truncated at ~80 chars)
- First ~200 chars of body as preview (stripped of markdown)
- Version badge (e.g. "v1", "v3")
- Small "Open" button/link to the content piece page

Card styling matches saved Topic cards (same card shell, same typography scale). Stacked vertically with a small gap if multiple updates happen in one message.

Rendered via the existing `streamUI()` method -- add cases for `write_blog_post` and `update_blog_post` in the tool rendering logic.

For message history (non-streaming), cards are reconstructed from `message.metadata.tools` entries where `name === 'write_blog_post'` or `name === 'update_blog_post'`. Look up the `content_piece_id` in the tool result and fetch the current state of the piece to render the card (so an old tool-result card reflects the latest version info).

### Tool pill labels

Add to `toolPill()` and the `streamUI()` active tool label map:

| Tool | Active label | Completed label |
|------|--------------|-----------------|
| `research_topic` | "Researching topic..." | "Gathered N claims" |
| `create_outline` | "Building outline..." | "Outline ready" |
| `write_blog_post` | "Writing blog post..." | "Draft created" |
| `update_blog_post` | "Revising..." | "Revised (vN)" |

### `!autopilot` / `!checkpoint` interception

In the chat component's submit method, before dispatching to the LLM, check if the trimmed message matches one of the commands. If so, update `conversation->writer_mode`, append a system-style message (`role=assistant`, with a `metadata.command_result` flag so it renders differently if desired) saying "Switched to X mode", and return without calling the LLM.

### Mode indicator

Show the current mode as a small Flux badge near the chat title: "Autopilot" or "Checkpoint". Tappable -- opens a dropdown to switch modes (same effect as typing the command).

---

## Content Piece Page

### Route

Add to `routes/web.php` inside the team-scoped group:

```php
Route::livewire('content-pieces/{contentPiece}', 'pages::teams.content-piece')
    ->name('content-pieces.show');
```

Route model binding: scope `contentPiece` to the current team (team ID must match) or 404.

### Sidebar

Add a "Content" item to the sidebar, between "Topics" and "AI Log":

```
[document-text icon] Content
```

This links to a simple index page listing all content pieces for the team. (Index page is a minor add -- see Files Summary.)

### Page: `resources/views/pages/teams/⚡content-piece.blade.php`

Volt inline component showing a single content piece with version history.

**Component logic:**
- `ContentPiece $contentPiece` (from route model binding, team-scoped)
- Computed `versions` property: `$contentPiece->versions()->get()` (already ordered desc by version)
- `$selectedVersion` (nullable int, default null = current) -- controls which version body is displayed
- `restoreVersion(int $version)` -- loads the target version's title/body and calls `$piece->saveSnapshot($target->title, $target->body, "Restored from v{$version}")`. This produces a new version row (e.g. v5 after a restore from v2) rather than rewinding, preserving full history. Opens a confirmation modal before executing.
- `updateStatus(string $status)` -- moves between `draft`, `approved`, `archived`.

**Page layout:**

- Header: title, status badge, "Open original conversation" link (if `conversation_id` set), status dropdown
- Two-column Flux layout:
  - **Left (main):** the body rendered as markdown (use existing markdown renderer if present; otherwise a minimal Parsedown-style pass). Shows the selected version's body, not necessarily the current.
  - **Right (sidebar):** version history. List of versions with "v1", "v2"..., `created_at`, `change_description` (truncated). Current version marked with a "Current" pill. Click a version to view it in the left column. "Restore this version" button on non-current versions.

Empty state: not applicable -- a piece always has at least v1.

### Content index page (minor)

`resources/views/pages/teams/⚡content.blade.php` -- a list of all `ContentPiece` records for the team, rendered as truncated cards (title, status, updated_at, topic title link). Clicking a card opens the content piece page. Can reuse patterns from the Topics page.

---

## Tool Executor Wiring

In `⚡create-chat.blade.php`, the `ask()` method's `$toolExecutor` closure currently dispatches `save_topics`, `update_brand_intelligence`, and `fetch_url`. Extend it with:

```php
if ($name === 'research_topic') {
    return $researchHandler->execute($team, $conversation->id, $args, $conversation->topic);
}
if ($name === 'create_outline') {
    return $outlineHandler->execute($team, $conversation->id, $args);
}
if ($name === 'write_blog_post') {
    return $writeHandler->execute($team, $conversation->id, $args, $conversation->topic);
}
if ($name === 'update_blog_post') {
    return $updateHandler->execute($team, $conversation->id, $args);
}
```

Instantiate the four handlers in the method alongside the existing ones. `Conversation` gains a `topic()` belongsTo relationship to make `$conversation->topic` available.

---

## Files Summary

### Create

| File | Purpose |
|------|---------|
| `database/migrations/..._create_content_pieces_table.php` | Content pieces table |
| `database/migrations/..._create_content_piece_versions_table.php` | Versions table |
| `database/migrations/..._add_writer_fields_to_conversations_table.php` | Adds `writer_mode` and `topic_id` to `conversations` |
| `app/Models/ContentPiece.php` | ContentPiece model with `saveSnapshot()` |
| `app/Models/ContentPieceVersion.php` | ContentPieceVersion model |
| `app/Services/ResearchTopicToolHandler.php` | Handler for `research_topic` |
| `app/Services/CreateOutlineToolHandler.php` | Handler for `create_outline` |
| `app/Services/WriteBlogPostToolHandler.php` | Handler for `write_blog_post` with hard gate |
| `app/Services/UpdateBlogPostToolHandler.php` | Handler for `update_blog_post` |
| `resources/views/pages/teams/⚡content-piece.blade.php` | Content piece page with version history |
| `resources/views/pages/teams/⚡content.blade.php` | Content index page |

### Modify

| File | Change |
|------|--------|
| `app/Services/ChatPromptBuilder.php` | Add `writer` case in `build()` and `tools()`; add `writerPrompt()` method; thread `$conversation` param through; remove `write` case and `writePrompt()` |
| `app/Models/Conversation.php` | Add `topic()` belongsTo and `contentPieces()` hasMany; add `writer_mode` and `topic_id` to fillable |
| `app/Models/Team.php` | Add `contentPieces()` hasMany |
| `app/Models/Topic.php` | Add `contentPieces()` hasMany |
| `resources/views/pages/teams/⚡create.blade.php` | Add "Write a blog post" card; remove generic "Write" card |
| `resources/views/pages/teams/⚡create-chat.blade.php` | Add Topic picker for `writer` type, mode sub-cards, `$topicId` and `$writerMode` properties, `selectWriterTopic` and `selectWriterMode` methods, `!autopilot`/`!checkpoint` command interception, render content piece cards inline, tool handler instantiation and dispatch, mode indicator badge |
| `routes/web.php` | Add `content-pieces.show` and `content` routes |
| `resources/views/layouts/app/sidebar.blade.php` | Add "Content" nav item |

---

## Out of scope

- Other platforms: LinkedIn, Instagram, Instagram carousels, YouTube scripts, X threads, TikTok, Facebook
- Audience Picker step -- brand profile's audience personas are used via the system prompt instead
- Style Reference step -- brand voice comes from the brand profile
- Claim Verifier step
- Publishing/scheduling integrations
- Collaborative editing / comments
- Full-text search across content pieces
- Diff view between versions (version list with restore is enough for now)
