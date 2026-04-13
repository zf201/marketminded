# Topic Generator Design

## Goal

Add AI-powered topic generation to the existing chat system. Topics are proposed conversationally, saved to a backlog via tool calls, and managed on a dedicated Topics page.

## Architecture

The topic generator runs inside the existing "Brainstorm topics" chat type. The AI uses web search and URL fetching to research trends, proposes topics as numbered lists in the conversation, and saves user-approved topics via a `save_topics` tool call. A new Topics page provides an overview of all saved topics with scoring and management.

No separate `topic_runs` or `topic_steps` tables — the conversation history serves as the run log.

## Tech Stack

- Laravel 13 / Livewire / Volt (inline components)
- Flux UI Pro components
- OpenRouter API (web search via server tools, streaming)
- Existing `OpenRouterClient::streamChatWithTools()` generator pattern

---

## Data Model

### `topics` table

| Column            | Type                   | Notes                                    |
|-------------------|------------------------|------------------------------------------|
| `id`              | `bigIncrements`        | PK                                       |
| `team_id`         | `foreignId`            | FK to `teams`, cascades on delete         |
| `conversation_id` | `foreignId`, nullable  | FK to `conversations`, null on delete     |
| `title`           | `string`               | Topic title                              |
| `angle`           | `text`                 | Why this topic fits the brand             |
| `sources`         | `json`                 | Array of research evidence strings        |
| `status`          | `string(20)`           | `available`, `used`, `deleted`            |
| `score`           | `unsignedTinyInteger`, nullable | User rating 1-10                |
| `created_at`      | `timestamp`            |                                           |

Index on `(team_id, status)` for the Topics page query.

### `Topic` model

- Fillable: `team_id`, `conversation_id`, `title`, `angle`, `sources`, `status`, `score`
- Casts: `sources` as `array`, `score` as `integer`
- `$timestamps = false`, auto-set `created_at` via `booted()` (same pattern as `Message`)
- Relationships: `team()`, `conversation()`

### `Team` model addition

- Add `topics()` hasMany relationship

---

## Chat Flow

### Sub-card selection

After the user selects "Brainstorm topics" from the three type cards, a second level of sub-cards appears (same styling as the type cards):

1. **"Auto-discover topics"** — icon: `magnifying-glass`, description: "Research trends and discover topics for your brand automatically"
2. **"Start a conversation"** — icon: `chat-bubble-left`, description: "Guide the brainstorming with your own direction"

The composer is hidden until a sub-card is selected.

**Auto-discover flow:** Selecting this card automatically sends a pre-filled user message: "Research current trends and discover content topics for my brand." This triggers `submitPrompt()` with the pre-filled content, and the AI begins researching immediately using web search and URL fetching.

**Conversation flow:** Selecting this card reveals the composer. The user types their own prompt to guide the brainstorming.

Implementation: Add a `topicsMode` property (nullable string, `discover` or `conversation`) to the chat component. When `conversation->type === 'topics'` and `topicsMode` is null and no messages exist, show the sub-cards instead of the composer. `selectTopicsMode('discover')` sets the mode and calls `submitPrompt()` with the pre-filled message. `selectTopicsMode('conversation')` just sets the mode and shows the composer.

### Propose-then-save flow

1. AI researches using web search (OpenRouter server tools) and `fetch_url`
2. AI proposes topics as a numbered list in natural language — title, angle, and evidence for each
3. User reviews and tells the AI which to save ("save 1 and 3", "save all", "save the first one", etc.)
4. AI calls `save_topics` tool with the approved topics
5. Compact "Saved" cards render inline in the chat (see UI section below)

### Tools available to `topics` type

1. **`save_topics`** — Saves one or more topics to the team's backlog
2. **`fetch_url`** — Same URL fetcher used by brand type (already exists in `BrandIntelligenceToolHandler::fetchUrlToolSchema()`)
3. **Web search** — OpenRouter server tool (already enabled by default)

---

## Tool Schema

### `save_topics`

```json
{
  "type": "function",
  "function": {
    "name": "save_topics",
    "description": "Save approved content topics to the team's topic backlog. Only call this when the user has approved specific topics.",
    "parameters": {
      "type": "object",
      "required": ["topics"],
      "properties": {
        "topics": {
          "type": "array",
          "items": {
            "type": "object",
            "required": ["title", "angle"],
            "properties": {
              "title": {
                "type": "string",
                "description": "The topic title -- specific and compelling"
              },
              "angle": {
                "type": "string",
                "description": "Why this topic fits the brand and what angle to take, 1-2 sentences"
              },
              "sources": {
                "type": "array",
                "items": { "type": "string" },
                "description": "Research evidence supporting this topic"
              }
            }
          }
        }
      }
    }
  }
}
```

### `TopicToolHandler`

New service class following the `BrandIntelligenceToolHandler` pattern:

- `execute(Team $team, int $conversationId, array $data): string` — creates `Topic` records from the `topics` array, returns JSON with saved count and titles
- `toolSchema(): array` — returns the schema above
- Static `fetchUrlToolSchema()` not needed — reuse from `BrandIntelligenceToolHandler`

The `execute` method creates each topic with:
- `team_id` from the team
- `conversation_id` from the current conversation
- `status` = `available`
- `score` = null (user sets this on the Topics page)

Returns: `json_encode(['status' => 'saved', 'count' => N, 'titles' => [...]])`

---

## Chat UI Changes

### Sub-cards in `create-chat.blade.php`

When `conversation->type === 'topics'` and no sub-mode is selected and no messages exist, show two sub-cards using the same card styling as the type selection:

```
[magnifying-glass icon]          [chat-bubble-left icon]
Auto-discover topics             Start a conversation
Research trends and discover     Guide the brainstorming with
topics automatically             your own direction
```

Wire actions: `wire:click="selectTopicsMode('discover')"` and `wire:click="selectTopicsMode('conversation')"`.

### Saved topic cards (inline in chat stream)

When the `save_topics` tool completes during streaming, render compact cards. Each card shows:

- Purple "Saved" indicator (checkmark + text)
- Topic title (bold)
- Angle text (muted, smaller)

Cards are stacked vertically with a small gap. Rendered via the existing `streamUI()` method — add a case for `save_topics` in the tool rendering logic.

For message history (non-streaming), saved topic cards are reconstructed from `message.metadata.tools` where `name === 'save_topics'`.

### Tool pill labels

Add to the `toolPill()` method:
- Active: "Saving topics..."
- Completed: "Saved N topics" (extract count from tool result JSON)

Add to the `streamUI()` active tool label:
- `save_topics` => "Saving topics..."

### Existing topics backlog context

The system prompt should include a list of existing topic titles (from the team's backlog with status `available`) so the AI avoids proposing duplicates. Add this to `ChatPromptBuilder::topicsPrompt()` as a `<existing-topics>` block, similar to how `<brand-profile>` is included.

---

## System Prompt Updates

### Updated topics prompt in `ChatPromptBuilder`

The topics system prompt needs to be rewritten to:

1. Instruct the AI to use web search to find current trends, news, and content gaps
2. Instruct it to propose topics as numbered lists with title, angle, and evidence
3. Instruct it to wait for user approval before calling `save_topics`
4. Include tool usage instructions (similar to brand prompt's tool section)
5. Include existing backlog titles to avoid duplicates
6. Keep the nudge for empty brand profiles
7. Keep the brand profile context in `<brand-profile>` tags

Key prompt instructions:
- Research using web search before proposing topics
- Propose 3-5 topics at a time as a numbered list
- Each topic: title, angle (why it fits the brand), evidence (what research supports it)
- Wait for user to say which to save before calling the tool
- Topics should be timely, specific, and connected to the brand's positioning
- Avoid duplicating existing backlog topics

### Tools configuration

Update `ChatPromptBuilder::tools()` to return tools for the `topics` type:

```php
'topics' => [
    TopicToolHandler::toolSchema(),
    BrandIntelligenceToolHandler::fetchUrlToolSchema(),
],
```

---

## Tool Executor Updates

### In `create-chat.blade.php`

The `$toolExecutor` closure in the `ask()` method needs a new case for `save_topics`:

```php
if ($name === 'save_topics') {
    return $topicHandler->execute($team, $conversation->id, $args);
}
```

This requires instantiating `TopicToolHandler` alongside `BrandIntelligenceToolHandler` and passing the conversation ID.

---

## Topics Page

### Route

Add to `routes/web.php` inside the team-scoped group:

```php
Route::livewire('topics', 'pages::teams.topics')->name('topics');
```

### Sidebar

Add a "Topics" item to the sidebar between "Brand Intelligence" and "AI Operations":

```
[light-bulb icon] Topics
```

### Page: `resources/views/pages/teams/⚡topics.blade.php`

Volt inline component showing the team's topic backlog.

**Component logic:**
- `Team $teamModel` (from route model binding)
- Computed `topics` property: `Topic::where('team_id', ...)->where('status', '!=', 'deleted')->latest()->get()`
- `updateScore(int $topicId, int $score)` — updates the topic's score
- `deleteTopic(int $topicId)` — sets status to `deleted`, closes confirmation modal
- Status filter (optional): tabs or dropdown for available/used/all

**Page layout:**

Header: "Topics" heading + count badge

Topic list: Each topic rendered as a `flux:card` with:
- **Title** (heading)
- **Angle** (subtext, muted)
- **Score slider** — 1-10 range input, updates via `wire:change` with `updateScore(topicId, score)`
- **Link to conversation** — small link/button if `conversation_id` is set, opens the originating chat
- **Delete button** — ghost trash icon, opens confirmation modal (same pattern as conversation delete in `create.blade.php`)

Empty state: Icon + "No topics yet" + subheading "Start a Brainstorm topics conversation to discover content ideas." + button linking to Create page.

---

## Files Summary

### Create

| File | Purpose |
|------|---------|
| `database/migrations/..._create_topics_table.php` | Topics table migration |
| `app/Models/Topic.php` | Topic model |
| `app/Services/TopicToolHandler.php` | Tool handler for `save_topics` |
| `resources/views/pages/teams/⚡topics.blade.php` | Topics page |

### Modify

| File | Change |
|------|--------|
| `app/Services/ChatPromptBuilder.php` | Add tools for topics type, rewrite topics prompt with tool instructions and existing backlog context |
| `resources/views/pages/teams/⚡create-chat.blade.php` | Add sub-card selection for topics, add `topicsMode` property, handle `save_topics` in tool executor and tool rendering, render saved topic cards |
| `app/Models/Team.php` | Add `topics()` hasMany relationship |
| `routes/web.php` | Add topics route |
| `resources/views/layouts/app/sidebar.blade.php` | Add Topics nav item |
